# admidio-addons

Add-ons for the membership system of the Piratenpartei Österreichs. The
membership system itself is a vanilla [Admidio](https://www.admidio.org/)
installation; everything specific to the party lives here instead of in a fork.
The add-ons run on the same server and use the Admidio database.

## Contents

| Directory | What | Status |
|---|---|---|
| [`mail-alias-sync/`](mail-alias-sync/) | Export of the mail aliases defined by roles | imported as is, not yet ported; not fetched since 2018 at the latest |
| [`pgacc/`](pgacc/) | Interfaces with the finance application [pgacc](https://github.com/PPOE/pgacc) | imported as is, not yet ported; **in use** |

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

## Rules

- No secrets in this repository: configuration and keys come from files on the
  server that are not committed.
- Admidio itself stays unmodified; add-ons must not patch its code.

## License

GNU General Public License version 2 only (GPL-2.0-only), like Admidio. See [LICENSE](LICENSE).
