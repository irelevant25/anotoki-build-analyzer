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
| Sync builds | Sources page "Sync all" / "Sync now" (EDITOR) | `cd backend && php sync-builds.php [source] [--force] [--character=x] [--check-names]` |
| Check parsers without writing (offline) | – | `cd backend && php sync-builds.php --dry-run --cache-hours=96 [-v]` |
| Catalog | Sources page "Refresh catalog" (EDITOR) | `cd backend && php sync-catalog.php [genshin\|hsr] [--force]` |
| Import from the command line | – | `cd backend && php import-account.php genshin 700123456 --user=ID` or `… hsr path/to/file.json --user=ID --account=3` (`--user` required) |
| The site's words | the Translations page (EDITOR) and the Languages page (adding, renaming, switching, deleting: ADMIN) | `cd backend && php translations.php --status` (the frontend's keys against the database's), `--export=sk [file]`, `--import=sk file` |
| Backups | the admin panel's Backups page (ADMIN) | `cd backend && php backup.php --list \| --create ["why"] \| --inspect=<id> \| --extract=<id> <folder> \| --restore=<id> --confirm=<last 6>` |
| What failed, what was done, what visitors wrote | the admin panel's Errors, Audit and Feedback pages (ADMIN) | `storage/logs/errors-*.jsonl` (one JSON line each); the tables `anotoki_audit_log` and `anotoki_feedback` |
| Tests | – | `cd backend && php tests/run.php` and `cd frontend && npx ng test --watch=false` |
| The browser check | – | `node .claude/skills/verify-ui/scripts/run.mjs [--no-build] [--keep] [--measure]` (the `verify-ui` skill: a throwaway database, the stand-in IAM on 8597, the site on 8590) |
| Frontend build | – | `cd frontend && npx ng build` (then the API server alone serves everything on :8400) |
| Build for the host | – | `cd backend && php tools/build.php [--skip-frontend]` (into `dist/` at the repo root) |
| Look at the data | – | `psql` against the database of `config/database.local.php`, or a scratch PHP file using `App\Cli::app()->db()` |

`backend/tools/php.sh` runs PHP 8.4+ from `$BUILD_ANALYZER_PHP`, else the `php` on PATH, and refuses older
versions - for machines whose `php` on PATH is too old. AI tool shells reset their working directory, so start
commands with `cd <repo root> &&`.

