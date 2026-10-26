# Operations and environment

First-time setup, day-to-day work and deploying are walked through in [DEV.md](../DEV.md); this
page is the reference behind it.

## Running things

| Task | In the browser | From the command line (from the repo root) |
| --- | --- | --- |
| First setup | open http://localhost:4600 (it leads to `setup.php`) | `cd backend && composer install`; `cd frontend && bun ci`; start the two servers below |
| API (and setup.php, and the built app) | – | `cd backend && composer start` (`php -S localhost:8400 router.php`) |
| Angular dev server | – | `cd frontend && npm start` (`bun ci`, then http://localhost:4600) |
| Database migrations | the admin panel's Migrations page, "Apply pending" (ADMIN) | `cd backend && php migration.php [--status \| --dry-run]` |
| Builds | the admin panel's Builds page (EDITOR): "Export", "Import a file" (a dry run's preview, then "Import") | – (the file is made on the owner's computer: "Importing builds", below) |
| Catalog | the Builds page, "Refresh catalog" (EDITOR) | `cd backend && php sync-catalog.php [genshin] [--list=characters\|weapons\|sets] [--force]` |
| Import from the command line | – | `cd backend && php import-account.php genshin 700123456 --user=ID` or `… genshin path/to/good.json --user=ID --account=3` (`--user` required) |
| The site's words | the Translations page (EDITOR) and the Languages page (adding, renaming, switching, deleting: ADMIN) | `cd backend && php translations.php --status` (the frontend's keys against the database's), `--export=sk [file]`, `--import=sk file` |
| Backups | the admin panel's Backups page (ADMIN) | `cd backend && php backup.php --list \| --create ["why"] \| --inspect=<id> \| --extract=<id> <folder> \| --restore=<id> --confirm=<last 6>` |
| What failed, what was done, what visitors wrote | the admin panel's Errors, Audit and Feedback pages (ADMIN) | `storage/logs/errors-*.jsonl` (one JSON line each); the tables `anotoki_audit_log` and `anotoki_feedback` |
| Tests | – | `cd backend && php tests/run.php` and `cd frontend && npx ng test --watch=false` |
| The browser check | – | `node .claude/skills/verify-ui/scripts/run.mjs [--no-build] [--keep] [--measure]` (the `verify-ui` skill: a throwaway database, the stand-in IAM on 8597, the site on 8590) |
| The repository names no build site | – | `php backend/tools/check-names.php [--staged \| --message=<file>]` (the words of the untracked `backend/config/neutral.local.php`: "Configuration", "The names file") |
| Frontend build | – | `cd frontend && npx ng build` (then the API server alone serves everything on :8400) |
| Build for the host | – | `cd frontend && bun run anotoki-deploy build` (into `dist/` at the repo root: `frontend/` and `backend/`) |
| Look at the data | – | `psql` against the database of `config/database.local.php`, or a scratch PHP file using `App\Cli::app()->db()` |

`backend/tools/php.sh` runs PHP 8.4+ from `$BUILD_ANALYZER_PHP`, else the `php` on PATH, and refuses older
versions - for machines whose `php` on PATH is too old. AI tool shells reset their working directory, so start
commands with `cd <repo root> &&`.

There is **no queue worker and no scheduler**: nothing runs unless someone starts it, and the site never fetches
a build. Ordinary traffic carries the rest, after its answer (`api/middleware/upkeep.php`; `config/app.php`
`upkeep` switches each off): the scrub of the accounts the IAM deleted (at most hourly), the day's backup, the
day's tidying up (`App\Cache\Prune`: what has expired in the `cache` table, and the files the showcase import keeps
in `storage/http-cache/` once they are three days old) and a stale catalog list refreshed ("The catalog", below).

## Configuration

Everything that differs between installations is in files in `backend/config/`, written by `setup.php` (PHP
files returning arrays; gitignored, never committed, never uploaded from here). `database.local.php` and
`auth.local.php` are required: while one is missing or incomplete the API answers every request
`503 {"code": "not_set_up", "installed", "message"}` and the app shows the anotoki family's "This site is not
set up yet" page (the site gate, [architecture.md](architecture.md)) - with the setup page only while the site
was never installed: once `setup.php` has written `storage/installed.lock` on a host, nobody is sent to it again
("The site is not available right now" instead). `fetch.local.php` is optional: each value it leaves out (or
all of them, without it) is `Config::FETCH_DEFAULTS`'.

| File | Keys |
| --- | --- |
| `database.local.php` | `host`, `port`, `database`, `username`, `password`, `persistent` |
| `auth.local.php` | `issuer` (the IAM's address), `client_id` (`build-analyzer`); optional `jwks_file` (a key set on disk instead of the IAM's, for offline work) and `leeway` (seconds, 60). A `mode` or `local_user_id` an older setup wrote is ignored: sign-in is the IAM's, always |
| `fetch.local.php` (optional) | the site's outgoing requests - the catalog's lists, the showcase service, the icons: `user_agent` (how they identify the app; `build-analyzer/1.0`, and setup writes the site's address into it), `delay_ms` (the wait between two requests to one host, 1200 - the showcase client sets its own; kept in the `cache` table, so it holds across requests and processes), `timeout` (seconds, 30; in a web request - an import, a refresh from the Builds page - 10 at most, and one retry at most), `browser_user_agent` (sent only where a caller asks for a browser's name: no request of the site's own does). An older file's `cache_hours`, `step_seconds` and `stale_hours` are no longer read |

**Every one of them is read cleanly or not at all** (`App\Config::readLocalFile()` - the anotoki family's rule of
2026-10-13, and its first five bytes since 2026-10-18; [decisions.md](decisions.md)), the ones written by hand -
`client_address.local.php`, `cloudflare.local.php`, `neutral.local.php`, `site.local.php` (below) - and the committed
`app.php` too: a file that does not start with `<?php` (saved with a byte-order mark, a blank line or a space before it, or
without that line) is
passed over without being compiled - required, it would print what comes before it (all of it, the database's
password included, where the line is missing) ahead of every answer, and with `declare(strict_types=1)` after such
a start PHP could not even compile it: a fatal error nothing catches, every request a 500. Whatever a file that
does start with `<?php` prints (text after a closing tag, an echo) is caught and never sent, and that file is
passed over too; so is one PHP cannot read or run. A file passed over is said in PHP's error log at most once a day
(a stamp in `storage/cache/config-said-*.stamp`), by its fault and byte count alone - never its text: a parse
error's message can quote it. What passing over means, never looser than no file:

| File | Passed over |
| --- | --- |
| `database.local.php`, `auth.local.php` | the site is not set up until it is mended - the problem names the file and its fault ("does not start with <?php (...)", "prints 12 bytes of its own (...)", "could not be read (ParseError on line 3)") |
| `fetch.local.php` | `Config::FETCH_DEFAULTS` stand in for it |
| `client_address.local.php` | no proxy is trusted - every request counts its own connection - never Cloudflare's ranges in its place: it may be the very file that said `[]` for a host not behind Cloudflare |
| `cloudflare.local.php` | a secret no request carries: it fails closed - as does one read cleanly that names no usable secret; deleting the file is the way to switch the secret off (see "Which address", below) |
| `neutral.local.php` | it adds no names: what `App\Support\Neutral` names itself, and every web address, are still kept out of what the API sends; `tools/check-names.php` has nothing to look for, and says so |
| `site.local.php` | the address of where the code runs holds - a release's `https://builder.anotoki.eu`, a checkout's `http://localhost:4600` - as for one read cleanly that names no usable address (an http(s) origin, nothing after its host and port): robots.txt keeps its Sitemap line |

**The site's own address** (`config/site.local.php`, optional, by hand - never written or read by `setup.php`,
anotoki-lib 0.11.0): `<?php return ['address' => 'https://builds.example'];` - for robots.txt's Sitemap line and
every address in sitemap.xml (`App\Config::siteAddress()`), only where it is not the one the code's place gives: a
release (`release.json` beside `backend/`) is `Config::RELEASE_ADDRESS`, the address the deploy tool puts it at
(`composer.json`'s `extra["anotoki-deploy"]["url"]`, which a test holds to it); a checkout is
`Config::DEVELOPMENT_ADDRESS`, the Angular dev server. Never an address read off a request: a `Host` header is
anybody's to write.

`setup.php` reads its files the same way: on this computer it names a broken one by its fault (never a value), and
asks for every field again - the password too - since it shows none of that file's values and keeps none of its
password; on a host it shows nothing of any file, neither a value nor a fault (`Installer::passedOver($local)`,
`tests/settings_files.test.php`).

Run `setup.php` again to change the database or the IAM (on this computer it can be run any number of times
and shows the saved values, never the password; on a host it shows none), or edit the files. Setup writes
`fetch.local.php` with the defaults only when there is none, and leaves one that is there as it is: edit it by
hand. `config/app.php` is committed and holds what is the same
everywhere: the catalog's provider (genshin.anotoki.eu's lists) and its intervals ("The catalog", below), the
showcase service and its per-person rate limit, the file imports' rate limit, a person's accounts file (`me`: 5
imports a minute, files up to 8 MB), the visitors' limits and clean-up
(`visitors`), which address a request counts as (`client_address`), what traffic carries after an answer
(`upkeep`), and the name aliases.

`setup.php` on this computer (a loopback client asking for a `localhost` address) needs no token - a nonce in a
`SameSite=Strict` cookie protects the form - and never deletes itself. On a real host it writes a token into
`config/setup-token.local.php` that has to be read back over FTP, refuses to run once `storage/installed.lock`
exists, and deletes the token and itself when it is done. It asks for the database (defaults `127.0.0.1`,
`5432`, `anotoki_builder`) and the IAM (address, client id) - and on a host the token - and nothing else. Its
"Test connection" buttons save nothing: the IAM's asks for `{issuer}/.well-known/openid-configuration`; the
database's answers in the words of every anotoki setup page:

- `Connected to the database "<name>": it is empty - Save makes the tables.` / `… N update(s) to apply - Save
  applies them.` / `… it is up to date.`
- `Connected to the server. The database "<name>" does not exist yet - Save creates it.`
- `Connected to the server, but there is no database "<name>" and this user may not create one. Make it in
  the hosting panel first.`
- `The server is there, but "<user>" cannot open the database "<name>": <reason>`
- `Cannot connect: <reason>`

