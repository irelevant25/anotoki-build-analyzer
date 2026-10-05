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

## Configuration

Everything that differs between installations is in files in `backend/config/`, written by `setup.php` (PHP
files returning arrays; gitignored, never committed, never uploaded from here). `database.local.php` and
`auth.local.php` are required: while one is missing or incomplete the API answers every request
`503 {"code": "not_set_up"}` and the app shows its "Not set up yet" page. `fetch.local.php` is optional: each
value it leaves out (or all of them, without it) is `Config::FETCH_DEFAULTS`'.

| File | Keys |
| --- | --- |
| `database.local.php` | `host`, `port`, `database`, `username`, `password`, `persistent` |
| `auth.local.php` | `issuer` (the IAM's address), `client_id` (`build-analyzer`); optional `jwks_file` (a key set on disk instead of the IAM's, for offline work) and `leeway` (seconds, 60). A `mode` or `local_user_id` an older setup wrote is ignored: sign-in is the IAM's, always |
| `fetch.local.php` (optional) | `user_agent` (how requests identify the app; `build-analyzer/1.0`, and setup writes the site's address into it), `delay_ms` (between two requests to one site, 1200), `cache_hours` (the page cache, 12), `step_seconds` (one sync step, 20, at least 12), `stale_hours` ("Sync all" skips sources synced within it, 20); `timeout` (30), `browser_user_agent` |

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

People sign in at the anotoki IAM - on a host and on this computer alike; there is no other way in. Who a
request is comes only from its verified access token (`src/Auth/IamGuard.php`); a request that sends an
`Authorization: Bearer` header is that person or it is refused (401 `sign_in_required`), on public routes too
(another scheme - `Basic`, from a host behind a password file - counts as no header).

**Whether sign-in is required is the IAM's switch**, not a setting here: the IAM's admin panel, Applications ->
Build Analyzer -> Settings -> "Sign-in required". The server reads it from the IAM (`GET
{issuer}/api/public/applications/build-analyzer`, through the vendored SDK) and keeps the answer a minute in
`storage/cache/iam/`, so a switch reaches the site within a minute; while the IAM is out of reach it uses the
last answer, and with none ever read - or a 404, an application the IAM does not know or switched off - sign-in
is **required** (fail closed). `/api/meta` relays it to the page (`auth.login_required`), which then sends
everybody not signed in to the IAM before any page (and keeps a visitor key it holds, unsent). Required: the table's
first two rows shrink to `/api/meta`, the icons and a language's strings - the builds browser, the sources'
list and every visitor key answer 401 `sign_in_required`, and no visitor is made; a visitor's data waits (the
clean-up still deletes it 30 days after the last visit), and signing in still moves it into the account.
**Deploy this version only after the IAM serves that address** (IAM migration 010 and its public route):
before that the IAM answers 404 and the site requires sign-in - visitors turned away. Check with `curl
https://iam.anotoki.eu/api/public/applications/build-analyzer` (200, `"login_required": false`).

| Who | May |
| --- | --- |
| nobody (no token) | `/api/meta`, the icons, a language's strings; while sign-in is not required also the builds browser (`/api/builds/*`), the sources' list (`GET /api/sources`, without who started a run); an import, which makes them a visitor |
| a visitor (no token, this browser's `X-Visitor-Key`; only while sign-in is not required) | its own accounts and imports, as a person's - nothing else (no accounts file: sign in first) |
| a signed-in person (anybody the IAM lets sign in here; `MEMBER`, which every account holds, or no role) | their own accounts and imports, and their accounts as a file (`/api/me/export`, `/api/me/import`) |
| `EDITOR` | also: syncs and the catalog, the sync history, a source's builds file (export / import), the strings (the Translations page, a language's file), the languages' list |
| `ADMIN` | also: switching a source on or off, applying database updates, adding, renaming, switching and deleting a language, the People routes (list people, delete a person's data) |

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
- **People** (ADMIN): `GET /api/admin/people` lists everybody the IAM has signed in here, with their accounts
  and imports; `DELETE /api/admin/people/{id}` deletes a person's data - their accounts with everything
  imported into them and their import runs. The syncs they started and the strings they saved stay, by nobody;
  their anotoki account is not touched, and signing in again starts them afresh.
- The verifier caches the IAM's keys in `storage/cache/iam/`, and the reader the IAM's sign-in answer
  (`anotoki-iam-application-<sha1>.json`). The folder must be the site's own - a kept "not required" is what
  opens the site to visitors; the upload denies `storage/` to the web (`tools/build.php`), and setup checks it. Deleting the file makes the
  next request ask the IAM again.

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
- **Which address**: `REMOTE_ADDR`; an IPv6 address counts by its /64. Behind a proxy such as Cloudflare
  every visitor would share the proxy's few addresses: list its ranges in `client_address.trusted_proxies`
  (CIDR, v4 and v6) and its header in `client_address.client_ip_header` (`CF-Connecting-IP`) - believed only on
  connections from those ranges. `X-Forwarded-For` alone is never read. The header should be one the proxy
  writes whole, overwriting what the visitor sent, with one address (`CF-Connecting-IP` is); set to a list a
  proxy appends to (`X-Forwarded-For`), it is read from the right - past the trusted proxies' own addresses,
  never its leftmost entry, which is whatever the visitor wrote. `config/app.php` is the same
  everywhere and travels with the upload: change it here and build again.
- **Watch the count** after a launch: the People page (ADMIN) shows the visitors and their accounts apart, or
  `SELECT count(*) FROM users WHERE id < 0`; and `storage/logs/app.log` ("Visitors unseen for 30 days
  deleted: N"). Lower the limits in `config/app.php` if the database grows faster than it should.
- **At sign-in** the page sends `POST /api/visitor/move` with the token and the key: the visitor's accounts
  become the person's (the person's copy of an account both have stays), and the key opens nothing any more; a
  snack bar says how many moved and how many were kept. When the move cannot be made just then (no network, a
  503) the browser keeps the key and asks again a few seconds later, then at the next page load - the
  visitor's accounts are out of sight meanwhile, never lost.
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
[architecture.md](architecture.md), "The site's words"). An editor or an admin opens them in the admin panel -
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