There is **no queue worker and no scheduler**: nothing runs unless someone starts it. Syncs run in steps (see
below), and the page cache (`storage/http-cache/`) is pruned (files older than 3 days) when a build run ends.
Ordinary traffic carries the rest, after its answer (`api/middleware/upkeep.php`; `config/app.php` `upkeep`
switches each off): the scrub of the accounts the IAM deleted (at most hourly) and the day's backup.

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
| `fetch.local.php` (optional) | `user_agent` (how requests identify the app; `build-analyzer/1.0`, and setup writes the site's address into it), `delay_ms` (between two requests to one site, 1200), `cache_hours` (the page cache, 12), `step_seconds` (one sync step, 20, at least 12), `stale_hours` ("Sync all" skips sources synced within it, 20); `timeout` (30), `browser_user_agent` |

**Every one of them is read cleanly or not at all** (`App\Config::readLocalFile()` - the anotoki family's rule of
2026-10-13, and its first five bytes since 2026-10-18; [decisions.md](decisions.md)), the ones written by hand -
`client_address.local.php`, `cloudflare.local.php` (below) - and the committed `app.php` too: a file that does not
start with `<?php` (saved with a byte-order mark, a blank line or a space before it, or without that line) is
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
| `cloudflare.local.php` | a secret no request carries: it fails closed (see "Which address", below) |

`setup.php` reads its files the same way: on this computer it names a broken one by its fault (never a value), and
asks for every field again - the password too - since it shows none of that file's values and keeps none of its
password; on a host it shows nothing of any file.

Run `setup.php` again to change the database or the IAM (on this computer it can be run any number of times
and shows the saved values, never the password; on a host it shows none), or edit the files. Setup writes
`fetch.local.php` with the defaults only when there is none, and leaves one that is there as it is: edit it by
hand. `config/app.php` is committed and holds what is the same
everywhere: the build sources (in "Source N" order), the catalog provider and its refresh intervals, the showcase
service and its per-person rate limit, the file imports' rate limit, a person's accounts file (`me`: 5
imports a minute, files up to 8 MB), the visitors' limits and clean-up
(`visitors`), which address a request counts as (`client_address`), and the name aliases.

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
`127.0.0.1`. Search engines never get the cookie; `robots.txt` keeps them off `/auth/`, and the callback and the
failure screen say `noindex`. A sign-in that came back without one is said on `/sign-in` (the library's screen,
in the site's frame: why, from the failure's code alone - Try again, Back).

**Whether sign-in is required is the IAM's switch**, not a setting here: the IAM's admin panel, Applications ->
Build Analyzer -> Settings -> "Sign-in required". The server reads it from the IAM (`GET
{issuer}/api/public/applications/build-analyzer`, through the library's `SignInRule`) and keeps the answer a minute in
`storage/cache/iam/`, so a switch reaches the site within a minute; while the IAM is out of reach it uses the
last answer, and with none ever read - or a 404, an application the IAM does not know or switched off - sign-in
is **required** (fail closed). `/api/meta` relays it to the page (`auth.login_required`), which then sends
everybody not signed in to the IAM before any page (and keeps a visitor key it holds, unsent). Required: the table's
first two rows shrink to `/api/meta`, the icons, a language's strings and `/api/site-status` (which reads no
credentials, so the pages always learn where the site stands) - the builds browser, the sources'
list and every visitor key answer 401 `sign_in_required`, and no visitor is made; a visitor's data waits (the
clean-up still deletes it 30 days after the last visit), and signing in still moves it into the account.
**Deploy this version only after the IAM serves that address** (IAM migration 010 and its public route):
before that the IAM answers 404 and the site requires sign-in - visitors turned away. Check with `curl
https://iam.anotoki.eu/api/public/applications/build-analyzer` (200, `"login_required": false`).

| Who | May |
| --- | --- |
| nobody (no token) | `/api/meta`, the icons, a language's strings, `/api/site-status`; while sign-in is not required also the builds browser (`/api/builds/*`), the sources' list (`GET /api/sources`, without who started a run); an import, which makes them a visitor |
| a visitor (no token, this browser's `X-Visitor-Key`; only while sign-in is not required) | its own accounts and imports, as a person's - nothing else (no accounts file: sign in first) |
| a signed-in person (anybody the IAM lets sign in here; `MEMBER`, which every account holds, or no role) | their own accounts and imports, and their accounts as a file (`/api/me/export`, `/api/me/import`) |
| `EDITOR` | also: syncs and the catalog, the sync history, a source's builds file (export / import), the strings (the Translations page, a language's file), the languages' list |
| `ADMIN` | also: switching a source on or off, applying database updates, adding, renaming, switching and deleting a language, the People routes (list people, delete a person's data, check the IAM's deleted accounts now), and the admin tools - the error log, the audit log, feedback, backups (an editor sees none of them) |

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
  `person.data_deleted`). The syncs they started and the strings they saved stay, by nobody - the syncs' logs say
  "a deleted person" where they named them -, and what they sent through the feedback form stays without the
  address and browser it was sent with; their anotoki account is not touched, and signing in again starts them
  afresh.
- **The IAM's deleted accounts are scrubbed here** (the library's people module): after an answer, at most once an
  hour (`config/app.php` `upkeep.people`), the site reads the accounts the IAM deleted (thirty days after their
  owners asked) and makes each one nobody's here - its row `deleted-{id}` with `anonymized_at` set, a sync's log
  "a deleted person", their feedback's address and their name in the audit log's details blanked; their game
  accounts and imports stay, nobody's now. The People page says when the IAM was last asked; Check now asks at
  once (`POST /api/admin/people/sync`). A scrubbed row stays nobody's: no request ever writes a name back into
  it, nor makes a new row for an id the IAM deleted.
