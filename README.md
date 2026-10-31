# admidio-addons

Add-ons for the membership system of the Piratenpartei Österreichs. The
membership system itself is a vanilla [Admidio](https://www.admidio.org/)
installation; everything specific to the party lives here instead of in a fork.
The add-ons run on the same server and use the Admidio database.

## Contents

| Directory | What | Status |
|---|---|---|
| [`mail-alias-sync/`](mail-alias-sync/) | Export of the mail aliases defined by roles | imported as is, not yet ported; not fetched since 2018 at the latest |

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

## Rules

- No secrets in this repository: configuration and keys come from files on the
  server that are not committed.
- Admidio itself stays unmodified; add-ons must not patch its code.

## License

GNU General Public License version 2 only (GPL-2.0-only), like Admidio. See [LICENSE](LICENSE).