**The names file** (`config/neutral.local.php`; the owner's decision of 2026-10-20, [decisions.md](decisions.md)).
The repository is public and names no site the builds come from - not in a tracked file, a commit message, a branch
or a PR. Their names are in this file, written by hand like `cloudflare.local.php` (gitignored, never written or
read by `setup.php`, never in a release - an upload leaves it where it is): `<?php return ['names' => [...],
'tree_words' => [...]];`.

- `names` add to the names `App\Support\Neutral` keeps out of everything the API sends to be shown - a builds
  file's texts too, on the way in and out (read once, through `Config::readOptionalFile()`: `Neutral::localNames()`).
  Without the file `Neutral` keeps out its own names and every web address, not the build sites': so **upload it
  once to the host's `config/`**, beside the other settings, and keep a copy off the server.
- `php backend/tools/check-names.php` (from the repo root) reads every file git tracks - and every file `git add -A`
  would add -, and their paths, for the `names` as whole words (in a path however it joins their words: `a-name/`,
  `aName.php`) and the `tree_words` (spellings, page parts, labels that would give a site away) anywhere, prints
  each place it found one (`path:line` and the word found there, never the file's list) and fails on any; `--staged`
  reads the index instead and `--message=<file>` a commit message (the owner's untracked `.git/hooks/pre-commit` and
  `commit-msg` run it so). Without the file it has nothing to look for, and says so.

## Sign-in

People sign in at the anotoki IAM - on a host and on this computer alike; there is no other way in - through
the anotoki family's auth module (anotoki-lib 0.5.0: `Anotoki\Lib\Auth` on the server, `@anotoki/lib/auth` in the
app). Who a request is comes only from its verified access token (the library's `Identify` with its
`AccessTokens`, on each route group: `api/middleware/auth.php`); a request that sends an `Authorization: Bearer`
header is that person or it is refused (401 `invalid_token`), on public routes too (another scheme - `Basic`, from
a host behind a password file - counts as no header).

**Signed in at the IAM is signed in here** (the IAM's sign-in hint, the owner's decision for the sites that let
visitors in): the IAM sets `anotoki_signed_in` - a random value per session, readable by script, for every host
under its parent domain - and a visitor's page load makes one silent sign-in with it before the first page is
drawn (`prompt=none`; whatever comes back, nothing is said; each hint tried once, at most one try a minute -
the marker `anotoki-auth:build-analyzer:hint` in localStorage). Signed out at the IAM is signed out here at the
next page load. It needs nothing here but the IAM's hint (the IAM's own release) and the site under the IAM's
parent domain; in development the IAM's hint is host-only `localhost`, so open the site as `localhost`, never
`127.0.0.1`. Search engines never get the cookie; `robots.txt` (the library's, answered by the site's PHP since
anotoki-lib 0.11.0: "The site settings", below) keeps them off `/auth`, and the callback and the failure screen say
`noindex`. A sign-in that came back without one is said on `/sign-in` (the library's screen,
in the site's frame: why, from the failure's code alone - Try again, Back).

**Whether sign-in is required is the IAM's switch**, not a setting here: the IAM's admin panel, Applications ->
Build Analyzer -> Settings -> "Sign-in required". The server reads it from the IAM (`GET
{issuer}/api/public/applications/build-analyzer`, through the library's `SignInRule`) and keeps the answer a minute in
`storage/cache/iam/`, so a switch reaches the site within a minute; while the IAM is out of reach it uses the
last answer, and with none ever read - or a 404, an application the IAM does not know or switched off - sign-in
is **required** (fail closed). `/api/meta` relays it to the page (`auth.login_required`), which then sends
everybody not signed in to the IAM before any page (and keeps a visitor key it holds, unsent). Required: the table's
first two rows shrink to `/api/meta`, the icons, a language's strings and `/api/site-status` (which reads no
credentials, so the pages always learn where the site stands) - the builds browser and every visitor key
answer 401 `sign_in_required`, and no visitor is made; a visitor's data waits (the clean-up still deletes it 30
days after the last visit), and signing in still moves it into the account.
**Deploy this version only after the IAM serves that address** (IAM migration 010 and its public route):
before that the IAM answers 404 and the site requires sign-in - visitors turned away. Check with `curl
https://iam.anotoki.eu/api/public/applications/build-analyzer` (200, `"login_required": false`).