- The verifier caches the IAM's keys in `storage/cache/iam/`, and the reader the IAM's sign-in answer
  (`anotoki-iam-application-<sha1>.json`). The folder must be the site's own - a kept "not required" is what
  opens the site to visitors; the upload denies `storage/` to the web (`tools/build.php`), and setup checks it. Deleting the file makes the
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
  there; changing the secret, likewise. Without the file - or with one read cleanly that names no secret: a value
  that is not a string with something in it, another key, no array - the site believes `CF-Connecting-IP` from the
  trusted proxies alone, as before (said in PHP's error log once a day, by the file's name). **A file that is there
  but cannot be read cleanly fails closed** (the family's rule, as the IAM's `iamOriginSecret()` keeps it; this
  site's since 2026-10-18): one that does not start with `<?php` (a byte-order mark, a blank line, no `<?php` line - never
  compiled), one that prints, one PHP cannot read or run stands for a secret no request carries, so every request
  from Cloudflare's ranges counts as the edge it came from - the file's own value too - until it is mended. The
  file is optional: the site keeps running either way, and PHP's error log says so once a day, by the file's fault,
  never its text. Never "no secret" there: that would believe `CF-Connecting-IP` from every Cloudflare customer's
  Worker. The secret is never shown or logged.
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

## Syncing builds and the catalog

An editor (or an admin) presses **Sync all** (every enabled source not synced successfully in the last `stale_hours`, 20),
**Sync now** on one source (always) or **Refresh catalog** on the Sources page. That creates sync runs; the
page then asks `POST /api/sync/step` again and again. Each step is one short request (at most `step_seconds`,
20 s; never less than 12, so one request always fits) that fetches a page or two - Prydwen's 10 s crawl delay
means two pages at most - saves its progress and says how long to wait. Closing the tab pauses the runs;
**Resume** (on any Sources page) continues where they stopped, and **Cancel** ends one. A stale game catalog is
refreshed before that game's build runs. A full sync takes about 20 minutes per Prydwen game and needs the tab
open; in a background tab the browser may slow the loop down.

`sync-builds.php` and `sync-catalog.php` start the same runs and loop over the same steps, sleeping through the
waits; they join a run the browser already started. Only one step runs at a time (tabs and the command line
take turns), and each site's delay between requests holds across all of them.

## Moving builds between installations

Each source on the Sources page has **Export** and **Import** (editors and admins; both wait while that source syncs).

- **Export** saves the source's builds as a file, `builds-genshin-2-2026-09-29.json` (the format:
  [data-model.md](data-model.md), "Builds files"). It holds every build of the source but not the addresses of
  the pages they were read from. Keep it as a backup, or take it to another installation.
- **Import** takes such a file of the same source (same key, same game) and makes the source's builds exactly
  the file's, after the page has asked: builds are created, updated or kept as a sync would, and builds the
  file does not have are removed. It is recorded as a run of the source ("imported from a file exported …"),
  and the source counts as synced when the file's builds were last synced in full (or, when that sync did not
  succeed, when the file was exported), so "Sync all" leaves it alone for `stale_hours` like any source synced
  then. The page refuses a file over 8 MB before sending it (a common default of PHP's limit on a request; a
  whole source's file takes about 2 MB).
- A character this installation's catalog lacks is skipped and named (in the answer and the run's log):
  **Refresh catalog**, then import the same file again. A file none of whose characters the catalog knows is
  refused.
- A file of another source or game, a file that is not a builds file and a file with any malformed build are
  refused whole (422, naming the first problem), and nothing is written. While a sync of the source is going
  the import is refused too (409): let the sync finish, or cancel it.

