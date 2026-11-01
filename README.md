# admidio-addons

Add-ons for the membership system of the Piratenpartei Österreichs. The
membership system itself is a vanilla [Admidio](https://www.admidio.org/)
installation; everything specific to the party lives here instead of in a fork.
The add-ons run on the same server and use the Admidio database.

## Contents

| Directory | What | Status |
|---|---|---|
| [`mail-alias-sync/`](mail-alias-sync/) | Export of the mail aliases defined by roles | imported as is, not yet ported; not fetched since 2018 at the latest |
| [`pgacc/`](pgacc/) | Interfaces with the finance application [pgacc](https://github.com/PPOE/pgacc) | `adm_names.php` imported as is, **in use**; the new `accsync.php` (below) is not deployed yet |
| [`tests/`](tests/) | Tests of the add-ons (Docker) | |

### mail-alias-sync

`adm_mail.php` builds mail alias lists from `{alias:...}` tags in Admidio role
descriptions and the e-mail addresses of the roles' current members, and prints
them encrypted (AES-128-CBC with `$mail_key`/`$mail_iv` from `config.php`) for
the mail server's alias sync.

It is imported **unchanged** from `adm_api/adm_mail.php` of
[PPOE/admidio-old](https://github.com/PPOE/admidio-old) (branch `austria`, last
changed in commit `2b9bd5b`, 2014), where it ran against Admidio 2.3. As it is,
it does not run on current PHP or Admidio 5: it uses the removed `mysql_*` and
`mcrypt_*` functions and reads `config.php` (DB access and keys; not part of
this repository). Porting it to PHP 8 and the Admidio 5 schema, with proper
authentication instead of a public URL, is the next step.

**Status: not in use.** The access logs of the current membership system show
the alias list being fetched once a day (04:19, `Wget/1.13.4`, i.e. a
Debian 7 client) until January 2018, the last month of the retained old logs.
In the retained logs from May to October 2026 it was not fetched at all
(checked 2026-10-31). No current server is known to fetch it; the aliases on
the mail server have presumably not been updated from Admidio since then.

### pgacc

The interfaces between the membership system and the finance application pgacc.

- `adm_names.php`: **member list for pgacc**, imported **unchanged** from
  `adm_api/` of [PPOE/admidio-old](https://github.com/PPOE/admidio-old)
  (identical to the copy deployed on the old membership system). Prints the
  active members (role 2) with ID, last and first name (profile fields 1 and
  2), the regional organisation role (37–45) and whether the membership fee is
  paid (field 26, "paid until", in the future), encrypted with
  Rijndael-256-CBC (`mcrypt`, key `md5($key4)`, IV `md5(md5($key4))`).
  pgacc's `getusers.php` fetches and decrypts it with the same key (pgacc's
  `$datakey`). In use; it uses the removed `mysql_*` and `mcrypt_*` functions
  and is replaced separately.
- `accsync.php`: **payment status from pgacc** (sync v2, below). It replaces
  the old `accsync.php` and its cron wrapper `lgfmail.sh` of PPOE/admidio-old
  (root, every 10 minutes), which also ran `registration.php` and
  `lgfmail.php`; those two are **not ported** and not part of this
  repository.

### Payment status from pgacc (sync v2)

Replaces the old `accsync.php` and `lgfmail.sh`. `pgacc/accsync.php` is a
command line script, run by a systemd timer every 10 minutes, that fetches
the payments from pgacc's internal listener and writes them into the members'
profiles:

```
php pgacc/accsync.php [--dry-run] [--secrets=<file>]
```

#### Transport and authentication

- Caller: this side (`accsync.php`). Callee: pgacc,
  `https://<pgacc internal address>:8443/mbapi.php`.
- PHP streams, `https://` only, the certificate verified (`verify_peer`,
  `verify_peer_name`) against pgacc's certificate as the only trust anchor
  (`cafile`; see Certificates), no redirects, a fixed timeout of 20 s, at
  most 1 MiB, HTTP 200 required.
- `Authorization: Bearer <token>` with the secrets file's token (never
  logged); pgacc also checks the caller's address against its peer list and
  answers 403 with an empty body otherwise.

#### Data

The answer is JSON, `[{"id": 123, "amount_cents": 2000, "paid_until": "2026-12-31"}, …]`.

1. **Deployment check** (before the fetch): profile fields 1 (`TEXT`,
   `LAST_NAME`), 2 (`TEXT`, `FIRST_NAME`), 26 (`DATE`, "paid until") and 27
   (`DECIMAL`, amount) and roles 2 and 37–45 exist, and `adm_user_data` has
   its unique key on `(usd_usr_id, usd_usf_id)`. Otherwise it stops.
2. **Validate** the whole document: a list of objects with exactly these
   keys, ids positive integers and unique, amounts integers ≥ 0, dates real
   dates as `YYYY-MM-DD`. `[]` is valid (nothing to do). Anything invalid:
   nothing is written.
3. **Write** in one transaction (READ COMMITTED), for each id that is an
   eligible member (`usr_valid = 1` and an active role-2 membership,
   `mem_begin <= today <= mem_end`; other ids are skipped and logged):
   - field 26 only if the new date is later:
     `INSERT … ON DUPLICATE KEY UPDATE usd_value = GREATEST(COALESCE(usd_value, ''), VALUES(usd_value))`
     (a later date entered by hand stays);
   - field 27 to the exact euros from the cents (`2505` → `25.05`; no
     floats), also when it goes down.

   Members not in the document are left unchanged. Any SQL error: rollback.
   The database user `'pgacc_accsync'@'localhost'` has only the column
   grants of [`pgacc/pgacc_accsync.sql`](pgacc/pgacc_accsync.sql).

It prints one line per change or skipped id (ids and values only, no names,
never the token) and a summary, e.g. `user 12 field 26: 2025-12-31 ->
2026-12-31`. Exit status 0 on success; on any failure 1, a message on stderr
and nothing written. `--dry-run` does everything, prints the changes with a
`dry run: ` prefix and rolls back.

#### Secrets file

`/etc/admidio-addons/accsync-secrets.php`, outside every document root,
readable only by the user the timer runs as, passed with `--secrets` or the
environment variable `ACCSYNC_SECRETS`:

```php
<?php return [
    'url' => 'https://<pgacc internal address>:8443/mbapi.php',
    'token' => '<64 hex characters>',  // openssl rand -hex 32, the same as in pgacc
    'cafile' => '/etc/admidio-addons/pgacc-tls.crt',  // pgacc's certificate
    'dsn' => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=<admidio db>;charset=utf8mb4',
    'user' => 'pgacc_accsync',
    'password' => '<writer password>',
];
```

#### Listener

None on this side for this flow: `accsync.php` calls pgacc's internal
listener. It runs from systemd units, e.g.:

```ini
# /etc/systemd/system/pgacc-accsync.service
[Unit]
Description=Payment status from pgacc into Admidio
[Service]
Type=oneshot
User=<accsync user>
ExecStart=/usr/bin/php /srv/admidio-addons/pgacc/accsync.php --secrets=/etc/admidio-addons/accsync-secrets.php

# /etc/systemd/system/pgacc-accsync.timer
[Unit]
Description=Payment status from pgacc every 10 minutes
[Timer]
OnCalendar=*:0/10
[Install]
WantedBy=timers.target
```

The log is in the journal (`journalctl -u pgacc-accsync`).

#### Certificates

Each side's own certificate is `/etc/<app>/sync-tls.{crt,key}`; the peer's
certificate is `/etc/<app>/<peer>-tls.crt`. Each certificate is self-signed
by the callee (SAN = the address in the caller's URL) and handed to the
caller through the deployment inventory. For this flow: pgacc's
`/etc/pgacc/sync-tls.{crt,key}`; this side keeps the certificate as
`/etc/admidio-addons/pgacc-tls.crt` (the `cafile`).

#### Deployment needs

Not in this repository:

1. Create the writer `'pgacc_accsync'@'localhost'` with a random password,
   then apply `pgacc/pgacc_accsync.sql` as an administrator in the Admidio
   database (table prefix `adm`); apply it again after Admidio updates.
2. The secrets file, pgacc's certificate and the systemd units above.
3. The firewall rule that lets this side reach pgacc's internal listener on
   port 8443.

Tests: `tests/run.sh` (Docker; MariaDB 12.3 with the Admidio 5.0.17 tables
the add-ons use, their database users and every `pgacc/*.sql`, and PHP 8.5;
runs each `tests/*-test.php`; `accsync.php` against a local HTTPS test server
with self-signed certificates; synthetic users only). GitHub Actions runs it
on every push.

## Rules

- No secrets in this repository: configuration and keys come from files on the
  server that are not committed.
- Admidio itself stays unmodified; add-ons must not patch its code.

## License

GNU General Public License version 2 only (GPL-2.0-only), like Admidio. See [LICENSE](LICENSE).