| Who | May |
| --- | --- |
| nobody (no token) | `/api/meta`, the icons, a language's strings, `/api/site-status`; while sign-in is not required also the builds browser (`/api/builds`, `/api/builds/*`: naming nobody); an import, which makes them a visitor |
| a visitor (no token, this browser's `X-Visitor-Key`; only while sign-in is not required) | its own accounts and imports, as a person's - nothing else (no accounts file: sign in first) |
| a signed-in person (anybody the IAM lets sign in here; `MEMBER`, which every account holds, or no role) | their own accounts and imports, and their accounts as a file (`/api/me/export`, `/api/me/import`) |
| `EDITOR` | also: the Builds page - a game's builds file (export, a dry run, import), the imports made, the catalog's refresh -, the strings (the Translations page, a language's file), the languages' list |
| `ADMIN` | also: applying database updates, adding, renaming, switching and deleting a language, the People routes (list people, delete a person's data, check the IAM's deleted accounts now), and the admin tools - the error log, the audit log, feedback, backups (an editor sees none of them) |

- The IAM in development runs at `http://localhost:4300` (its API on :8100); hosted it is
  `https://iam.anotoki.eu`. The frontend reads the issuer and client id from `/api/meta`.
- Registered at the IAM (client `build-analyzer`, "Build Analyzer"): redirect `https://builder.anotoki.eu/auth/callback`,
  post-logout `https://builder.anotoki.eu/`, web origin `https://builder.anotoki.eu`; for development the IAM
  registers `http://localhost:4600` (`/auth/callback`, `/`, the origin) by itself.
- Roles are granted in the IAM's admin panel (the owner holds ADMIN). A removed role or a disabled account
  reaches this app within one access-token lifetime (10 minutes). Every account holds `MEMBER` here (IAM
  migration 010; an ordinary person - no check here needs or refuses it), and the IAM's page for the application
  says which roles may sign in. **Blocking somebody**: take their `MEMBER` away at the IAM - their next sign-in
  and refresh are refused, while the access token they hold verifies until it expires (minutes). While sign-in
  is not required they can still use the site as a visitor: blocking stops signing in, not visiting; to keep
  somebody out entirely, require sign-in.
- The API sends no CORS headers at all (the app is same-origin, and the dev server proxies), so other sites
  can neither read its answers nor send it a bearer token.
- **People** (ADMIN; the anotoki family's People page, `/admin/people`): everybody the IAM has signed in here -
  the newest seen first, a search by name or IAM id, 50 a page - with their game accounts, imports and last
  import; the visitors counted apart. **Delete their data** asks only its question, then deletes their accounts
  with everything imported into them and their import runs (`DELETE /api/admin/people/{id}`, in the audit log as
  `person.data_deleted`). The builds files they imported and the strings they saved stay, by nobody, and what
  they sent through the feedback form stays without the address and browser it was sent with; their anotoki
  account is not touched, and signing in again starts them afresh.
- **The IAM's deleted accounts are scrubbed here** (the library's people module): after an answer, at most once an
  hour (`config/app.php` `upkeep.people`), the site reads the accounts the IAM deleted (thirty days after their
  owners asked) and makes each one nobody's here - its row `deleted-{id}` with `anonymized_at` set, their
  feedback's address and their name in the audit log's details blanked; their game accounts and imports stay,
  nobody's now, and so do the builds files they imported (the Builds page names the row, `deleted-{id}`). The
  People page says when the IAM was last asked; Check now asks at once (`POST /api/admin/people/sync`). A scrubbed
  row stays nobody's: no request ever writes a name back into it, nor makes a new row for an id the IAM deleted.
- The verifier caches the IAM's keys in `storage/cache/iam/`, and the reader the IAM's sign-in answer
  (`anotoki-iam-application-<sha1>.json`). The folder must be the site's own - a kept "not required" is what
  opens the site to visitors; the switch never serves `storage/` (it sends every request into `frontend/`), and setup checks it. Deleting the file makes the
  next request ask the IAM again.
- **The account's theme, dates and language** (an IAM with its migration 015; the library's auth module): for a
  signed-in person the theme (on every page, the admin panel's too), the date order and clock and the
  language are the account's, which every anotoki site shares - set on the IAM's account page, on another
  site, or here: the theme toggle and the language menu save a signed-in person's choice to the account
  (`POST {issuer}/api/oidc/preferences`, from the page, with the person's token). A visitor's stay this
  device's. With an IAM before 015 nothing changes: the site keeps its own, as before. Nothing of it reaches
  this site's server.

## Visitors

Somebody who uses the site without signing in gets a visitor account with their first import that goes
through (how it works: [architecture.md](architecture.md), "Visitors"; why: [decisions.md](decisions.md)). What
they import is kept on the server under a row of `users` with a negative id; their browser holds the key.

- **Kept for 30 days after the last visit** (`visitors.keep_days`), then deleted by the site itself: the
  clean-up runs after the answer of a request that makes a visitor, and at most once an hour after an
  own-data request - never on a quiet site's public pages, so a visitor may outlive 30 days until the next
  such request (harmless: nothing opens it without the key). A failing clean-up is logged ("After the
  answer: ...") and tried again an hour later.
- **Limits** (`config/app.php` `visitors`; the defaults): 10 new visitors an hour per address, 60 from
  everywhere, 1000 at once (past any: 429 `too_many_visitors`, "... Sign in to continue, or try again later.");
  5 game accounts and 5 MB files per visitor (422 `visitor_limit`); a visitor's imports counted per address
  (showcase 10, files 20 a minute), and all visitors' showcase imports 20 a minute together. Members keep
  10 showcase and 20 file imports a minute each, and files up to 20 MB.
- **Which address**: `REMOTE_ADDR`; an IPv6 address counts by its /64, an IPv4 address written as IPv6
  (`::ffff:…`) as the IPv4 one. The site is behind Cloudflare (the owner's decision of 2026-10-11):
  `client_address.trusted_proxies` in `config/app.php` are Cloudflare's published ranges, and on a connection
  from one of them the address in `CF-Connecting-IP` is counted - from anywhere else the header is whatever the
  sender wrote, and the connection's own address counts. `CF-Connecting-IP` and no other header, never a setting:
  Cloudflare writes it whole over whatever the visitor sent, one address, while `X-Real-IP` and `True-Client-IP`
  pass through it as the visitor wrote them and `X-Forwarded-For` starts with what they wrote - none of those is
  ever read. A `CF-Connecting-IP` that is not one address is not believed either: the proxy's own address counts
  then (stricter, never looser). The reading is the anotoki library's one rule
  (`Anotoki\Lib\Support\ClientAddress`, anotoki-lib 0.5.0), and the admin tools read by it too, over the same
  list (`AdminContext`'s `trustedProxies`): the feedback's limits and the error log's client hash count the
  visitor, never Cloudflare's edge. `config/app.php` is the same everywhere and travels with the upload: change
  it here and build again. An installation that is not behind Cloudflare overrides the proxies - and nothing
  else - in `config/client_address.local.php` (by hand, gitignored): `<?php return ['trusted_proxies' => []];`.
  A range wider than /8 (IPv6: /16) there is refused - believed, a forged `CF-Connecting-IP` from anywhere in
  it would walk past every limit - and the site answers "not set up" until it is narrowed. One that cannot be read
  cleanly (above, "Configuration") trusts no proxy at all until it is mended - coarser, never looser.
- **The origin secret** (anotoki-lib PHP 0.5.2, the owner's decision of 2026-10-13): Cloudflare's ranges are
  every Cloudflare customer's, not the owner's zone alone - anybody's Worker can open a connection to the host
  from them and write the `CF-Connecting-IP` it likes, a new address every time, past every limit here. So the
  owner's zone adds a secret header to every request through it, and the site believes `CF-Connecting-IP` only
  on a request that carries it; any other request from Cloudflare counts as the edge it came from (coarser - a
  Worker's made-up visitors share one bucket - never looser), for the site's counters and the admin tools alike
  (`Config::originSecret()`, `App\Web\ClientAddress`, `AdminContext`'s `originSecret`). The same value in two
  places, a long random value of 32 characters or more:
  1. Cloudflare: Rules → Transform Rules → Modify Request Header → "Set static" `X-Anotoki-Origin` to the secret,
     for every request of the zone (one rule serves every anotoki site; a site without the secret pays it no heed).
  2. The host: `config/cloudflare.local.php`, by hand, beside the other settings (gitignored, never written or read
     by `setup.php`, never in the upload - an upload leaves it where it is), the same file in every anotoki site:
     `<?php return ['origin_secret' => '<the same long random value>'];`. Keep a copy off the server.

  Set the rule first, then the file: the other way round, every visitor counts as their edge until the rule is
  there; changing the secret, likewise. Without the file the site believes `CF-Connecting-IP` from the trusted
  proxies alone, as before: **deleting the file is the one way to switch the secret off.** **A file that is there
  but yields no usable secret fails closed**: one that cannot be read cleanly (the family's rule, as the IAM's
  `iamOriginSecret()` keeps it; this site's since 2026-10-18) - one that does not start with `<?php` (a byte-order
  mark, a blank line, no `<?php` line - never compiled), one that prints, one PHP cannot read or run -, and one read
  cleanly that names no secret (since the owner's word of 2026-10-18: a value that is not a string with something
  in it, a misspelt or another key, no array; anything in the file's place, a folder too) stands for a secret no
  request carries, so every request from Cloudflare's ranges counts as the edge it came from - the file's own value
  too - until it is mended. The file is optional: the site keeps running either way, and PHP's error log says so
  once a day, by the file's fault or its name, never its text. Never "no secret" there: that would believe
  `CF-Connecting-IP` from every Cloudflare customer's Worker. The secret is never shown or logged.
- **Cloudflare's ranges change rarely.** The committed list is the one https://www.cloudflare.com/ips-v4 and
  https://www.cloudflare.com/ips-v6 published on 2026-10-11 (15 IPv4, 7 IPv6 ranges) - the library's
  `ClientAddress::CLOUDFLARE` of 0.5.0 is the same list, and `tests/visitors.test.php` holds the two to each
  other. When Cloudflare announces a change, fetch both again, replace `client_address.trusted_proxies` with them
  (and the date in its comment), run the tests (the library's list follows in its next release), build and
  upload. A range missing from the list makes every visitor through it count as that
  edge's address (they share its limits); a range listed that is no longer Cloudflare's would let whoever holds
  it name any address.
- **No address is kept.** The counters (the `cache` table: `visitors:new:...`, `rate:*:ip:...`) are keyed by an
  HMAC of the counted address under the installation's key (`storage/anotoki-lib.key`, the library's) - never
  the address itself, so neither the table nor a backup of the database holds one. The feedback limits and the
  error log keep a keyed hash too, and nothing else reads an address.
- **Watch the count** after a launch: the People page (ADMIN) shows the visitors and their accounts apart, or
  `SELECT count(*) FROM users WHERE id < 0`; and `storage/logs/app.log` ("Visitors unseen for 30 days
  deleted: N"). Lower the limits in `config/app.php` if the database grows faster than it should.
- **At sign-in** the page sends `POST /api/visitor/move` with the token and the key: the visitor's accounts
  become the person's (the person's copy of an account both have stays), and the key opens nothing any more; a
  message (the kit's toast) says how many moved and how many were kept. When the move cannot be made just then (no network, a
  503) the browser keeps the key and asks again a few seconds later, then at the next page load - the
  visitor's accounts are out of sight meanwhile, never lost.
- **After an automatic sign-in** (the IAM's sign-in hint's silent hop, nobody's click - the owner's decision of
  2026-10-11) the move says so (`silent`), and the server moves the visitor's data only into an account that has
  nothing here yet (no game account, no import run: its first time here, after playing as a visitor). An account
  with data here never takes in a browser's visitor data without its person's click: nothing moves (`held`), the
  key stays this browser's, and signed out again the visitor's data is there; the next sign-in the person
  clicks moves it. How the present sign-in began, the browser notes at its callback (localStorage
  `build-analyzer-sign-in`: `silent` or `clicked`).
- **In the browser** the key is localStorage `build-analyzer-visitor`: every tab of the site shares it, and a
  second tab's first import never replaces it (its one import is left to the clean-up). A private window that
  refuses storage keeps the key for the tab only. A lost key (site data cleared, another browser) loses the
  visitor's data: by design - the notice on the visitor's pages says it is kept "for this browser".

## The catalog

The game catalog - Genshin Impact's characters, weapons and artifact sets, with their elements, weapon types,
rarities, regions, versions and icons - comes from the owner's own genshin.anotoki.eu (the owner's decision of
2026-10-20, [decisions.md](decisions.md)): its public lists
`GET https://genshin.anotoki.eu/api/catalog/characters`, `/weapons` and `/artifacts` (`config/app.php`
`catalog.genshin.api`; `App\Services\Catalog\Providers\GenshinCatalog`), each entry with the game's own ID. Imports
and builds files are matched against it ([name-matching.md](name-matching.md)), and only a refresh writes it.

- **A refresh** asks for one list with the version tag (ETag) its last read kept: "unchanged" (304) writes
  nothing; a changed list is written in one transaction, each row found again by its in-game ID, then by its key -
  a renamed entry keeps its row and everything linked to it -, and no row is ever deleted. An answer over 5 MB or
  of more than 5000 entries is refused whole; an icon address is kept only on the provider's own host (the icons
  are served from here, `/api/icons/…`, each fetched once into `storage/icons/`). One refresh of a list at a time
  (a claim of two minutes at most; another meanwhile is told "This list is being refreshed", 409). Where each list
  stands - its tag, when the provider last confirmed it, when it last changed, the last failure and why - is kept in
  the `cache` table (`catalog-state:{game}:{list}`), which a restore leaves as it is.
- **The stats behind the stat targets** (since 0.8.0, [analysis.md](analysis.md), "Stat targets"): each character's
  and weapon's base stats at level 1 and level 80 ascended, kept in the rows' `meta.stats` - from the lists'
  `stats` field, which that site's catalog carries since 2026-10-26; while a list carries none (a release of that
  site from before), the same numbers are read from the site's own API (`catalog.genshin.site_api`: its
  `/characters` and `/characters-ascensions`, `/weapons` and `/weapons-ascensions` - phase 0 and phase 6 of each,
  as that site's catalog endpoint reads them), matched by the game's id, four more requests per refresh of those
  two lists; a phase list that cannot be read leaves the rows without stats, and the list is written all the same.
- **Who refreshes it**: an editor, on the admin panel's Builds page - **Refresh catalog** asks the three lists one
  after another, each in full (`?force=1`, since 0.8.1: a conditional request would answer "unchanged" and keep a
  list's stats out until it changes), with a progress bar, and stops at the first that fails (each in the audit
  log, `catalog.refreshed`); `php sync-catalog.php [genshin] [--list=characters|weapons|sets] [--force]` (without
  `--force` a catalog whose lists were all confirmed within `refresh_days` is left alone; `--force` asks every list
  in full, without its tag); and the upkeep after an answer (`App\Services\Catalog\CatalogUpkeep`,
  `upkeep.catalog`): at most every five minutes an answer looks for a list not confirmed within
  `catalog.refresh_days` (3) and refreshes the first it finds after the answer, a list that failed waiting
  `catalog.retry_minutes` (60) before its next try. An account's import that names an in-game ID the catalog lacks,
  and a builds file that names a character or a weapon it lacks, refresh that list first - at most once per list
  every `catalog.import_refresh_minutes` (60), for everybody together.
- **When genshin.anotoki.eu is closed or failing**, this site keeps its catalog as it is and goes on working with
  it. The Builds page's catalog card shows per list how many entries it has and when it was last refreshed, and
  under "The last refresh failed" why: "The game data provider is closed for now (HTTP 503)" (that site's update or
  maintenance; 401: it requires sign-in; 423: the route is locked), "… has no such list" (404: its catalog endpoint
  is not there), "… could not be reached", "… answered HTTP 500" or an answer it could not read. The upkeep tries
  again an hour later; **Refresh catalog** tries at once (and answers 502 with the same reason when it fails).

## Importing builds

**The site never fetches a build.** The builds are put together on the owner's computer, from community build
sites, by the owner's own tools in the untracked, git-ignored `local/` folder (never in the repository, and nothing
of it is copied into a tracked file): they read each site politely, keep every page on disk, sort its builds by
role, pick per role and constellation, make every text neutral, check the result with this site's own reader of
the file and write `builds-genshin-<date>.json` with a report. An editor imports that file on the admin panel's
**Builds** page (`/admin/builds`; editors and admins, English), one file per game. What a build is, the file's
format and what the tools must deliver: [builds.md](builds.md).

- **A build is a variation of a character**: its role (Main DPS, Sub DPS, Support), a short qualifier where a
  character has two builds of one role ("Melt"; at most 40 characters, one line), and the constellation it needs
  (`min_rank`, 0-6); a stat target may start from a constellation. (character, role, qualifier without regard to
  case, constellation) is its identity. The analysis follows the player's constellation: a build their character
  has not reached shows a lock and "needs C4", and is never their best. Since 0.6.0 a build also lists the teams
  it is played in, the best first: each teammate's role in the team, a constellation the team asks of one, and who
  can stand in.
- **Import a file** checks the file in the browser first (JSON, the format and its version 3, the game, at most
  8 MB), then sends it as a dry run, which writes nothing and is the preview: how many builds are new, updated,
  unchanged and removed (and how many teams the file holds), a warning when the file is older than the one the
  builds now come from, the characters skipped, the teammates the catalog lacks (kept, shown by their names until a
  refresh brings them - they skip nothing), the changes one by one, and - where the file and an editor's hand edits
  disagree - a question per build ("Keep your edit" unless "Take the file's" is chosen; "Keep every edit" and "Take
  the file's everywhere" answer them all). **Import** sends the same file for real, with the answers; **Cancel**
  forgets it. When an edit was saved or another import came meanwhile, the page checks the file again and says so.
- **The file is the truth for its game, but for what editors did by hand**: once imported, the game's builds are the
  file's - a character the file does not have loses its builds -, but for the editors' work (an edit stays until the
  file changes the same part and the file is chosen; a build made here stays; a removed build stays removed; notes
  are never touched - [builds.md](builds.md), "Hand edits") and for characters this installation's catalog lacks,
  which are skipped and named ("Not in this installation's catalog": their list was refreshed first, at most
  hourly - Refresh catalog later, then import the same file again). A build found again by its identity keeps its
  id, so what names it by its id (a character's analysis asked `?build=ID`) stays valid. One import at a time: one
  transaction under an advisory lock, and another meanwhile answers 409 and writes nothing. Every import is
  recorded - the Builds page's recent imports (when, the file's date, what it did, who), the table
  `build_imports`, the audit log's `builds.imported`.
- **Refused whole** (422, naming the first problem; nothing written): a file that is not a builds file, one of
  another game, one of format version 2 (made before 0.6.0: builds without their teams) or 1 (a source's export
  from this site's versions before 0.5.0), each said in its own words, one with any malformed build or team, more
  than 5000 builds or over 8 MB; and a file none of whose characters
  the catalog knows ("Refresh the catalog on the Builds page, then import again" - importing it would only
  remove every build).
- **Export** saves the game's builds as such a file - a backup, or the builds for another installation (what it
  writes always imports; it holds the hand edits as builds of the file's, without the site's notes). Each installation holds its own builds: import the same file on
  this computer and on the host.

## A person's own accounts as a file

Every signed-in person (any role or none; never a visitor, who signs in first) can save their game accounts as a
file and bring them back - into this installation or another: `GET /api/me/export` and
`POST /api/me/import` - the "Your data" card on the dashboard (Export; Import a file, its preview, Confirm;
a file over 8 MB is refused before it is sent). The format and every rule:
[data-model.md](data-model.md), "Accounts files"; why the newer copy wins and nothing is deleted:
[decisions.md](decisions.md).

- **Export** saves `build-analyzer-accounts-2026-10-04.json`: every account with its characters, weapons,
  artifacts and its newest 200 import runs - keys and neutral words only, no ids. Keep it as a backup, or import
  it on another copy. A file holding a Star Rail account (exported by this site before 0.5.0) is refused (422
  `bad_entry`): the site keeps Genshin Impact accounts alone.
- **Import** asks first (a dry run: what would be added, replaced by a newer copy, kept, and how many entries
  this copy's catalog does not know), then imports. It never deletes an account: an account of the file that is
  not here is added; one that is here is replaced only when the file's copy was imported later; the rest stays
  as it is. The same file twice changes nothing.
- **Limits** (`config/app.php` `me`): 5 imports a minute per person, dry runs included (429
  `too_many_imports`); a file of at most `file_max_mb` (8). The export refuses rather than write a file larger
  than that (422 `export_too_big`) - a few thousand artifacts take well under 2 MB. Keep `file_max_mb` at or
  below the host's PHP `post_max_size`: a larger body arrives empty ("No file arrived").
- Entries this copy's catalog lacks (a character newer than its last catalog refresh) are left out and counted.
  Have an editor refresh the catalog (the Builds page) before importing: the same file imported again afterwards
  keeps the account as it now is here (the copies are equally new), so the left-out entries come only with a
  newer export, or by deleting that account here and importing the file again.

## The site's words

The member pages read in English or Slovak; the words are rows of the database (migration 003,
[architecture.md](architecture.md), "The site's words"), kept since version 0.4.0 by the anotoki family's
translations module (anotoki-lib, since its 0.3.0) - the same two pages as on every anotoki site, with the library's own
words (the language button's, the status page's, its kit's) among the keys as `anotoki.*`. An editor or an
admin opens them in the admin panel -
the "Admin panel" item of the person's menu in the top bar, then Translations (`/admin/translations`); the Languages page
(`/admin/languages`) is an admin's, as are adding, renaming, switching and deleting a language:

- **Translations**: every key with its description and a cell per language. Change what you like and save
  once; the whole save is checked first and refused with the key and language at fault (a placeholder the
  English lacks, a stray brace, a web address or another site's name, a string over 2000 characters). An
  empty cell in a language other than English means "no string of its own": that language reads the English.
  What is not saved yet is kept for the tab, and leaving the page asks first. **Export** saves one
  language's own strings as a `{key: text}` file; **Import** reads one back with the same checks (empty
  strings are passed over, nothing is deleted).
- **Languages**: add one (it starts hidden: write its strings, then offer it), rename, reorder, offer or hide,
  delete (with its strings; never English or Slovak, and English is never hidden).

**Each installation holds its own strings.** What is reworded in the browser lives only in that database: the
owner's computer and the host each have their own, and no upload carries them. Export a language before a
database is replaced, and import it on the other installation to carry edits across (the command line does the
same: `php translations.php --export=sk file` / `--import=sk file`, for the database of
`config/database.local.php`).

## The admin tools (ADMIN)

The anotoki family's admin tools (anotoki-lib 0.5.0: `App\Admin\AdminTools`, `api/routes/admin_tools.php`; the
pages `@anotoki/lib/admin/*` on `/admin/errors`, `/admin/audit`, `/admin/feedback`, `/admin/backups`) - for the
site's ADMIN alone: an editor sees none of them and the server refuses them (403 `admin_only`). English, as the
whole admin panel. No file explorer here (the owner's choice for this site).

- **Error log** (`storage/logs/errors-YYYY-MM-DD.jsonl`, UTC days, 30 kept, 64 MB at most): what failed - an
  exception the API answered 500 (its route, who asked, its trace - and, for one thrown for another, each cause's
  line), PHP's warnings and fatal errors - and the answers worth seeing: 404, 405, 413, 415, 429 and every 5xx, but
  an answer whose code says a state the site is in, not a failure (`AdminTools::EXPECTED_ANSWERS`, the family's
  rule: the site gate's `update_pending` and `not_set_up`, a table that waits for its update -
  `feature_unavailable`, `visitors_unavailable`, `translations_unavailable`, `people_unavailable`). A 503 that is
  a failure is written: feedback's `storage_unavailable` (its limits cannot be counted), the move's
  `visitor_move_retry`. Never a request's body, query, header or token; an address only as a keyed hash. The page groups them,
  with each one's occurrences; **Clear** deletes the days chosen (audited). `storage/logs/app.log` stays the
  site's own log of what it did (imports that failed, catalog refreshes that failed, migrations, the visitors'
  clean-up).
  **The errors the pages meet in the browser** are in it too (the owner's decision of 2026-10-11, every site):
  the library's reporter (`@anotoki/lib/error-report`, the app's `ErrorHandler`) scrubs each - the page's path
  alone, no address, token, secret or value the browser keeps - and sends it to `POST /api/client-errors`
  (`ErrorLogRoutes::client()`), which redacts it again and writes it as `source: client`: at most five a page
  load, each once, never an HTTP failure. The route is public, behind the site's rule for a public route (a
  signed-in person known by their token; while the IAM requires sign-in here, anybody not signed in refused) and
  closed while an update waits; it takes 20 an hour from one sender - a person by their account, anybody else by their address
  bucket - and 500 a day from the visitors, 500 from the people signed in (429 `too_many`), counted by keyed
  hashes in `storage/logs/client-errors.json`.
- **Audit log** (the table `anotoki_audit_log`): what was done in the admin panel and by whom - a game's builds
  imported, a character's builds edited by hand (`builds.edited`), the catalog refreshed, strings saved and languages changed, a person's data
  deleted, the IAM's deleted accounts checked, migrations applied, the error log cleared, feedback marked and
  deleted, backups made, downloaded, deleted and restored. No address is kept. A restore keeps it as it is.
- **Feedback** (the table `anotoki_feedback`): what visitors send from **Feedback** at the foot of every page - a
  bug, an idea or anything else, about one of the site's places (Dashboard, Import, Accounts, Builds). Nobody is
  mailed: the admin panel's column counts what is new beside Feedback. Who sent it comes from their token alone; for somebody signed
  in the page writes their account's address into the form's address field, and the address they leave there -
  none once they cleared it - is the only one kept, unless they tick "send it without my name" (no id, no
  address): their choice (the owner's decision of 2026-10-11). Nothing on the server fills an empty field: the
  library keeps only what the form sends (anotoki-lib 0.5.0). Kept with a message: the page and the language it was
  sent from and the browser's name (its user agent, blanked by the scrub with the address of a person the IAM
  deleted); never the network address it came from, nor a hash of it: the limits - 5 an hour and 20 a day from
  one sender (a signed-in person by their account; anybody else, and a message "without my name", by their
  address), 300 a day from the visitors together and 300 from the people signed in - count apart, in
  `storage/cache/anotoki-feedback-limits.json`, a day. Opening a message marks it read; Mark as and Delete are in
  the audit log (a delete's entry written in its own transaction: a restore deletes the message again by it).
- **Backups** (`storage/backups/*.anotoki-backup`, the folder denied to the web): the whole database by COPY (no
  `pg_dump` on the host) - every day after the first answer of a UTC day (`config/app.php` `upkeep.daily_backup`;
  never while an update waits), before every Apply (the Migrations page's and `php migration.php`'s), before every
  restore, and by hand (**Back up now**, or `php backup.php` on this computer). Making, downloading (a single-use
  link of a minute), deleting and restoring ask for a sign-in of the last ten minutes - the page offers **Sign in
  again**. A restore says first what it would take away, wants the backup's last six characters typed, and puts
  every table back but the audit log and the `cache` table (the counters, the claims, where each catalog list
  stands), after a backup of the database as it was. What it must not bring back, it does again (anotoki-lib
  0.5.0, `People::restoreHooks()`):
  - **What was deleted since the backup** is deleted again inside the restore, before anybody sees its rows: every
    person's data deleted on the People page and every feedback message deleted, as the audit log (which a
    restore keeps) says - each entry is written in its delete's own transaction, so no delete is made without
    one. The People page's delete runs on the restore's own connection (`SitePeople::deleteData()` works on the
    connection it is given), and what cannot be done again rolls the whole restore back ("nothing of it
    stayed"); so does a check of the IAM's deleted accounts running at that moment ("restore again in a minute").
  - **The IAM's deleted accounts** are checked again at once, once the restore committed: whoever the IAM deleted
    since the backup was made is nobody's here again before the answer. When that check cannot finish (the IAM
    out of reach, more to read than its time allows) the restore is done and says so - the Backups page's note,
    the error log - and the check runs again with the next request (Check now on the People page at once).
  - **The ids**: a restore never moves a sequence back, so an id handed out since the backup - one the kept audit
    log may name - is never handed out again.

  Only those deletes are done again: a restore brings back everything else as the backup has it - also what people
  deleted themselves since (a game account deleted on its own page), which is neither audited nor done again.

  **Only a backup made here is restored here** (anotoki-lib 0.5.0): every backup is sealed - an HMAC of its id and
  its SHA-256 under a key derived from this installation's `storage/anotoki-lib.key` - and the page and `php
  backup.php --restore` restore a sealed one alone, hashing the very bytes they read. One brought from another
  installation (downloaded from the host and put into this computer's `storage/backups/`, sealed under the
  host's key), one changed since, and **every backup made before anotoki-lib 0.5.0** (they have no seal) are listed
  but not restored here (`422 archive_invalid`): they are read offline - `php backup.php --inspect=<id>` and
  `--extract=<id> <folder>`, then `psql \copy`. So is one made while `storage/anotoki-lib.key` could be neither
  read nor made (a storage folder PHP cannot write): the backup is still made, but unsealed - the error log says
  so, and the list shows it not restorable here. **Nor is one made before a migration applied since**: a backup
  is restored in place only while the database's applied migrations and columns are the ones it was made with (else
  `409 schema_differs`, naming the files) - so every backup made before this site's 0.5.0 (migrations 014-019) is
  read offline too ("Deploying 0.5.0", below). Make a backup right after an update. The seal keeps out a file put
  into `storage/backups` by whoever cannot reach the key;
  whoever can write the whole storage folder can make the key their own. The backups' files are the site's own
  user's alone (`0600`, folders `0700`). The list names each backup's maker (the "By" column).

  **Backups have no maximum age** (the owner's decision of 2026-10-11): a backup is kept until it is
  deleted - a manual one only by the owner (the page flags one older than 90 days, and deletes nothing), the
  automatic ones by the rotation by count (the newest 14 daily, 5 before an update, 5 before a restore). So a
  person the IAM deleted, and the data of a person deleted on the People page, stay in the backups made before
  until those backups go - delete them by hand when that matters. Download one now and then, and keep it off the
  host.

## The site settings (ADMIN)

The anotoki family's site settings (anotoki-lib 0.11.0, the owner's request of 2026-10-25: genshin's switches on
every site; [decisions.md](decisions.md)): the admin panel's **Site settings** page (`/admin/site-settings`, the
library's, English) - the site's ADMIN alone (an editor is refused: 403 `admin_only`) - throws them without a deploy,
each change audited (`site_settings.changed`, `page_access.added`, `.changed`, `.removed`). Kept in the library's
tables (its `anotoki_admin/003`); the site's part is `App\Web\SitePages`. All three parts:

- **Getting in**: closed for maintenance, every API path answers `503 maintenance` (`Retry-After: 300`, the
  administrators' message per language) to everybody but the site's ADMIN, whom the site gate knows by the token alone;
  the pages draw the family's closed sign, in the page's language (the message written for it, else English, else the
  library's sentence). The ADMIN uses the site as it is, a line above every page saying it is closed. An administrator
  signed out signs in at **`/staff`** - the closed sign offers Sign in there alone, back to the dashboard. What keeps
  answering while it is closed: the site's status (it says `maintenance`), `/api/meta` (the sign-in's configuration and
  the start), the words, the settings themselves, the catalog's pictures (`/api/icons`: an `<img>` carries no token) -
  and the Migrations page's, the error log's and the backups' routes, which are the ADMIN's anyway. Nothing of it goes
  into the error log (a state somebody chose, not a failure).
- **Announcement**: a bar above everybody's pages - information, a warning or something wrong -, a message per
  language (else English, else no bar). A reader's Hide keeps a mark of it in the browser (never its words,
  `build-analyzer-announcement`) until its words or its kind change.
- **Page access**: a row per page - who sees it (anybody, anybody signed in, editors and administrators,
  administrators), or switched off for all but the administrators - and the API paths refused with it (`423
  page_locked` to whoever the page is not shown to: a visitor is nobody here). The page itself is taken out of the
  router (it falls through to the dashboard, or to "This page is not open right now" where the dashboard is closed
  too) and out of the bar's sections and the sitemap; the site's ADMIN keeps everything, with a line above such a page.
  The pages it can govern, as the router names them, with the API paths each reads (`SitePages::GOVERNED`) - name a
  page's paths with its row where its data must go too; an API path claimed by two rows is refused only when both
  refuse the reader:

  | Page | Its API paths |
  | --- | --- |
  | `/` (the dashboard) | `/api/builds` (the builds' counts), `/api/accounts`, `/api/me` |
  | `/import` | `/api/imports`, `/api/accounts` |
  | `/accounts/:id` | `/api/accounts`, `/api/me` |
  | `/accounts/:id/characters/:key` | `/api/accounts` |
  | `/builds/:game` | `/api/builds` |
  | `/builds/:game/:key` | `/api/builds` |

  No row governs `/auth`, `/staff`, `/admin`, the legal pages, About, Support or `/sign-in`, and no row claims
  `/api/meta`, `/api/icons`, `/api/site-status`, `/api/translations` or the settings' own two - either way round
  (`/api/m` covers `/api/meta`; `/api/meta/x` is inside it): the page refuses them, and one put in the table by hand
  is passed over.

**robots.txt and sitemap.xml** (the library's `Robots`, answered by the site's PHP - never a file of the app: a release
refuses one at a backend route). robots.txt keeps every robot out of `/admin`, `/api`, `/auth`, `/accounts` (a reader's
own accounts), `/sign-in` and `/staff`, and names the sitemap by the site's own address ("Configuration", above).
sitemap.xml lists the legal pages, About and Support always, and while the IAM lets visitors in the dashboard, the
import, the builds browser and every character's builds page - each with the day one of its builds last changed, by a
file or by hand - never what Page access hides. Both `Cache-Control: public, max-age=3600`; made afresh for each
request (no kept copy: an import or the IAM's switch shows at once).

## Deploying (FTP-only shared hosting)

The host: Apache with `.htaccess` support (`mod_rewrite`), **PHP 8.4** with `pdo_pgsql`, `openssl`, `mbstring`,
`curl` and `zlib` (the backups compress with it) - the setup page checks each -, and a PostgreSQL database
made in the hosting panel. No shell, cron, queue worker or long-running process is needed. The steps, with the
upload map, are in [DEV.md](../DEV.md) ("Deploying"); in short:

1. `cd frontend && bun run anotoki-deploy build` (anotoki-lib's deploy tool, set up in `backend/composer.json`'s
   `extra["anotoki-deploy"]`) runs the frontend's build and `backend/tools/check-release.mjs` (it refuses a
   frontend that names another site - a build site too, with the names file in `backend/config/`), then assembles
   the release: `dist/frontend/` (the Angular build, `index.php`, `.htaccess`) and `dist/backend/` (`api/`, `src/`,
   `config/app.php`, `migrations/`, `vendor/`, `setup.php`, `release.json`) - never a `*.local.php`.
2. `bun run anotoki-deploy deploy --backups <folder> --go` puts it into `anotoki.eu/sub/builder/`: `frontend/` and
   `backend/` replaced whole beside `config/` and `storage/`, which no release writes (the first time,
   `bun run anotoki-deploy switch --go` once: the folder's `.htaccess` sends every request into `frontend/`).
   `config/` and `storage/` must be writable for the web server.
3. First install: open `https://builder.anotoki.eu/setup.php`, read the token from
   `config/setup-token.local.php` over FTP, fill in the database and the IAM, test both, press "Set up". It
   checks the requirements and that `storage/` is not served, builds the database, writes the config files
   (owner-only where PHP runs as the FTP account), writes `storage/installed.lock` and deletes the token and
   itself.
4. Put the names file, `config/neutral.local.php`, beside the other settings by hand ("Configuration", "The
   names file"). Sign in with the ADMIN account and open the admin panel's **Builds** page: **Refresh catalog**,
   then **Import a file** - the newest builds file - and, after its preview, **Import** ("Importing builds").
5. A new version: `bun run anotoki-deploy deploy --backups <folder> --go` again - the release replaces `frontend/` and
   `backend/` whole, nothing stale stays, and `rollback --go` brings the one before back. **While a new
   migration waits the site is closed** (the site gate - the anotoki family's one
   behaviour, the owner's decision of 2026-10-07): the API answers every path 503 `update_pending` (`Retry-After:
   60`) but `/api/meta`, a language's strings, `/api/site-status` and the Migrations page's API; everybody reads
   "The site is being updated" in their language - their page asks again every 30 seconds and comes back by
   itself - and the site's ADMIN, signed in, reads "A database update is waiting" with **Open Migrations**
   (signed out: that page's quiet **Sign in** goes to the IAM and back; it asks again by itself too, so it
   opens once another administrator has applied the update). The Migrations page (`/admin/migrations`,
   ADMIN only, the one page that works then) lists the waiting files in the order they will run beside the
   applied ones with their dates and SQL. Then **Apply pending** (`POST /api/admin/migrations/apply`) at once - it
   makes a backup of the database as it is first (the Backups page lists it, kind "before-migration"; the error
   log and the backups stay open while an update waits, restoring does not): the files run in order, each in its own transaction,
   and the run stops at the first that fails - that file rolled back whole, the page naming it with the
   database's error (the log too). One apply at a time, whoever runs it - the page, `php migration.php`, setup:
   each takes the same advisory lock, and another is told it is busy (409 `busy`) and does nothing. Once nothing
   is left, the site is open again.
   - **The release that brings the words (migration 003)**: until Apply the site is closed; the page that
     applies it reads the words at once, and every other tab when it comes back.
   - **A release that adds strings**: they are there once Apply is pressed - until then the site is closed
     anyway (only the ADMIN's frame of the Migrations page may show a new key). Upload and press Apply in one
     sitting.
   - **Version 0.4.0: the family's site gate and Migrations page (anotoki-lib in `vendor/` and in the app;
     migration 005)**: upload with `vendor/` (it holds `vendor/anotoki/lib`), then Apply 005 on the
     Migrations page - it only deletes the six words of the old "Not set up yet" page. From then on every upload
     with a migration closes the site until it is applied, and a site whose `storage/installed.lock` is there
     never offers the setup page again.
   - **Version 0.4.0: the site's words through the anotoki library (anotoki-lib 0.3.0 in `vendor/` and in the
     app; migration 006, then the library's `anotoki_translations` 001 and 002)**: before uploading, **Export**
     each language on the Translations page (a copy of every rewording). Upload with `vendor/`, then Apply at
     once - one Apply runs all three, the Migrations page naming each file with its set
     (`build_analyzer/006_…`, `anotoki_translations/001_…`): 006 moves the language button's two strings
     (`language.button`, `language.notLoaded`) to the library's keys (`anotoki.language.button`,
     `anotoki.language.notLoaded`), every language's string with who reworded it and when, and refuses (rolled
     back whole) if anything did not arrive or the site's own keys are not the 450 it expects; the library's 001
     finds this site's three tables already there, checks they are in the shape it needs and changes nothing;
     002 adds the library's own words (English and Slovak), never over a string that is there. Then check: the
     Translations page lists the `anotoki.*` keys too, and in Slovak (`/?lang=sk`) the language button is named
     "Jazyk: Slovenčina (SK)". The strings' answers are the library's now: the same shapes (snake_case), the
     same rights (EDITOR the strings, ADMIN the languages), a few new refusals (415 a body that is not JSON, 413
     one over 1 MiB, 422 `empty_body`, 400 `invalid_json`).
   - **Version 0.4.0: signing in, people and the admin tools through the anotoki library (anotoki-lib 0.5.0 in
     `vendor/` and in the app - the host needs `mbstring` and `zlib`; the library's `anotoki_admin` 001-002, migrations 007-010, then `anotoki_translations`
     003-004 and `anotoki_people` 001)**: the IAM must already set its sign-in hint (the IAM's release of
     2026-10-07) - without it nothing breaks, the automatic sign-in only waits for it. Before uploading,
     **Export** each language on the Translations page. Upload with `vendor/`, sign in with the ADMIN and **Apply
     pending** at once - one Apply runs them all, in the README's order, after a backup of the database as it was:
     `anotoki_admin` makes the audit log's and feedback's tables; 007 gives `users` its `anonymized_at` and touches
     no row; 008 drops what the first (Laravel) version left, where it is - its login tables `laravel_users` (old
     addresses and password hashes), `laravel_password_reset_tokens` (addresses, reset tokens) and
     `laravel_sessions` (network addresses, browsers), and its queue's `laravel_jobs`, `laravel_job_batches` and
     `laravel_failed_jobs` (old payloads), with their sequences, in one statement - keeping only
     `laravel_migrations` (the owner's decisions); 009 moves the five
     sign-in words this site had to the library's `anotoki.signIn.*` keys with every rewording, its writer and its
     time, retires three, and adds Feedback's words; 010 moves the top bar's twelve to `anotoki.topbar.*` and
     `anotoki.theme.*` the same way (the bar is the family's now) and retires two of Material's stepper's; the
     library's 003-004 add the sign-in screen's and the form's
     own words, never over a string that is there; `anotoki_people` makes where the scrub stands. Each refuses
     (rolled back whole) when what it proves does not hold. **A step of this release, not an option: delete the
     "before-migration" backup this Apply made** on the Backups page, once the site works - it still holds those
     six tables (and the cache table's counters as version 0.3 kept them, by address). Nothing on disk is deleted
     by the update: `storage/logs/app.log` stays the site's log; the error log and the backups are new files
     beside it (`storage/logs/errors-*.jsonl`, `storage/backups/`) - delete any old file by hand when you like.
     Then check: a private window where you are signed in at the IAM opens the site signed in, without a click;
     Feedback at the foot of a page sends, and the admin panel counts it beside Feedback; the People page lists
     you; the Errors, Audit and Backups pages open; **Back up now** lists a backup by you that can be restored here
     (one made on this computer, or before anotoki-lib 0.5.0, cannot: it is read offline); `https://builder.anotoki.eu/robots.txt`
     says `Disallow: /auth/`. A token that does not verify now answers 401 `invalid_token` (it was
     `sign_in_required`).
   - **Version 0.4.0: Terms, Privacy and Contact, and the kit's newer pieces (anotoki-lib 0.6.2 in `vendor/` and in
     the app; migrations 011 and 012, then the library's `anotoki_translations` 005-011)**: with the files above, in the same Apply, or on their own
     after them - **Export** each language first, upload with `vendor/`, and **Apply pending** at once: 011 adds the
     legal pages' facts (the site's own words: what it keeps, what for and how long, its browser storage, who else
     handles data), their tabs' titles and the footer's fan clause, in English and Slovak, and rewords five
     descriptions that still told of a snack bar; 012 adds the names the kit's stepper and a build's tab badge are
     heard by and retires the old stepper's "Completed"; the library's 005 writes the pages' shared words and the footer's
     links', 006-009 the kit's newer pieces' and 010-011 its own rewordings of the Privacy page - each only where
     a key has no string, or where nobody saved it. Then check: the foot of every page has Terms · Privacy ·
     Contact and the fan clause; `/privacy` reads the site's parts and its storage, and links "your anotoki account"
     to the IAM's own Privacy page (`https://iam.anotoki.eu/privacy`); `/contact` opens the feedback form. The
     facts are the code's (`frontend/src/app/sites/main/legal/legal-facts.ts`): a change to what the site keeps, or for how
     long, changes their `legal.*` strings by a migration and `SITE_LEGAL_UPDATED`.
   - **The family's footer (anotoki-lib 0.6.3 in `vendor/` and in the app; migration 013, then the library's
     `anotoki_translations/012`)**: upload with `vendor/`, then **Apply pending** at once: 013 moves the footer's
     three words (`footer.version`, `footer.feedback`, `footer.fanMade`) under the library's keys
     (`anotoki.footer.*`) with every rewording, its writer and its time, and copies What's new's (`whatsNew.title`
     stays the dialog's title); the library's 012 describes the four keys, never writing over a string that is
     there. Then check: the foot of every page and of the admin panel reads as before; while "Sign-in required" is
     on, `/privacy` signed out has no Feedback at its foot and `/contact` gives the address alone.
   - **Version 0.5.0: builds from a file, Genshin Impact alone, the catalog from genshin.anotoki.eu (migrations
     014-019)**: genshin.anotoki.eu first, then the names file, the release, Apply, Refresh catalog and the builds
     file's import, in one sitting - the order is its own section, "Deploying 0.5.0" (below).
   - **Version 0.7.0: the builds editor (migrations 022-023)**: the release, then Apply at once; nothing to import -
     its own section, "Deploying 0.7.0" (below).
   - **The admin panel in the family's frame (anotoki-lib 0.10.0, both halves; migration 024)**: the release with
     `vendor/`, then Apply at once - its own section, "Deploying the admin panel's frame" (below).
   - **The site settings, robots.txt and sitemap.xml (anotoki-lib 0.11.1, both halves; migration 025)**: the release
     with `vendor/`, then Apply at once - its own section, "Deploying anotoki-lib 0.11.1" (below).
   - **Version 0.3: visitors and the family's bar (migration 004, IAM migration 007)**, in this
     order: (1) before uploading, make sure an account that can sign in holds **ADMIN on `build-analyzer` now**
     (the IAM's admin panel; IAM 007 is what later gives every IAM administrator ADMIN here); (2) upload;
     (3) sign in with that ADMIN at once and **Apply pending** (004) on the Migrations page - until then the site is
     closed to everybody else; (4) then apply **IAM 007** (`build-analyzer` open to
     everyone, roles ADMIN and EDITOR, no MEMBER) - not before the upload: the old code asks for MEMBER, which
     007 removes. If nobody holds ADMIN here before the upload, apply IAM 007 right after the upload (the old
     code is gone by then) and Apply 004 next. Then check: a private window imports a file without signing
     in and keeps it on reload; `X-Visitor-Key` arrives through Apache (it needs no rewrite, unlike
     `Authorization`; the browser check proves it only through `router.php`); the bar reads in Slovak with
     `?lang=sk`.
   - **The IAM's sign-in switch (SDK 1.1.0; no migration here)**: upload only once the IAM serves
     `GET {issuer}/api/public/applications/build-analyzer` (its migration 010 and the public route) - check that it
     answers 200 with `"login_required": false`. Before that the IAM answers 404, which this version reads as
     "sign-in required" (fail closed): visitors and the signed-out builds browser are turned away until the IAM
     has it. Then try it: switch "Sign-in required" on for Build Analyzer in the IAM's admin panel - within a
     minute `/api/meta` says `"login_required": true`, `/api/builds/genshin` answers 401 without a token and a
     private window opening the site goes to the IAM first - and off again.
   - **Version 0.4.0: the account's theme, dates and language (browser SDK 1.2.0; no migration here)**: upload
     in either order with the IAM's migration 015 - before it, the site keeps its own settings exactly as it did
     (the SDK finds no `preferences` in the tokens and saves nothing). Once both are up, try it: sign in, pick
     Dark in the bar - the IAM's account page (and every anotoki site) shows Dark; pick a language - the account
     has it too; What's new writes its dates in the order chosen on the account page. A visitor's choices stay
     the browser's. The site's server is not involved.
   - **The origin secret (anotoki-lib PHP 0.5.2 in `vendor/`, the app's half still 0.5.0; no migration)**:
     upload with `vendor/` - nothing waits for an Apply. Then, in this order, the Cloudflare zone's Transform Rule
     (Rules → Transform Rules → Modify Request Header → "Set static" `X-Anotoki-Origin` to the secret, every
     request of the zone) and `config/cloudflare.local.php` on the host by hand (*Visitors*, "The origin
     secret"); without the file the site works as before.

Check after an install: `https://builder.anotoki.eu/config/database.local.php`, `/storage/logs/app.log` and
`/src/Config.php` must not download (403); `https://builder.anotoki.eu/api/meta` answers JSON with the IAM's
`issuer`, `client_id` and `login_required` under `auth`; `/api/accounts` answers 401 without a token.

### Deploying 0.8.1

No migration: the release goes up (`bun run anotoki-deploy deploy --backups <folder> --go`) and the site stays open.
The owner deferred the genshin site's release, so the stats behind the tiers are read from that site's own phase
lists while its catalog lists carry none ("The catalog" above), and the Builds page's **Refresh catalog** reads every
list in full. After the swap: **Refresh catalog** once on `/admin/builds` - the characters and the weapons lists
answer "changed" with their stats -, and `/builds/genshin/kamisatoayaka` shows "Worked out for Mistsplitter Reforged
with 4pc Blizzard Strayer." with three tiers on CRIT DMG.

### Deploying 0.8.0

The owner's round of 2026-10-26 ([decisions.md](decisions.md)): a player chooses the build a character is graded by
(migration 026: `account_characters.chosen_build_id`, the choice's words, the Privacy page's game-accounts sentence
where it reads as 015 left it), and every build has stat targets in three tiers, worked out from the game's numbers
([analysis.md](analysis.md), "Stat targets"; migration 027: the tiers' six words, no table). The tiers rest on each
character's and weapon's base stats, which the catalog keeps in its rows' `meta.stats` from genshin.anotoki.eu's
catalog lists - a field those lists carry since 2026-10-26 - so that site's release goes up first.

1. **genshin.anotoki.eu first**: its release with the catalog change (no migration there); its catalog lists answer
   each character and weapon with `stats` (`GET /api/catalog/characters`, `/weapons`).
2. **The release**: `bun run anotoki-deploy deploy --backups <folder> --go`. From then on the site is closed until
   the Apply.
3. **Apply pending at once** (signed in as the ADMIN, on the Migrations page), after a backup of the database as it
   was ("before-migration"): **026** (its gate refuses unless the site's own keys are 452) and **027** (458). No build
   changes, nothing to import.
4. **Refresh catalog** on `/admin/builds` - the characters and the weapons lists: a conditional request answers
   "changed" once the genshin site's release is up (its lists' ETag moved) and the rows gain their stats; refreshed
   before it, a list answers "unchanged" and keeps none - refresh again after. Until then the targets are the stated
   ones alone, as before.

Then check: `/builds/genshin/kamisatoayaka` shows "Stat targets" with "Worked out for Mistsplitter Reforged with
4pc Blizzard Strayer." and three tiers on CRIT DMG (in Slovak with `?lang=sk`: "Vypočítané pre ... so setom ...",
Základná / Stredná / Pokročilá); an account's character page shows "Use this build" and, chosen, "Selected" with
the tiers for the weapon worn; the account page says "Selected build: ...". **The backups made before this Apply
are restored offline only** (`409 schema_differs`, as after every Apply): make a backup by hand once it is applied.

### Deploying anotoki-lib 0.11.1

The owner's requests of 2026-10-25 ([decisions.md](decisions.md)): the site settings (Getting in, the announcement,
Page access), robots.txt and sitemap.xml from the site's PHP, the family's dates and search, and the admin menu in the
family's one shape - anotoki-lib 0.11.0's, on 0.11.1, both halves (`vendor/` from Composer's `^0.11.0`, its lock at
v0.11.1; the app's `@anotoki/lib` from the 0.11.1 tarball). 0.11.1 adds nothing to Apply: the announcement bar and the
administrators' notice come out of the first load, fetched only when there is something to draw. The release's front
controller takes `/robots.txt` and `/sitemap.xml` beside `/api`
(`composer.json`'s `extra["anotoki-deploy"]["routes"]`); the app keeps no `robots.txt` of its own.

1. **The release**: `bun run anotoki-deploy deploy --backups <folder> --go`, `vendor/` with it. From then on the
   site is closed until the Apply.
2. **Apply pending at once** (signed in as the ADMIN, on the Migrations page), after a backup of the database as it
   was ("before-migration"): the library's `anotoki_admin/003` (the site settings' two tables: no row, every switch at
   its default), this site's **025** (the Privacy page's part about the settings, the page that is not open, the
   audit log's sentence where it reads as 023 left it - its gate refuses unless the site's own keys are 446), then the
   library's `anotoki_translations/014` (its new pieces' words) and `015` (the settings' and the closed sign's words).
3. Optional, by hand: `config/site.local.php` only where the site's address is not `https://builder.anotoki.eu`.

Then check: `https://builder.anotoki.eu/robots.txt` ends `Sitemap: https://builder.anotoki.eu/sitemap.xml`, and
`/sitemap.xml` lists the legal pages - with the builds while visitors are welcome; `/admin` has Site settings first in
System (Site settings, People, Feedback, Error log, Audit log, Backups, Migrations); an announcement saved there shows
above every page (and goes once switched off); maintenance switched on shows a private window the closed sign, and
`/staff` its Sign in, while the ADMIN's window uses the site with the line above each page - switch it off again. No
release from before 025 goes on the host after it: its Privacy page would not say what the site settings keep.
**The backups made before this Apply are restored offline only** (`409 schema_differs`, as after every Apply): make a
backup by hand once it is applied.

### Deploying the admin panel's frame

The owner's request of 2026-10-24 ([decisions.md](decisions.md)): the admin panel in the anotoki family's frame,
as every anotoki admin panel - anotoki-lib 0.10.0, both halves (`vendor/` from Composer's `^0.10.0`, the app's
`@anotoki/lib` from the 0.10.0 tarball): the panel's pages in a column on the left, the page on the right, and no
top bar - the bar is everybody's pages' alone. Migration 024 deletes the one string only the admin panel's bar
said - `topbar.homeAdmin`, the name of its brand's link - and describes `brand.admin` and `topbar.backToApp` anew,
which the frame's column says.

1. **The release**: `bun run anotoki-deploy deploy --backups <folder> --go`, `vendor/` with it. From then on the
   site is closed until the Apply.
2. **Apply pending at once** (signed in as the ADMIN, on the Migrations page): 024, after a backup of the database
   as it was ("before-migration"); its gate refuses (rolled back whole) unless the key and its strings are gone, the
   two keys it describes have their English, and the site's own keys are 439.

Then check: `/admin` on a computer - the column with "Analyzer · admin panel", the person, Back to Build Analyzer,
Sign out, the theme and the pages (an editor's two, the ADMIN's nine, the count of new feedback beside Feedback) -
and on a phone (up to 860 px) the row with Menu and its drawer; every page outside `/admin` keeps its top bar, with
no admin sections in it. No release from before 024 goes on the host after it: its admin panel's bar would name the
panel's home by a bare key. **The backups made before this Apply are restored offline only** (`409
schema_differs`, as after every Apply): make a backup by hand once it is applied.

### Deploying 0.7.1

No migration: the release goes up (`bun run anotoki-deploy deploy --backups <folder> --go`) and the site stays open.
It is the owner's look at 0.7.0 (2026-10-23): the Builds page's Edit builds card is a table of every character, a
build's role, qualifier and constellation can be changed in the editor (a build of the file's renamed becomes one of
the editor's own, the file's removed at the save), and a team's members stand side by side. Check: the table lists
every character and opens one's editor; a renamed build's tab says "Renamed" until **Back to the file's name**.

### Deploying 0.7.0

Version 0.7.0 is the builds editor (task 9, the owner's round of 2026-10-22, [decisions.md](decisions.md)): editors
change builds by hand on the admin panel's Builds page (Edit builds), write the site's own notes on a build and its
teams - English, and Slovak where they want -, make builds of their own and remove others, and every import of a
builds file keeps what they did ([builds.md](builds.md), "Hand edits"). No build changes: 022 adds the hand edits'
storage - and fills every build's `file_build`, what the file said of it, with its own columns as they are, so the
next import compares as the last one did -, 023 adds the editor's one word on the member pages and rewords the
Privacy page's sentence on the audit log. Nothing is imported again.

1. Before uploading, **Export** each language on the Translations page (a copy of every rewording).
2. **The release**: `bun run anotoki-deploy deploy --backups <folder> --go`. From then on the site is closed until
   the Apply.
3. **Apply pending at once** (signed in as the ADMIN, on the Migrations page): one Apply runs 022 and 023, after a
   backup of the database as it was ("before-migration"):
   - 022 adds `builds.file_build` (every build's columns as they are), `site_notes` and `edited_at`, lets
     `checked_at` be empty (a build made here was in no file), and adds `build_removals` and `build_orders`; its
     gate refuses (rolled back whole) unless every `file_build` says what its build's columns say and no build or
     content hash changed;
   - 023 adds "Edit builds" (a character's builds page, for editors and admins) in English and Slovak, describes
     the notes' two panels anew, and rewords the Privacy page's sentence on what the audit log keeps - "builds
     imported from a file or edited by hand" - only where it still reads as before (a rewording made in the browser
     stays). (The release itself dates the legal pages 2026-10-23: `SITE_LEGAL_UPDATED`.)

Then check: the Builds page has the **Edit builds** card; picking a character there opens its editor
(`/admin/builds/genshin/<key>`) with a tab a build; a note written and saved shows on the character's builds page -
in Slovak with `?lang=sk` where it was written in Slovak; every member sees it at once, so write a real one, or delete
it again once seen -, and the audit log has `builds.edited` by its editor; the
next builds file's dry run asks only where the file changed what an edit changed too. **The backups made before this
Apply are restored offline only** (`409 schema_differs`, as after every Apply): make a backup by hand once it is
applied.

### Deploying 0.6.1

No migration: the release goes up (`bun run anotoki-deploy deploy --backups <folder> --go`) and the site stays open.
Then import the builds file the local tools wrote without the sites' notes and with every team (the second export of
2026-10-22): its dry run updates nearly every build - their notes and target wordings go, teams come (2026-10-22: 183
updated, 7 unchanged, none new or removed, 895 teams) -, then **Import**. Check: a character's builds page shows no
notes and its teams side by side on a phone; on an account's character page a teammate you have wears a check.

### Deploying 0.6.0

Version 0.6.0 gives every build the teams it is played in (the owner's request of 2026-10-22,
[decisions.md](decisions.md)): the builds file becomes format version 3, and its migrations 020 (the builds' `teams`
column) and 021 (the teams' words) only add. The local tools write version 3 files only from now on, and this site
reads no other, so the release goes up before the next builds file is imported:

1. **Have the builds file ready**: a `builds-genshin-<date>.json` of format version 3 ("Importing builds"; the
   version 2 files made before are refused from now on). Before uploading, **Export** each language on the
   Translations page (a copy of every rewording).
2. **The release**: `bun run anotoki-deploy deploy --backups <folder> --go`. From then on the site is closed until
   the Apply.
3. **Apply pending at once** (signed in as the ADMIN, on the Migrations page): one Apply runs 020 and 021, after a
   backup of the database as it was ("before-migration"):
   - 020 adds `builds.teams`, NULL for every build until the import: no build changes;
   - 021 adds the teams' three words (the section's heading, the line of a member's stand-ins, the panel of a team's
     notes) in English and Slovak,
     and widens the descriptions of the three words the teams reuse.
4. **Import the builds file** on the Builds page: the dry run's preview says the file's teams in its first line
   ("…, with 809 teams, made …") and updates only the builds that gained teams - the 2026-10-22 file: 147 updated,
   43 unchanged, none new or removed -, with no teammate the catalog lacks; then **Import**. The updated builds'
   "changed" date becomes the file's.

Then check: a character's builds page (`/builds/genshin/hutao`) lists each build's teams under "Teams", a teammate
with builds of its own a link to them; on an account's character page the teammates the account has are marked
"Owned" - with "needs C2" where the team asks more - and link to their own analysis; in Slovak the section reads
"Tímy". **The backups made before this Apply are restored offline only** (`409 schema_differs`, as after every
Apply): make a backup by hand once the import is in.

### Deploying 0.5.0

Version 0.5.0 stops syncing builds (they are imported as one file per game), drops Honkai: Star Rail and takes
the catalog from the owner's genshin.anotoki.eu (the owner's decisions of 2026-10-20, [decisions.md](decisions.md)).
Its migrations 014-019 change the data for good, so it goes up in this order, in one sitting:

1. **genshin.anotoki.eu first.** Deploy its release and apply its migrations `genshin_impact/030` (the game's own
   IDs: a `game_id` column) and `031` (the IDs filled in by name) on its Migrations page. Then check its catalog
   endpoint: `curl -i https://genshin.anotoki.eu/api/catalog/characters` answers 200 with an `ETag` and a JSON
   list, and the same request with `-H 'If-None-Match: <that ETag>'` answers 304; `/weapons` and `/artifacts`
   likewise. Without it this site's catalog cannot be refreshed ("The catalog").
2. **Have the builds file ready**: the newest `builds-genshin-<date>.json` the local tools wrote ("Importing
   builds"). Before uploading, **Export** each language on the Translations page (a copy of every rewording).
3. **The names file**: upload `config/neutral.local.php` once, by hand, beside the other settings - like
   `cloudflare.local.php`: gitignored, never in a release, never written or read by `setup.php`, and starting with
   `<?php` ("Configuration", "The names file").
4. **The release**: `bun run anotoki-deploy deploy --backups <folder> --go`. From then on the site is closed
   until the Apply.
5. **Apply pending at once** (signed in as the ADMIN, on the Migrations page): one Apply runs 014-019 together,
   after a backup of the database as it was ("before-migration"), each file refusing (rolled back whole) when what
   it proves does not hold:
   - 014 deletes every row of Star Rail - its game accounts with everything in them, its imports, its build sources
     with their builds and sync runs, its catalog - and narrows every `game` check to `genshin` (and the sets'
     `kind` to `artifact`);
   - 015 retires Star Rail's 56 keys (its import guides and kinds, the import wizard's Game step, the builds page's
     game switch) and has the six sentences that named it say Genshin Impact alone, where still as seeded;
   - 016 drops the old `builds`, `sync_runs`, `build_sources` and `cache_locks`, and makes the new `builds` (a
     character's variations: role, qualifier, the constellation it needs) and `build_imports`;
   - 017 retires the sources' and the syncs' 23 keys and adds the builds' own 18 (the roles, the constellation a
     build needs, the dashboard's builds), rewording the fifteen sentences that told of syncs where still as seeded;
   - 018 gives the characters their region, version and version name, clears the old game data provider's icon
     addresses and forgets where each catalog list stood (the first refresh reads every list in full);
   - 019 adds the builds browser's 14 filter words (`builds.filter.*`), in English and Slovak.
6. **Refresh catalog** on the admin panel's Builds page: the three lists, read in full - and the icons come back
   with it (018 cleared the old addresses).
7. **Import the builds file** on the same page: **Import a file**, read the dry run's preview (the counts, and
   the characters skipped - none while the catalog is as new as the file), then **Import**.

**Between the Apply and the first import the site has no builds** (016 dropped them, and the file brings them
back): a few minutes, if steps 5-7 are done together. Then check: the builds browser (`/builds/genshin`) lists the
characters with their roles and filters by element, weapon type, rarity, region and version; a character's page
has a tab per variation, one their constellation does not reach showing a lock and "needs C4"; an account's page
shows each character's best build; the Builds page lists the import under your name, and the audit log has
`catalog.refreshed` and `builds.imported`; `/admin/sources` leads to `/admin/builds`.

**The backups made before this Apply are restored offline only.** They hold Star Rail's data and the old builds
tables, and a restore of one would bring back what 014-016 removed; the Backups page lists them but does not
restore them in place (`409 schema_differs`: the database's migrations are not the ones they were made with). Read
what you need from one offline (`php backup.php --inspect=<id>`, `--extract=<id> <folder>`, then `psql \copy`),
and make a backup by hand once the import is in. An older `fetch.local.php` may keep its `cache_hours`,
`step_seconds` and `stale_hours`: they are no longer read.

### A database made by the first (Laravel) version

`001_adopt_laravel_database.sql` recognises a PostgreSQL database that the Laravel version created (its
`migrations` table has a `batch` column) and brings it forward in place: Laravel's own tables are kept aside
as `laravel_*` (dropped when empty), times become `TIMESTAMPTZ` with the same instants, accounts and imports
without an owner go to IAM user 1 (the owner), runs a queue worker left "running" are closed as failed, and a
verification gate compares the counts before and after. `002_schema.sql` then finishes the schema, so an
adopted database ends up identical to a new one (`tests/migrations.test.php` proves it for the three schema
versions that version had). Point `setup.php` at the old database and it does all of that; `migration.php`
does the same on the command line. The later migrations then carry it on like any other database - 014 deletes
its Star Rail accounts, 016 its builds and sync runs -, so import a builds file afterwards. The old SQLite database
of a local copy is not carried over: import the accounts again, and a builds file.

## Machine quirks (this Windows machine)

- The `php` on PATH has changed over time (PHP 7.4, then XAMPP's 8.2; from 2026-09-28 PHP 8.5 with its own
  php.ini: curl, fileinfo, intl, mbstring, openssl, pdo_pgsql, sodium, zip and a CA bundle; on 2026-10-20 PHP 7.4
  again). The project needs 8.4+: call the PHP 8.5 binary directly, or `backend/tools/php.sh`, which takes
  `BUILD_ANALYZER_PHP` and refuses anything older than 8.4; the browser check takes it as `PHP=<it>`.
- Outgoing requests use `composer/ca-bundle`'s certificates whatever php.ini says, so don't "fix" SSL errors by
  disabling verification.
- PHP 8.5 deprecates `$http_response_header`, `curl_close()` and the `(integer)` / `(boolean)` / `(double)`
  casts; the app and anotoki-lib use none of them. Keep it that way: the host runs 8.4, this machine 8.5.
- PostgreSQL 18 runs on 5432. The tests make throwaway databases on that server (`build_analyzer_test_*`) and
  drop them again; `setup.test.php` also a throwaway login (`build_analyzer_test_…_nocreate`), dropped at the
  end. The stand-in IAM of `tests/stand_in_iam.test.php` listens on a free port of 8590-8599 while it runs;
  the browser check uses 8590 (the site) and 8597 (the stand-in IAM), refuses to start when either answers,
  and makes and drops `build_analyzer_test_browser`. Its site reads its settings from the folder
  `BUILD_ANALYZER_TEST_CONFIG` names (outside the repository; honoured only for a `build_analyzer_test_`
  database) and writes its log there, never into `backend/storage`.
- playwright-core for the browser check lives in `%TEMP%/pw` (`npx -y npm@11 install --prefix <temp>/pw
  playwright-core`; `run.mjs` installs it when it is missing) and drives the installed Edge - nothing is
  installed into the repository. The browser's locale is set to `en-GB`: this computer's is Slovak.
- npm 10.9.2 crashes on this project (`Cannot read properties of null (reading 'edgesOut')`); use
  `npx -y npm@11 install|ci`.
- Bun 1.4 is on PATH: `npm start` in `frontend/` runs `bun ci` (from `bun.lock`) before `ng serve`; npm
  installs from `package-lock.json`, so a change of the dependencies updates both lock files.
- Node 22.15 → Angular 21 (Angular 22 needs Node ≥ 22.22.3).
- Python is not installed (`python` is the Windows Store stub): use node or PHP for scripting.
- Git Bash: heredocs containing apostrophes or backslashes can come out wrong → write files with the editor
  tools instead; `sed -i` converts CRLF files to LF, and `grep` does not see the CRs (check with `git ls-files
  --eol`). `backend/` is LF (its `.gitattributes`); the root's Markdown, `docs/` and `.claude/` are CRLF.
- A background `ng serve` can leave an orphan `node` process on port 4600 after stopping; free the port with
  `Get-NetTCPConnection -LocalPort 4600` → `Stop-Process`. The same for a PHP server on 8400.

## Troubleshooting

| Symptom | Where to look |
| --- | --- |
| "This site is not set up yet" / "The site is not available right now" with 503 `not_set_up` | a `config/*.local.php` is missing or incomplete; `storage/logs/app.log` says which; run `setup.php` (on a host whose `storage/installed.lock` is there the page never offers it: upload `setup.php` and delete the lock by FTP to run it again) |
| "The site is being updated" for everybody / 503 `update_pending` | a migration waits: sign in as an ADMIN (the page's Sign in), Open Migrations, Apply pending; on a computer with a shell `php migration.php` does the same |
| "The site is not available right now", no `not_set_up` | `/api/site-status` says `unavailable`: the database cannot be reached (the log has the error) |
| Apply answers 409 `busy` | another apply holds the lock (another tab, `migration.php`, setup): wait, then look again |
| No builds anywhere (the builds browser empty, no build on a character's page) | no builds file was imported since migration 016 (it dropped the old builds), or the last one held none of these characters: Import a file on the admin panel's Builds page ("Importing builds") |
| Builds getting old | the site never fetches builds: a newer file is made on the owner's computer (`local/`, [builds.md](builds.md)) and imported on the Builds page; its "Newest file made" says when the file in use was made |
| An import's result warns of an unknown character, weapon or set | the catalog lacks it, or a name does not resolve ([name-matching.md](name-matching.md)): refresh the catalog (Builds page) - an import by UID refreshed that list itself, at most once an hour - or add an alias, then import again |
| A builds file's import skipped characters ("Not in this installation's catalog") | the import refreshed the characters' list first (at most once an hour) and genshin.anotoki.eu does not list them yet, or the refresh failed (the catalog card says why): Refresh catalog later, then import the same file again |
| A builds file's import is refused: "None of the file's characters is in this installation's catalog" | the catalog is empty - never refreshed, or genshin.anotoki.eu could not be read: Refresh catalog, then import again |
| A builds file's import is refused (422) with the file's first problem | not a builds file, another game's, format version 2 (made before 0.6.0: builds without their teams) or 1 (a source's export from before 0.5.0), more than 5000 builds or one build or team that does not fit: nothing was written; make the file again with the local tools (they check it with the same reader) |
| A builds file's import answers 409 | another import is writing (another tab, another editor): wait, then press Import again - the preview stays |
| A builds file's import answers "No file arrived" | the file is larger than the host's PHP `post_max_size` (it arrives empty); a game's builds take about 1 MB (1.2 MB with their teams), so check the hosting panel's PHP settings |
| The Builds page's catalog card says "The last refresh failed" | genshin.anotoki.eu did not answer as it should: "closed for now" (HTTP 503 - its update or maintenance; 401 - it requires sign-in; 423 - the route is locked), "has no such list" (404 - its catalog endpoint is missing: its release and migrations 030-031), "could not be reached" or another HTTP code. This site keeps its catalog meanwhile; the upkeep tries again an hour later, Refresh catalog at once ("The catalog") |
| Refresh catalog answers 409 "This list is being refreshed" | another refresh of that list runs (the upkeep after an answer, another tab, an import): try again in a moment - a claim holds two minutes at most |
| `check-names.php` lists places | those files name a build site (or their paths do): reword them ("a build site") before committing - never add the name to a tracked file to "explain" it |
| `check-names.php` says `config/neutral.local.php` names nothing | the file is missing, empty, or passed over (does not start with `<?php`, prints, cannot be read - PHP's error log says why, once a day): put the owner's copy in `backend/config/` |
| An accounts file's import answers 422 `no_file` / `file_too_big` | the file is larger than the host's PHP `post_max_size` (it arrives empty) or than `me.file_max_mb`; an export of this site never writes more than `me.file_max_mb` |
| An accounts file's import answers 422 `bad_entry` `{path}` | the file was edited or is not whole: the message names the first entry that is not as the export writes it; export it again |
| An accounts file's import kept an account the file has newer data for | the copy here was imported later (or at the same second): the newer copy wins, by `last_import_at`; nothing is merged |
| Showcase import errors | the message comes from `EnkaClient` (UID, maintenance, 429); details hidden → the wizard's steps |
| "Sign in to continue." / 401 everywhere | token missing or refused: `storage/logs/app.log` has "IAM access token refused: <reason>" (issuer, audience, expiry, keys) |
| The builds browser and visitors answer 401 `sign_in_required` to everybody signed out | the IAM says sign-in is required here (`/api/meta`: `auth.login_required: true`): switched on in its admin panel, or the IAM answers 404 for `build-analyzer` (an IAM without its public route - migration 010 - or the application switched off), or it was never reached since the answer was last deleted. `storage/logs/app.log` has "IAM sign-in answer: <reason>" (`unknown_application`, `never_read`, `stale`); `curl {issuer}/api/public/applications/build-analyzer` shows what the IAM says. A switch takes up to a minute (delete `storage/cache/iam/anotoki-iam-application-*.json` to ask again at once) |
| 403 `editor_only` "Only an editor or an admin can do this." | a signed-in person without EDITOR or ADMIN asked for the admin panel: grant EDITOR in the IAM admin panel |
| 403 `admin_only` "Only an admin can do this." | an EDITOR asked for an admin's route (migrations, adding / deleting a language, People, the admin tools): grant ADMIN |
| 401 `visitor_unknown` | the browser's visitor key opens nothing: swept after 30 days unseen, moved into an account at sign-in, or not a key at all; the page forgets it and starts afresh. Also an import whose visitor was moved or swept while it ran: nothing was written |
| 401 `sign_in_required` "Your data here was deleted while this was being saved" | an admin deleted the person's data on the People page while their import ran: nothing was written, and the page's retry (with a fresh token) starts them afresh |
| 429 `too_many_visitors` | the visitor limits (`config/app.php` `visitors`): too many new visitors from one address or in all this hour, or too many at once. Many visitors refused together: Cloudflare may serve from a range missing from `client_address.trusted_proxies` (everybody through it then shares its address) - compare the list with https://www.cloudflare.com/ips-v4 and /ips-v6 |
| 415 `unsupported_media_type` "Without signing in, an import is sent as JSON." | an import by somebody with neither a token nor a visitor key that is not `application/json` - a form another page sent, never the site's own pages |
| 422 `visitor_limit` | a visitor reached its accounts or file size limit: signing in lifts it |
| 503 `visitors_unavailable` | migration 004 is not applied: Apply pending on the Migrations page (members are unaffected meanwhile) |
| 503 `visitor_move_retry` | the move at sign-in met an import still writing; the page asks again (`visitors.move_lock_timeout_ms`) |
| The dashboard says what this browser imported "is not kept here any more" | 401 `visitor_unknown`: the browser's visitor key opens nothing - another tab signed in and moved it, or it was unvisited for 30 days and swept. The page forgets the key and starts over |
| An editor sees no Languages, People, Migrations, Errors, Audit, Feedback or Backups link, no database updates | by design: those are an admin's (the family's `adminGuard` sends an editor to `/admin/builds`; the server refuses with 403 `admin_only`) |
| "This part is for editors and admins" in the admin panel | the account has no EDITOR or ADMIN role here: grant one in the IAM's admin panel |
| 401 although the person is signed in | the token the page sent was refused (`invalid_token`: expired, another IAM): the page refreshes it once, for the same person; the error log keeps no 401 - `storage/logs/app.log` says why (`[anotoki auth] token refused`) |
| "Signing in did not work" on `/sign-in` | the sign-in came back without one, or could not start; the screen says why from the failure's code alone ("not completed": the person went back from the IAM's page, or the IAM turned the account away - grant MEMBER there; "took too long or did not start here": an old tab; "the browser does not let the site remember a sign-in": site data blocked). Try again, or Back to where it started |
| Signed in at the IAM, but not signed in here at once | the hint's one silent try came back without a sign-in, and is not tried again for that hint (a minute at least between tries): sign in with the button. The IAM's hint must be set for the site's domain (the IAM's `hint_cookie_domain`), and in development the site opened as `localhost` |
| "Back up now" says "Sign in again first" | the sign-in is older than ten minutes: Sign in again (back to the Backups page), then once more |
| "Cannot reach the server" in the UI | API not running on localhost:8400 (the Angular dev server proxies `/api`) |
| Icons missing | `/api/icons/…` could not fetch the image: `storage/icons/` writable? genshin.anotoki.eu reachable? the catalog refreshed since migration 018 (it cleared the old provider's addresses)? The icons are AVIF, genshin.anotoki.eu's format: a browser without AVIF shows none |
| Headless screenshots blank | timing only; see the `verify-ui` skill |
| The browser check says "Port 8590 is in use" | a check that did not end, another check (of another site), or somebody's server: look at the process's command line before stopping it, or run it on other throwaway ports (`SITE_PORT=8593 IAM_PORT=8596`) |
| "Stopped at NNN_….sql" on the Migrations page | that file failed and was rolled back whole (the files before it are applied); the page shows the database's error, `storage/logs/app.log` has it too. Fix the cause (or the file, if it was never applied anywhere) and press Apply pending again |
| The bar's words show as keys (`topbar.dashboard`) right after an upload | migration 004 is not applied: Apply pending on the Migrations page (an ADMIN) |
| A key (`dashboard.title`) shows where a word should be | the database lacks that string: a migration with it waits (Migrations page → Apply pending), or the key is new and its migration was not written; `php translations.php --status` lists the frontend's keys the database lacks |
| Everything in English although Slovak is chosen; the Translations and Languages pages say to apply an update first | 503 `translations_unavailable`: migration 003 is not applied yet - Apply pending on the Migrations page (the Languages page, and for an admin the Translations page, link it) |
| A language is missing from the top bar's language menu | it is hidden: offer it on the Languages page |
| No language button in the top bar | only one language is offered, or the page is in the admin panel (English) |
| A reworded string is back to the old words | the database was replaced, or this is the other installation: each keeps its own strings; import the language's exported file |
| Signed in, the theme or the language is not what this browser chose before, or changes by itself | they are the account's (the IAM's migration 015): changed on the IAM's account page or on another anotoki site, they follow here; a choice in the bar is saved to the account. A visitor's stay the browser's |
| The theme flips back after the toggle, with "Cannot reach the server" / "Your sign-in has ended" | the account did not take it: the IAM was out of reach (its CORS, a network fault) or the sign-in ended - the account's theme is shown again. A language chosen then stays for the visit only |
| What's new's dates read 2026-10-06 (or 6.10.2026) | the account's date order, chosen on the IAM's account page: every anotoki site writes dates in it |