**A source the host cannot reach.** Some sites turn away requests from hosting servers (bot protection against
datacenter addresses) but answer a computer at home. Sync that source on your own computer (a copy set up as in
[DEV.md](../DEV.md), signed in through your local IAM), export it there and import the file on the site;
repeat when you want fresher builds. Leave the source switched on there - the analysis uses the builds of
switched-on sources only. Once the import is older than `stale_hours`, "Sync all" on the site tries the source
itself again; when the site still refuses, that run fails without touching the imported builds.

## A person's own accounts as a file

Every signed-in person (any role or none; never a visitor, who signs in first) can save their game accounts as a
file and bring them back - into this installation or another: `GET /api/me/export` and
`POST /api/me/import` - the "Your data" card on the dashboard (Export; Import a file, its preview, Confirm;
a file over 8 MB is refused before it is sent). The format and every rule:
[data-model.md](data-model.md), "Accounts files"; why the newer copy wins and nothing is deleted:
[decisions.md](decisions.md).

- **Export** saves `build-analyzer-accounts-2026-10-04.json`: every account with its characters, weapons,
  artifacts / relics and its newest 200 import runs - keys and neutral words only, no ids. Keep it as a backup,
  or import it on another copy.
- **Import** asks first (a dry run: what would be added, replaced by a newer copy, kept, and how many entries
  this copy's catalog does not know), then imports. It never deletes an account: an account of the file that is
  not here is added; one that is here is replaced only when the file's copy was imported later; the rest stays
  as it is. The same file twice changes nothing.
- **Limits** (`config/app.php` `me`): 5 imports a minute per person, dry runs included (429
  `too_many_imports`); a file of at most `file_max_mb` (8). The export refuses rather than write a file larger
  than that (422 `export_too_big`) - a few thousand artifacts take well under 2 MB. Keep `file_max_mb` at or
  below the host's PHP `post_max_size`: a larger body arrives empty ("No file arrived").
- Entries this copy's catalog lacks (a character newer than its last catalog refresh) are left out and counted.
  Have an editor refresh the catalog (Sources page) before importing: the same file imported again afterwards
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
  site's own log of what it did (syncs, imports, the visitors' clean-up).
  **The errors the pages meet in the browser** are in it too (the owner's decision of 2026-10-11, every site):
  the library's reporter (`@anotoki/lib/error-report`, the app's `ErrorHandler`) scrubs each - the page's path
  alone, no address, token, secret or value the browser keeps - and sends it to `POST /api/client-errors`
  (`ErrorLogRoutes::client()`), which redacts it again and writes it as `source: client`: at most five a page
  load, each once, never an HTTP failure. The route is public, behind the site's rule for a public route (a
  signed-in person known by their token; while the IAM requires sign-in here, anybody not signed in refused) and
  closed while an update waits; it takes 20 an hour from one sender - a person by their account, anybody else by their address
  bucket - and 500 a day from the visitors, 500 from the people signed in (429 `too_many`), counted by keyed
  hashes in `storage/logs/client-errors.json`.
- **Audit log** (the table `anotoki_audit_log`): what was done in the admin panel and by whom - sources switched,
  syncs started and cancelled, a source's builds imported, strings saved and languages changed, a person's data
  deleted, the IAM's deleted accounts checked, migrations applied, the error log cleared, feedback marked and
  deleted, backups made, downloaded, deleted and restored. No address is kept. A restore keeps it as it is.
