# admidio-addons

Add-ons for the membership system of the Piratenpartei Österreichs. The
membership system itself is a vanilla [Admidio](https://www.admidio.org/)
installation; everything specific to the party lives here instead of in a fork.
The add-ons run on the same server and use the Admidio database.

## Contents

| Directory | What | Status |
|---|---|---|
| [`mail-alias-sync/`](mail-alias-sync/) | Export of the mail aliases defined by roles | imported as is, not yet ported; not fetched since 2018 at the latest |
| [`pgacc/`](pgacc/) | Interfaces with the finance application [pgacc](https://github.com/PPOE/pgacc) | old files imported as is, **in use**; the new member list (below) is not deployed yet |
| [`internal/`](internal/) | Document root of the internal listener: `members.php` only | new, not deployed yet |
| [`tests/`](tests/) | Tests of the member list (Docker) | |

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

The interfaces between the membership system and the finance application pgacc,
imported **unchanged** from `adm_api/` of PPOE/admidio-old (identical to the
copies deployed on the current membership system):

- `adm_names.php`: **member list for pgacc.** Prints the active members
  (role 2) with ID, last and first name (profile fields 1 and 2), the regional
  organisation role (37–45) and whether the membership fee is paid (field 26,
  "paid until", in the future), encrypted with Rijndael-256-CBC (`mcrypt`,
  key `md5($key4)`, IV `md5(md5($key4))`). pgacc's `getusers.php` fetches and
  decrypts it with the same key (pgacc's `$datakey`). In use.
- `accsync.php`: **payment status from pgacc.** Fetches pgacc's `mbapi.php`,
  decrypts it with the same key and writes "paid until" (field 26) and the
  amount (field 27) into the members' profiles. Run every 10 minutes by
  `lgfmail.sh`; it currently fails because the membership system cannot reach
  pgacc.
- `lgfmail.sh`: the cron wrapper (root, `*/10 * * * *`) that runs
  `registration.php`, `accsync.php` and `lgfmail.php` (the other two are not
  imported).

Like the alias export, they use the removed `mysql_*` and `mcrypt_*`
functions, read their keys and DB access from `config.php`, and have no
authentication beyond the shared key. `accsync.php` builds its SQL from the
decrypted (unauthenticated) data. Porting them, together with the matching
change in pgacc, is needed before the membership system moves to Admidio 5.

### Member list for pgacc (sync v2)

Replaces `adm_names.php`. pgacc fetches `members.php` from an internal
listener of its own (not through the public web server), over TLS:

- `internal/members.php` is the only file of that listener's document root;
  the code is in `pgacc/members-api.php`.
- It answers **403 with an empty body** unless the socket's address
  (`REMOTE_ADDR`; no forwarded headers) is in the configured peer list **and**
  the request carries `Authorization: Bearer <token>` with the configured
  token. A missing or invalid configuration, an empty token or an empty peer
  list refuse everything. Any error after that: **503 with an empty body**
  (the log gets only the exception class and code).
- Otherwise: `200`, `application/json`, ordered by id:
  `[{"id": 123, "uuid": "…", "name": "First Last", "lo": 38, "paid": 1}, …]`
  (`lo` may be `null`; `paid` is 0 or 1).
- The data comes from one prepared `SELECT` on the view `pgacc_members`
  ([`pgacc/pgacc_members.sql`](pgacc/pgacc_members.sql); its comment lists the
  rules), read by a database user with `SELECT` on that view only.

Deployment (not in this repository):

1. Create the reader `'pgacc_members'@'localhost'` with a random password,
   then apply `pgacc/pgacc_members.sql` as an administrator in the Admidio
   database (table prefix `adm`); apply it again after Admidio updates.
2. A configuration file outside every document root, readable by PHP-FPM
   only, returning:

   ```php
   <?php return [
       'token' => '<64 hex characters>',  // openssl rand -hex 32, the same as in pgacc
       'peers' => ['<pgacc internal address>'],
       'dsn' => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=<admidio db>;charset=utf8mb4',
       'user' => 'pgacc_members',
       'password' => '<reader password>',
   ];
   ```

3. An nginx `server` on the internal address only, serving only
   `/members.php`, with the path of that file as `PGACC_MEMBERS_CONFIG`:

   ```nginx
   server {
       listen <internal address>:8443 ssl;
       ssl_certificate     /etc/<…>/members-tls.crt;   # self-signed, SAN = the address
       ssl_certificate_key /etc/<…>/members-tls.key;
       root /srv/admidio-addons/internal;
       access_log off;
       location = /members.php {
           include fastcgi_params;   # REMOTE_ADDR = the socket's address; no real-IP module here
           fastcgi_param SCRIPT_FILENAME $document_root/members.php;
           fastcgi_param PGACC_MEMBERS_CONFIG /etc/<…>/members-config.php;
           fastcgi_pass unix:/run/php/<php-fpm socket>;
       }
       location / { return 404; }
   }
   ```

   plus the firewall rules that admit only pgacc's address on that port.

Tests: `tests/run.sh` (Docker; MariaDB 12.3 with the Admidio 5.0.17 tables
the view reads, and PHP 8.5; synthetic users only). GitHub Actions runs it on
every push.

## Rules

- No secrets in this repository: configuration and keys come from files on the
  server that are not committed.
- Admidio itself stays unmodified; add-ons must not patch its code.

## License

GNU General Public License version 2 only (GPL-2.0-only), like Admidio. See [LICENSE](LICENSE).