## Deploying (FTP-only shared hosting)

The host: Apache with `.htaccess` support (`mod_rewrite`), **PHP 8.4** (the scrapers need PHP 8.4's
`Dom\HTMLDocument`) with `pdo_pgsql`, `openssl`, `mbstring`, `curl` and `dom`, and a PostgreSQL database made in
the hosting panel. No shell, cron, queue worker or long-running process is needed. The steps, with the upload
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
   harmless). New migrations show on the admin panel's Migrations page (`/admin/migrations`, ADMIN only)
   as pending, in the order they will run, beside the applied ones with their dates; the Sources page says
   that updates wait, with a link. Back the database up first where the panel allows, then **Apply pending**
   (`POST /api/admin/migrations/apply`): the files run in order, each in its own transaction, and the run
   stops at the first that fails - that file rolled back whole, the page naming it with the database's error
   (the log too). One apply at a time (the `migrate` lock: 409 `migrations_busy`; and whatever runs them - the page,
   `php migration.php`, setup - each file waits on the Migrator's advisory lock, so none runs twice). Nothing on the site waits
   for it: members keep using it, and signing in and the admin panel work with a migration pending.
   - **The release that brings the words (migration 003)**: until Apply, the site reads the English compiled
     into the app - Slovak readers too - and the Translations and Languages pages say to apply the update
     first; afterwards the words come from the database. The tab that pressed Apply reads them at once; another
     tab that was open keeps its words until its next navigation or reload.
   - **A later release that adds strings shows their keys until Apply is pressed** (the owner's decision: a
     missing string shows its key). Upload and press Apply in one sitting.
   - **Version 0.3: visitors and the family's bar (migration 004, IAM migration 007)**, in this
     order: (1) before uploading, make sure an account that can sign in holds **ADMIN on `build-analyzer` now**
     (the IAM's admin panel; IAM 007 is what later gives every IAM administrator ADMIN here); (2) upload;
     (3) sign in with that ADMIN at once and **Apply pending** (004) on the Migrations page - until then visitors get 503
     `visitors_unavailable`, and the new top bar and footer show their keys where words should be (their
     strings are in 004); members can still use their accounts; (4) then apply **IAM 007** (`build-analyzer` open to
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
| "Not set up yet" page / 503 `not_set_up` | a `config/*.local.php` is missing or incomplete; `storage/logs/app.log` says which; run `setup.php` |
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
| 429 `too_many_visitors` | the visitor limits (`config/app.php` `visitors`): too many new visitors from one address or in all this hour, or too many at once; behind a proxy, check `client_address.trusted_proxies` (otherwise everybody shares the proxy's address) |
| 422 `visitor_limit` | a visitor reached its accounts or file size limit: signing in lifts it |
| 503 `visitors_unavailable` | migration 004 is not applied: Apply pending on the Migrations page (members are unaffected meanwhile) |
| 503 `visitor_move_retry` | the move at sign-in met an import still writing; the page asks again (`visitors.move_lock_timeout_ms`) |
| The dashboard says what this browser imported "is not kept here any more" | 401 `visitor_unknown`: the browser's visitor key opens nothing - another tab signed in and moved it, or it was unvisited for 30 days and swept. The page forgets the key and starts over |
| An editor sees no Languages, People or Migrations link, no source switch, no database updates | by design: those are an admin's (`adminOnlyGuard` sends an editor to `/admin/sources`; the server refuses with 403 `admin_only`) |
| "This part is for editors and admins" in the admin panel | the account has no EDITOR or ADMIN role here: grant one in the IAM's admin panel |
| 401 although the person is signed in | the token the page sent was refused (expired, another IAM): the page refreshes it once; `storage/logs/app.log` says why |
| The sign-in page says "This account cannot use Build Analyzer." (or another IAM sentence) | the IAM refused the sign-in and the page shows its words as they are: grant a role in the IAM admin panel, or see what the sentence asks. "The sign-in was cancelled." only means the person went back from the IAM's page |
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
