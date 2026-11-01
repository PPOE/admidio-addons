# admidio-addons

Add-ons for the membership system of the Piratenpartei Österreichs. The
membership system itself is a vanilla [Admidio](https://www.admidio.org/)
installation; everything specific to the party lives here instead of in a fork.
The add-ons run on the same server and use the Admidio database.

## Contents

| Directory | What | Status |
|---|---|---|
| [`pgacc/`](pgacc/) | Interfaces with the finance application [pgacc](https://github.com/PPOE/pgacc) (sync v2): member list and payment status | new, not deployed yet; they replace the old `adm_names.php`, `accsync.php` and `lgfmail.sh` (removed here) of the old membership system, which is shut down |
| [`liquid/`](liquid/) | Interface with [LiquidFeedback](https://www.liquidfeedback.com/) (Liquid): member list with eligibility, email and regions | new, not deployed yet; replaces `adm1.php` of the old membership system |
| [`internal/`](internal/) | Document root of the internal listener: `members.php` and `liquid-members.php` only | new, not deployed yet |
| [`lib/`](lib/) | Code shared by the internal listener's endpoints: token and peer check | new, not deployed yet |
| [`tests/`](tests/) | Tests of the add-ons (Docker) | |

### Removed: mail alias export

`mail-alias-sync/adm_mail.php`, imported unchanged from `adm_api/` of
[PPOE/admidio-old](https://github.com/PPOE/admidio-old), was removed: it was
not fetched since 2018 at the latest, does not run on current PHP or Admidio 5
(`mysql_*`, `mcrypt_*`, Admidio 2.3's `config.php`), and the mail server now
keeps its aliases in its own database, so an alias sync would be written anew
against the mail server's API. The file stays in the Git history of both
repositories.

### pgacc

The interfaces between the membership system and the finance application
pgacc (sync v2). They replace the old files imported from `adm_api/` of
[PPOE/admidio-old](https://github.com/PPOE/admidio-old), which are removed
from this repository:

- `adm_names.php` (removed): the old member list, encrypted with a key
  shared with pgacc. Replaced by `internal/members.php` with the
  code in `pgacc/members-api.php` (Member list for pgacc, below).
- the old `accsync.php` and its cron wrapper `lgfmail.sh` (removed): fetched
  pgacc's `mbapi.php` encrypted the same way, every 10 minutes (root); the
  wrapper also ran `registration.php` and `lgfmail.php`, which are **not
  ported** and not part of this repository. Replaced by the new
  `pgacc/accsync.php` (Payment status from pgacc, below).

Neither new flow uses a shared encryption key: both use HTTPS with a pinned
certificate, a bearer token and a peer address check.

### Member list for pgacc (sync v2)

Replaces `adm_names.php` (removed). pgacc fetches the active members from
`members.php` on an internal listener of this side (not through the public
web server). `internal/members.php` is a file of that listener's document
root (the other is `liquid-members.php`, Member list for Liquid); the code is
in `pgacc/members-api.php`.

#### Transport and authentication

- Caller: pgacc. Callee: this side, `https://<internal address>:8443/members.php`.
- TLS with this side's self-signed certificate (see [Certificates](#certificates-both-flows)); pgacc
  verifies it against that certificate as its only trust anchor.
- The request must come from an address in the secrets file's peer list
  (the socket's `REMOTE_ADDR`; no forwarded headers) **and** carry
  `Authorization: Bearer <token>` with the secrets file's token (compared
  with `hash_equals`, never logged). Otherwise: **403 with an empty body**.
  A missing or invalid secrets file, an empty token or an empty peer list
  refuse everything.
- Any error after that: **503 with an empty body** (the log gets only the
  exception class and code).

#### Data

`200`, `application/json`, ordered by id:
`[{"id": 123, "uuid": "…", "name": "First Last", "lo": 38, "paid": 1}, …]`
(`lo` may be `null`; `paid` is 0 or 1; "paid until" today or later counts
as paid, so "paid until 31 December" is still paid on 31 December). The data
comes from one prepared `SELECT` on the view `pgacc_members`
([`pgacc/pgacc_members.sql`](pgacc/pgacc_members.sql); its comment lists the
rules), read by the database user `'pgacc_members'@'localhost'` with
`SELECT` on that view only.

#### Secrets file

`/etc/admidio-addons/members-secrets.php`, outside every document root,
readable by PHP-FPM only; the listener passes its path as the FastCGI
parameter `PGACC_MEMBERS_SECRETS`:

```php
<?php return [
    'token' => '<64 hex characters>',  // openssl rand -hex 32, the same as in pgacc
    'peers' => ['<pgacc internal address>'],
    'dsn' => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=<admidio db>;charset=utf8mb4',
    'user' => 'pgacc_members',
    'password' => '<reader password>',
];
```

#### Listener

An nginx `server` on the internal address only, serving only
`/members.php` (and `/liquid-members.php`, see Member list for Liquid):

```nginx
server {
    listen <internal address>:8443 ssl;
    ssl_certificate     /etc/admidio-addons/sync-tls.crt;   # self-signed, SAN = the address
    ssl_certificate_key /etc/admidio-addons/sync-tls.key;
    root /srv/admidio-addons/internal;
    access_log off;
    location = /members.php {
        include fastcgi_params;   # REMOTE_ADDR = the socket's address; no real-IP module here
        fastcgi_param SCRIPT_FILENAME $document_root/members.php;
        fastcgi_param PGACC_MEMBERS_SECRETS /etc/admidio-addons/members-secrets.php;
        fastcgi_pass unix:/run/php/<php-fpm socket>;
    }
    location / { return 404; }
}
```

#### Certificates

See [Certificates](#certificates-both-flows). For this flow this side is the callee:
this side's `/etc/admidio-addons/sync-tls.{crt,key}`; pgacc keeps the
certificate as `/etc/pgacc/admidio-addons-tls.crt`.

#### Deployment needs

Not in this repository:

1. Create the reader `'pgacc_members'@'localhost'` with a random password,
   then apply `pgacc/pgacc_members.sql` as an administrator in the Admidio
   database (table prefix `adm`); apply it again after Admidio updates.
2. The secrets file, the certificate and the listener above.
3. The firewall rules that admit only pgacc's address on port 8443.

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
  (`cafile`; see [Certificates](#certificates-both-flows)), no redirects, a fixed timeout of 20 s for
  connection, headers and body together, at most 1 MiB, HTTP 200 required,
  and a `Content-Length` header, if present, must be digits only and equal
  the body length (a body cut short by an early close is refused).
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
   dates as `YYYY-MM-DD` and not later than 31 December of next year (pgacc
   sends `<year>-12-31`; since field 26 only moves forward, a date far in the
   future would stay). `[]` is valid (nothing to do). Anything invalid:
   nothing is written.
3. **Write** in one transaction (READ COMMITTED), for each id that is an
   eligible member (`usr_valid = 1` and an active role-2 membership,
   `mem_begin <= today <= mem_end`; other ids are skipped and counted):
   - field 26 unless the stored value is an ISO date (`YYYY-MM-DD`, the
     rule of the `pgacc_members` view) later than the new one:
     `INSERT … ON DUPLICATE KEY UPDATE usd_value = IF(usd_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND usd_value > VALUES(usd_value), usd_value, VALUES(usd_value))`
     (a later date entered by hand stays; any other value, e.g. `31.12.2027`, is replaced);
   - field 27 to the exact euros from the cents (`2505` → `25.05`; no
     floats), also when it goes down.

   Members not in the document are left unchanged. Any SQL error: rollback.
   The database user `'pgacc_accsync'@'localhost'` has only the grants of
   [`pgacc/pgacc_accsync.sql`](pgacc/pgacc_accsync.sql): column grants for
   the checks, and the profile values only through the view
   `pgacc_accsync_user_data`, which holds fields 26 and 27 alone
   (`WITH CHECK OPTION`; no other profile field can be read or written).

A run prints only a summary with counts, e.g. `12 payments, 11 changes, 5
skipped`, so the journal gets no payment data. Exit status 0 on success; on
any failure 1, a message on stderr and nothing written. `--dry-run`, run by
hand, does everything, also prints one line per change or skipped id (ids
and values only, no names, never the token; e.g. `dry run: user 12 field 26:
2025-12-31 -> 2026-12-31`), all with a `dry run: ` prefix, and rolls back.

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

See [Certificates](#certificates-both-flows). For this flow pgacc is the callee:
pgacc's `/etc/pgacc/sync-tls.{crt,key}`; this side keeps the certificate as
`/etc/admidio-addons/pgacc-tls.crt` (the `cafile`).

#### Deployment needs

Not in this repository:

1. Create the writer `'pgacc_accsync'@'localhost'` with a random password,
   then apply `pgacc/pgacc_accsync.sql` as an administrator in the Admidio
   database (table prefix `adm`); apply it again after Admidio updates.
2. The secrets file, pgacc's certificate and the systemd units above.
3. The firewall rule that lets this side reach pgacc's internal listener on
   port 8443.

### Member list for Liquid

Replaces `adm1.php` of the old membership system (public, encrypted with a
shared key). Liquid fetches the active members from `liquid-members.php` on
the same internal listener as pgacc's `members.php`.
`internal/liquid-members.php` is the entry point; the code is in
`liquid/members-api.php`, the authentication in `lib/internal-api.php`
(shared with `members.php`).

#### Transport and authentication

- Caller: Liquid. Callee: this side, `https://<internal address>:8443/liquid-members.php`.
- TLS with this side's certificate, the same as for pgacc (see
  [Certificates](#certificates-both-flows)); Liquid verifies it against
  that certificate as its only trust anchor.
- As for pgacc's `members.php`, but with its own secrets file: the request
  must come from an address in that file's peer list (the socket's
  `REMOTE_ADDR`; no forwarded headers) **and** carry
  `Authorization: Bearer <token>` with that file's token (compared with
  `hash_equals`, never logged). Otherwise: **403 with an empty body**.
  Liquid's token and address are refused by `members.php`, and pgacc's by
  `liquid-members.php`. A missing or invalid secrets file, an empty token
  or an empty peer list refuse everything.
- Any error after that: **503 with an empty body** (the log gets only the
  exception class and code).

#### Data

`200`, `application/json`, ordered by id:
`[{"id": 123, "lo": 38, "eligible": true, "email": "…", "regions": ["AT130"]}, …]`

- The members and `lo` are those of the view `pgacc_members` (see Member
  list for pgacc).
- `eligible`: as `adm1.php`: accredited (profile field 35) on or before
  today, and paid (field 26, "paid until") or accredited at most 30 days
  ago (the trial month). "Paid until 31 December" is eligible through 30
  January. Both fields count only as real calendar dates (`YYYY-MM-DD`).
- `email`: only when eligible, else `null`. Profile field 12, or field 33
  when field 12 is `mitglied@piratenpartei.at`. An address that is not
  valid (PHP's `FILTER_VALIDATE_EMAIL`, at most 254 characters) is sent as
  `null`; the member is still listed. A deliberate difference from
  `adm1.php`: it used field 33 only if that had more than 4 characters and
  otherwise kept `mitglied@piratenpartei.at`; here a missing or empty field
  33 gives `null`, so no account gets that address. The comparison with
  `mitglied@piratenpartei.at` ignores case and trailing spaces (the
  collation of the Admidio tables).
- `regions`: the distinct region codes (NUTS 3, sorted) of the postcodes in
  fields 4 and 39 (trimmed, exact match), from the table
  `liquid_region_plz` ([`liquid/liquid_region_plz.sql`](liquid/liquid_region_plz.sql):
  the postcodes of AT130 and AT221, the regions with units in Liquid);
  `[]` if none.

The data comes from one prepared `SELECT` on the view `liquid_members`
([`liquid/liquid_members.sql`](liquid/liquid_members.sql); its comment lists
the rules), read by the database user `'liquid_members'@'localhost'` with
`SELECT` on that view only.

#### Secrets file

`/etc/admidio-addons/liquid-members-secrets.php`, outside every document
root, readable by PHP-FPM only; the listener passes its path as the FastCGI
parameter `LIQUID_MEMBERS_SECRETS`:

```php
<?php return [
    'token' => '<64 hex characters>',  // openssl rand -hex 32, the same as in Liquid
    'peers' => ['<Liquid internal address>'],
    'dsn' => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=<admidio db>;charset=utf8mb4',
    'user' => 'liquid_members',
    'password' => '<reader password>',
];
```

#### Listener

pgacc's listener (see Member list for pgacc), with a second location:

```nginx
    location = /liquid-members.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/liquid-members.php;
        fastcgi_param LIQUID_MEMBERS_SECRETS /etc/admidio-addons/liquid-members-secrets.php;
        fastcgi_pass unix:/run/php/<php-fpm socket>;
    }
```

#### Certificates

See [Certificates](#certificates-both-flows). For this flow this side is the callee:
this side's `/etc/admidio-addons/sync-tls.{crt,key}`; Liquid keeps the
certificate as `/etc/liquidfeedback/admidio-addons-tls.crt`.

#### Deployment needs

Not in this repository:

1. Create the reader `'liquid_members'@'localhost'` with a random password,
   then apply, as an administrator in the Admidio database (table prefix
   `adm`), in this order: `pgacc/pgacc_members.sql`,
   `liquid/liquid_region_plz.sql`, `liquid/liquid_members.sql`; apply them
   again after Admidio updates.
2. The secrets file and the listener location above.
3. The firewall rules that admit Liquid's address on port 8443.

### Certificates (both flows)

Each side's own certificate is
`/etc/<app>/sync-tls.{crt,key}`; the peer's certificate is
`/etc/<app>/<peer>-tls.crt`. Each certificate is self-signed by the callee
(SAN = the address in the caller's URL) and handed to the caller through the
deployment inventory.

### Tests

`tests/run.sh` (Docker; MariaDB 12.3 with the Admidio 5.0.17 tables the
add-ons use, their database users and every `pgacc/*.sql` and `liquid/*.sql`
in the deployment's order, and PHP 8.5; runs each `tests/*-test.php`;
`accsync.php` against a local HTTPS test server with self-signed
certificates; synthetic users only). GitHub Actions runs it on
every push.

## Rules

- No secrets in this repository: configuration and keys come from files on the
  server that are not committed.
- Admidio itself stays unmodified; add-ons must not patch its code.

## License

GNU General Public License version 2 only (GPL-2.0-only), like Admidio. See [LICENSE](LICENSE).