- **Feedback** (the table `anotoki_feedback`): what visitors send from **Feedback** at the foot of every page - a
  bug, an idea or anything else, about one of the site's places (Dashboard, Import, Accounts, Builds). Nobody is
  mailed: the bar counts what is new beside Feedback. Who sent it comes from their token alone; for somebody signed
  in the page writes their account's address into the form's address field, and the address they leave there -
  none once they cleared it - is the only one kept, unless they tick "send it without my name" (no id, no
  address): their choice (the owner's decision of 2026-10-11). Nothing on the server fills an empty field: the
  library keeps only what the form sends (0.5.0). Kept with a message: the page and the language it was
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
  every table back but the audit log and the counters (`cache`, `cache_locks`), after a backup of the database as
  it was. What it must not bring back, it does again (anotoki-lib 0.5.0, `People::restoreHooks()`):
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

  **Only a backup made here is restored here** (0.5.0): every backup is sealed - an HMAC of its id and its
  SHA-256 under a key derived from this installation's `storage/anotoki-lib.key` - and the page and `php
  backup.php --restore` restore a sealed one alone, hashing the very bytes they read. One brought from another
  installation (downloaded from the host and put into this computer's `storage/backups/`, sealed under the
  host's key), one changed since, and **every backup made before 0.5.0** (they have no seal) are listed but not
  restored here (`422 archive_invalid`): they are read offline - `php backup.php --inspect=<id>` and
  `--extract=<id> <folder>`, then `psql \copy`. So is one made while `storage/anotoki-lib.key` could be neither
  read nor made (a storage folder PHP cannot write): the backup is still made, but unsealed - the error log says
  so, and the list shows it not restorable here. Nothing is public yet, so none of those is needed: make a backup
  right after the update. The seal keeps out a file put into `storage/backups` by whoever cannot reach the key;
  whoever can write the whole storage folder can make the key their own. The backups' files are the site's own
  user's alone (`0600`, folders `0700`). The list names each backup's maker (the "By" column).

  **Backups have no maximum age** (the owner's decision of 2026-10-11): a backup is kept until it is
  deleted - a manual one only by the owner (the page flags one older than 90 days, and deletes nothing), the
  automatic ones by the rotation by count (the newest 14 daily, 5 before an update, 5 before a restore). So a
  person the IAM deleted, and the data of a person deleted on the People page, stay in the backups made before
  until those backups go - delete them by hand when that matters. Download one now and then, and keep it off the
  host.

## Deploying (FTP-only shared hosting)

The host: Apache with `.htaccess` support (`mod_rewrite`), **PHP 8.4** (the scrapers need PHP 8.4's
`Dom\HTMLDocument`) with `pdo_pgsql`, `openssl`, `mbstring`, `curl`, `dom` and `zlib` (the backups compress with
it), and a PostgreSQL database made in the hosting panel. No shell, cron, queue worker or long-running process is needed. The steps, with the upload
map, are in [DEV.md](../DEV.md) ("Deploying"); in short:

1. `cd backend && php tools/build.php` assembles `dist/build-analyzer/` - the whole web root:
   the Angular build, `api/`, `src/`, `config/app.php`, `migrations/`, `vendor/`, an empty `storage/` and the
   `.htaccess` files - and `dist/setup.php`. It refuses a build with a `*.local.php`, a log, a Laravel component
   or a frontend that names another site.
2. Upload `dist/build-analyzer/*` into the site's web root (and, the first time, `dist/setup.php` beside it).
   `config/` and `storage/` must be writable for the web server.
3. First install: open `https://builder.anotoki.eu/setup.php`, read the token from
   `config/setup-token.local.php` over FTP, fill in the database and the IAM, test both, press "Set up". It
   checks the requirements and that `storage/` is not served, builds the database, writes the config files
   (owner-only where PHP runs as the FTP account), writes `storage/installed.lock` and deletes the token and
   itself.
4. Sign in with the ADMIN account, press **Refresh catalog**, then **Sync all**, and keep the tab open.
5. A new version: upload `dist/build-analyzer/*` again (FTP never deletes remote files; old hashed `*.js` are
   harmless). **While a new migration waits the site is closed** (the site gate - the anotoki family's one
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
     (one made on this computer, or before 0.5.0, cannot: it is read offline); `https://builder.anotoki.eu/robots.txt`
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
     facts are the code's (`frontend/src/app/core/legal-facts.ts`): a change to what the site keeps, or for how
     long, changes their `legal.*` strings by a migration and `SITE_LEGAL_UPDATED`.
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

### A database made by the first (Laravel) version

`001_adopt_laravel_database.sql` recognises a PostgreSQL database that the Laravel version created (its
`migrations` table has a `batch` column) and brings it forward in place: Laravel's own tables are kept aside
as `laravel_*` (dropped when empty), times become `TIMESTAMPTZ` with the same instants, accounts and imports
without an owner go to IAM user 1 (the owner), runs a queue worker left "running" are closed as failed, and a
verification gate compares the counts before and after. `002_schema.sql` then finishes the schema, so an
adopted database ends up identical to a new one (`tests/migrations.test.php` proves it for the three schema
versions that version had). Point `setup.php` at the old database and it does all of that; `migration.php`
does the same on the command line. The old SQLite database of a local copy is not carried over: import the
accounts again and sync (cached pages make it quick).

## Machine quirks (this Windows machine)

- The `php` on PATH has changed over time (PHP 7.4, then XAMPP's 8.2; since 2026-09-28 PHP 8.5 with its own
  php.ini: curl, fileinfo, intl, mbstring, openssl, pdo_pgsql, sodium, zip and a CA bundle). The project needs
  8.4+: `backend/tools/php.sh` takes `BUILD_ANALYZER_PHP` and refuses anything older than 8.4.
- Outgoing requests use `composer/ca-bundle`'s certificates whatever php.ini says, so don't "fix" SSL errors by
  disabling verification.
- PHP 8.5 deprecates `$http_response_header`, `curl_close()` and the `(integer)` / `(boolean)` / `(double)`
  casts; the app and the vendored IAM SDK (v1.1.0) use none of them. Keep it that way: the host runs 8.4,
  this machine 8.5.
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
| Source status `failed` / `partial` | Sources page run log or `sync_runs.log`; use the `debug-build-sync` skill |
| Builds getting old, no failed runs | nobody synced: syncs only run when an editor starts them (Sources page) |
| A sync shows "paused" | no Sources page is stepping it: press Resume (runs keep their place indefinitely) |
| Parser change: did it break anything? | `php sync-builds.php --dry-run --cache-hours=96` → expect only intended `changed` |
| `! unknown weapon/set` lines, "unmatched" in import summaries | [name-matching.md](name-matching.md) → add an alias, or refresh the catalog |
| An import skipped a new character / set | the catalog lacks it: Refresh catalog (Sources page), then import again |
| New character missing builds | released? `php sync-builds.php --check-names`; the site may not have it yet |
| A source always fails on the host (HTTP 403) but syncs at home | its site refuses the host: sync it at home, Export, and Import on the site ("Moving builds between installations") |
| A builds file's import skipped characters ("not in the catalog") | this catalog is older than the file: Refresh catalog on the Sources page, then import the same file again |
| A builds file's import answers "No file arrived" | the file is larger than the host's PHP `post_max_size`; a whole source takes about 2 MB, so check the hosting panel's PHP settings |
| An accounts file's import answers 422 `no_file` / `file_too_big` | the file is larger than the host's PHP `post_max_size` (it arrives empty) or than `me.file_max_mb`; an export of this site never writes more than `me.file_max_mb` |
| An accounts file's import answers 422 `bad_entry` `{path}` | the file was edited or is not whole: the message names the first entry that is not as the export writes it; export it again |
| An accounts file's import kept an account the file has newer data for | the copy here was imported later (or at the same second): the newer copy wins, by `last_import_at`; nothing is merged |
| Showcase import errors | the message comes from `EnkaClient` (UID, maintenance, 429); details hidden → the wizard's steps |
| "Sign in to continue." / 401 everywhere | token missing or refused: `storage/logs/app.log` has "IAM access token refused: <reason>" (issuer, audience, expiry, keys) |
| The builds browser and visitors answer 401 `sign_in_required` to everybody signed out | the IAM says sign-in is required here (`/api/meta`: `auth.login_required: true`): switched on in its admin panel, or the IAM answers 404 for `build-analyzer` (an IAM without its public route - migration 010 - or the application switched off), or it was never reached since the answer was last deleted. `storage/logs/app.log` has "IAM sign-in answer: <reason>" (`unknown_application`, `never_read`, `stale`); `curl {issuer}/api/public/applications/build-analyzer` shows what the IAM says. A switch takes up to a minute (delete `storage/cache/iam/anotoki-iam-application-*.json` to ask again at once) |
| 403 `editor_only` "Only an editor or an admin can do this." | a signed-in person without EDITOR or ADMIN asked for the admin panel: grant EDITOR in the IAM admin panel |
| 403 `admin_only` "Only an admin can do this." | an EDITOR asked for an admin's route (sources on / off, migrations, adding / deleting a language, People): grant ADMIN |
| 401 `visitor_unknown` | the browser's visitor key opens nothing: swept after 30 days unseen, moved into an account at sign-in, or not a key at all; the page forgets it and starts afresh. Also an import whose visitor was moved or swept while it ran: nothing was written |
| 401 `sign_in_required` "Your data here was deleted while this was being saved" | an admin deleted the person's data on the People page while their import ran: nothing was written, and the page's retry (with a fresh token) starts them afresh |
| 429 `too_many_visitors` | the visitor limits (`config/app.php` `visitors`): too many new visitors from one address or in all this hour, or too many at once. Many visitors refused together: Cloudflare may serve from a range missing from `client_address.trusted_proxies` (everybody through it then shares its address) - compare the list with https://www.cloudflare.com/ips-v4 and /ips-v6 |
| 415 `unsupported_media_type` "Without signing in, an import is sent as JSON." | an import by somebody with neither a token nor a visitor key that is not `application/json` - a form another page sent, never the site's own pages |
| 422 `visitor_limit` | a visitor reached its accounts or file size limit: signing in lifts it |
| 503 `visitors_unavailable` | migration 004 is not applied: Apply pending on the Migrations page (members are unaffected meanwhile) |
| 503 `visitor_move_retry` | the move at sign-in met an import still writing; the page asks again (`visitors.move_lock_timeout_ms`) |
| The dashboard says what this browser imported "is not kept here any more" | 401 `visitor_unknown`: the browser's visitor key opens nothing - another tab signed in and moved it, or it was unvisited for 30 days and swept. The page forgets the key and starts over |
| An editor sees no Languages, People, Migrations, Errors, Audit, Feedback or Backups link, no source switch, no database updates | by design: those are an admin's (the family's `adminGuard` sends an editor to `/admin/sources`; the server refuses with 403 `admin_only`) |
| "This part is for editors and admins" in the admin panel | the account has no EDITOR or ADMIN role here: grant one in the IAM's admin panel |
| 401 although the person is signed in | the token the page sent was refused (`invalid_token`: expired, another IAM): the page refreshes it once, for the same person; the error log keeps no 401 - `storage/logs/app.log` says why (`[anotoki auth] token refused`) |
| "Signing in did not work" on `/sign-in` | the sign-in came back without one, or could not start; the screen says why from the failure's code alone ("not completed": the person went back from the IAM's page, or the IAM turned the account away - grant MEMBER there; "took too long or did not start here": an old tab; "the browser does not let the site remember a sign-in": site data blocked). Try again, or Back to where it started |
| Signed in at the IAM, but not signed in here at once | the hint's one silent try came back without a sign-in, and is not tried again for that hint (a minute at least between tries): sign in with the button. The IAM's hint must be set for the site's domain (the IAM's `hint_cookie_domain`), and in development the site opened as `localhost` |
| "Back up now" says "Sign in again first" | the sign-in is older than ten minutes: Sign in again (back to the Backups page), then once more |
| "Cannot reach the server" in the UI | API not running on localhost:8400 (the Angular dev server proxies `/api`) |
| Icons missing | `/api/icons/…` could not fetch the image: `storage/icons/` writable? the provider reachable? |
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
