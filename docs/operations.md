# Operations and environment

First-time setup, day-to-day work and deploying are walked through in [DEV.md](../DEV.md); this
page is the reference behind it.

## Running things

| Task | PowerShell | Bash (Git Bash / agents), from the repo root |
| --- | --- | --- |
| First setup | `.\dev.ps1 setup`, then `.\dev.ps1 start` and open http://localhost:4600 (it leads to `setup.php`) | `cd backend && composer install`; `cd frontend && npm ci`; start the two servers below; open http://localhost:4600/setup.php |
| Everything for development | `.\dev.ps1 start` (API :8400 + Angular :4600; nothing else is needed) | the two below, in background shells |
| API only | – | `cd backend && composer start` (`php -S 127.0.0.1:8400 router.php`) |
| Angular dev server | – | `cd frontend && npx ng serve` (http://localhost:4600) |
| Database migrations | `.\dev.ps1 migrate`, or the Sources page's "Apply" | `cd backend && php migration.php [--status \| --dry-run]` |
| Sync builds | Sources page "Sync all" / "Sync now", or `.\dev.ps1 sync [-Force]` | `cd backend && php sync-builds.php [source] [--force] [--character=x] [--check-names]` |
| Check parsers without writing (offline) | – | `cd backend && php sync-builds.php --dry-run --cache-hours=96 [-v]` |
| Catalog | Sources page "Refresh catalog" | `cd backend && php sync-catalog.php [genshin\|hsr] [--force]` |
| Import from the command line | – | `cd backend && php import-account.php genshin 700123456 [--user=ID]` or `… hsr path/to/file.json --account=3` |
| The site's words | the Translations and Languages pages (the menu in the toolbar, admins) | `cd backend && php translations.php --status` (the frontend's keys against the database's), `--export=sk [file]`, `--import=sk file` |
| Tests | `.\dev.ps1 test` | `cd backend && php tests/run.php` and `cd frontend && npx ng test --watch=false` |
| Frontend build | `.\dev.ps1 build` (then the API server alone serves everything on :8400) | `cd frontend && npx ng build` |
| Build for the host | `.\dev.ps1 dist` | `php scripts/build-dist.php [--skip-frontend]` |
| Look at the data | – | `psql` against the database of `config/database.local.php`, or a scratch PHP file using `App\Cli::app()->db()` |

`scripts/php.sh` runs PHP 8.4+ from `$BUILD_ANALYZER_PHP`, then `dev.local.ps1`
(`$env:BUILD_ANALYZER_PHP = '…'`), then PATH, and refuses older versions - for machines whose `php` on PATH is
too old. AI tool shells reset their working directory, so start commands with `cd <repo root> &&`.

There is **no queue worker and no scheduler**: nothing runs unless someone starts it. Syncs run in steps (see
below), and the page cache (`storage/http-cache/`) is pruned (files older than 3 days) when a build run ends.

## Configuration

Everything that differs between installations is in three files in `backend/config/`, written by `setup.php`
(PHP files returning arrays; gitignored, never committed, never uploaded from here). They are required: while
one is missing or incomplete the API answers every request `503 {"code": "not_set_up"}` and the app shows its
"Not set up yet" page.

| File | Keys |
| --- | --- |
| `database.local.php` | `host`, `port`, `database`, `username`, `password`, `persistent` |
| `auth.local.php` | `mode` (`iam` or `none`), `issuer` (the IAM's address), `client_id` (`build-analyzer`), `local_user_id` (the owner's IAM user id: the user of the "none" mode and of command-line imports); optional `jwks_file` (a key set on disk instead of the IAM's, for offline work) and `leeway` (seconds, 60) |
| `fetch.local.php` | `user_agent` (how requests identify the app), `delay_ms` (between two requests to one site, 1200), `cache_hours` (the page cache, 12), `step_seconds` (one sync step, 20, at least 12), `stale_hours` ("Sync all" skips sources synced within it, 20); optional `timeout` (30), `browser_user_agent` |

Run `setup.php` again to change them (on this computer it can be run any number of times and shows the saved
values, never the password), or edit the files. `config/app.php` is committed and holds what is the same
everywhere: the build sources (in "Source N" order), the catalog provider and its refresh intervals, the showcase
service and its per-person rate limit, and the name aliases.

`setup.php` on this computer (a loopback client asking for a `localhost` address) needs no token - a nonce in a
`SameSite=Strict` cookie protects the form - and never deletes itself. On a real host it writes a token into
`config/setup-token.local.php` that has to be read back over FTP, refuses to run once `storage/installed.lock`
exists, and deletes the token and itself when it is done. Its "Test connection" buttons try the database and
ask the IAM for `{issuer}/.well-known/openid-configuration` without saving anything.

## Sign-in

| `mode` in `auth.local.php` | Who can use the app | Where |
| --- | --- | --- |
| `iam` | people signed in at the anotoki IAM with a role in `build-analyzer` (`MEMBER`, `ADMIN`) | a hosted install (`setup.php` always writes it there) |
| `none` | nobody signs in: every request is `local_user_id` with every role, **only from the app's own pages on this computer** (loopback client and host; `Sec-Fetch-Site: cross-site` and foreign `Origin`s are refused) | the owner's own computer |

- The IAM in development runs at `http://localhost:4300` (its API on :8100); hosted it is
  `https://iam.anotoki.eu`. The frontend reads the issuer and client id from `/api/meta`.
- Registered at the IAM (client `build-analyzer`, "Build Analyzer"): redirect `https://builder.anotoki.eu/auth/callback`,
  post-logout `https://builder.anotoki.eu/`, web origin `https://builder.anotoki.eu`; for development the IAM
  registers `http://localhost:4600` (`/auth/callback`, `/`, the origin) by itself.
- Roles are granted in the IAM's admin console (the owner holds ADMIN). A removed role or a disabled account
  reaches this app within one access-token lifetime (10 minutes).
- Behind a reverse proxy on the same machine every client looks local, so "none" belongs on the owner's
  computer only - `setup.php` offers it nowhere else.
- Why the checks beyond 127.0.0.1: in "none" mode the request itself is the credential, and every other site
  open in the owner's browser also connects from 127.0.0.1. So the API sends no CORS headers at all (the app is
  same-origin, and the dev server proxies), which keeps other sites from reading answers or sending non-simple
  requests, and the guard refuses the simple ones (a cross-site `Sec-Fetch-Site`, an `Origin` or `Host` that is
  not this computer - the latter also stops DNS rebinding).
- The verifier caches the IAM's keys in `storage/cache/iam/`.

## Syncing builds and the catalog

An admin presses **Sync all** (every enabled source not synced successfully in the last `stale_hours`, 20),
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

Each source on the Sources page has **Export** and **Import** (admins; both wait while that source syncs).

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
datacenter addresses) but answer a computer at home. Sync that source on your own computer (a copy with sign-in
switched off will do, see [DEV.md](../DEV.md)), export it there and import the file on the site;
repeat when you want fresher builds. Leave the source switched on there - the analysis uses the builds of
switched-on sources only. Once the import is older than `stale_hours`, "Sync all" on the site tries the source
itself again; when the site still refuses, that run fails without touching the imported builds.

## The site's words

The member pages read in English or Slovak; the words are rows of the database (migration 003,
[architecture.md](architecture.md), "The site's words"). An admin (in auth mode "none", the owner) opens them
from the toolbar's menu:

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

1. `.\dev.ps1 dist` (or `php scripts/build-dist.php`) assembles `dist/build-analyzer/` - the whole web root:
   the Angular build, `api/`, `src/`, `config/app.php`, `migrations/`, `vendor/`, an empty `storage/` and the
   `.htaccess` files - and `dist/setup.php`. It refuses a build with a `*.local.php`, a log, a Laravel component
   or a frontend that names another site.
2. Upload `dist/build-analyzer/*` into the site's web root (and, the first time, `dist/setup.php` beside it).
   `config/` and `storage/` must be writable for the web server.
3. First install: open `https://builder.anotoki.eu/setup.php`, read the token from
   `config/setup-token.local.php` over FTP, fill in the database and the IAM, test both, press "Set up". It
   checks the requirements and that `storage/` is not served, builds the database, writes the three config files
   (owner-only where PHP runs as the FTP account), writes `storage/installed.lock` and deletes the token and
   itself.
4. Sign in with the ADMIN account, press **Refresh catalog**, then **Sync all**, and keep the tab open.
5. A new version: upload `dist/build-analyzer/*` again (FTP never deletes remote files; old hashed `*.js` are
   harmless). New migrations show on the Sources page as waiting database updates; **Apply** runs them
   (`POST /api/admin/migrations/apply`, admins only). Back the database up first where the panel allows.
   - **The release that brings the words (migration 003)**: until Apply, the site reads the English compiled
     into the app - Slovak readers too - and the Translations and Languages pages say to apply the update
     first; afterwards the words come from the database. The tab that pressed Apply reads them at once; another
     tab that was open keeps its words until its next navigation or reload.
   - **A later release that adds strings shows their keys until Apply is pressed** (the owner's decision: a
     missing string shows its key). Upload and press Apply in one sitting.

Check after an install: `https://builder.anotoki.eu/config/database.local.php`, `/storage/logs/app.log` and
`/src/Config.php` must not download (403); `https://builder.anotoki.eu/api/meta` answers JSON with
`"mode":"iam"`; `/api/accounts` answers 401 without a token.

### A database made by the first (Laravel) version

`001_adopt_laravel_database.sql` recognises a PostgreSQL database that the Laravel version created (its
`migrations` table has a `batch` column) and brings it forward in place: Laravel's own tables are kept aside
as `laravel_*` (dropped when empty), times become `TIMESTAMPTZ` with the same instants, accounts and imports
without an owner get `local_user_id`, runs a queue worker left "running" are closed as failed, and a
verification gate compares the counts before and after. `002_schema.sql` then finishes the schema, so an
adopted database ends up identical to a new one (`tests/migrations.test.php` proves it for the three schema
versions that version had). Point `setup.php` at the old database and it does all of that; `migration.php`
does the same on the command line. The old SQLite database of a local copy is not carried over: import the
accounts again and sync (cached pages make it quick).

## Machine quirks (this Windows machine)

- The `php` on PATH has changed over time (PHP 7.4, then XAMPP's 8.2; since 2026-09-28 PHP 8.5 with its own
  php.ini: curl, fileinfo, intl, mbstring, openssl, pdo_pgsql, sodium, zip and a CA bundle). The project needs
  8.4+: `scripts/php.sh` / `dev.ps1` take `BUILD_ANALYZER_PHP` (or the path in the gitignored `dev.local.ps1`)
  and refuse anything older than 8.4.
- Outgoing requests use `composer/ca-bundle`'s certificates whatever php.ini says, so don't "fix" SSL errors by
  disabling verification.
- PHP 8.5 deprecates `$http_response_header`, `curl_close()` and the `(integer)` / `(boolean)` / `(double)`
  casts; the app and the vendored IAM verifier (v1.0.1) use none of them. Keep it that way: the host runs 8.4,
  this machine 8.5.
- PostgreSQL 18 runs on 5432. The tests make throwaway databases on that server (`build_analyzer_test_*`) and
  drop them again.
- npm 10.9.2 crashes on this project (`Cannot read properties of null (reading 'edgesOut')`); use
  `npx -y npm@11 install|ci`.
- Node 22.15 → Angular 21 (Angular 22 needs Node ≥ 22.22.3).
- Python is not installed (`python` is the Windows Store stub): use node or PHP for scripting.
- Git Bash: heredocs containing apostrophes or backslashes can come out wrong → write files with the editor
  tools instead; `sed -i` converts CRLF files to LF (check with `file`).
- A background `ng serve` can leave an orphan `node` process on port 4600 after stopping; free the port with
  `Get-NetTCPConnection -LocalPort 4600` → `Stop-Process`. The same for a PHP server on 8400.

## Troubleshooting

| Symptom | Where to look |
| --- | --- |
| "Not set up yet" page / 503 `not_set_up` | a `config/*.local.php` is missing or incomplete; `storage/logs/app.log` says which; run `setup.php` |
| Source status `failed` / `partial` | Sources page run log or `sync_runs.log`; use the `debug-build-sync` skill |
| Builds getting old, no failed runs | nobody synced: syncs only run when an admin starts them (Sources page) |
| A sync shows "paused" | no Sources page is stepping it: press Resume (runs keep their place indefinitely) |
| Parser change: did it break anything? | `php sync-builds.php --dry-run --cache-hours=96` → expect only intended `changed` |
| `! unknown weapon/set` lines, "unmatched" in import summaries | [name-matching.md](name-matching.md) → add an alias, or refresh the catalog |
| An import skipped a new character / set | the catalog lacks it: Refresh catalog (Sources page), then import again |
| New character missing builds | released? `php sync-builds.php --check-names`; the site may not have it yet |
| A source always fails on the host (HTTP 403) but syncs at home | its site refuses the host: sync it at home, Export, and Import on the site ("Moving builds between installations") |
| A builds file's import skipped characters ("not in the catalog") | this catalog is older than the file: Refresh catalog on the Sources page, then import the same file again |
| A builds file's import answers "No file arrived" | the file is larger than the host's PHP `post_max_size`; a whole source takes about 2 MB, so check the hosting panel's PHP settings |
| Showcase import errors | the message comes from `EnkaClient` (UID, maintenance, 429); details hidden → the wizard's steps |
| "Sign in to continue." / 401 everywhere | token missing or refused: `storage/logs/app.log` has "IAM access token refused: <reason>" (issuer, audience, expiry, keys) |
| 403 "Sign-in is switched off … (auth mode none)" | a "none" copy asked from another computer or site: use http://localhost:4600, or switch sign-in on with `setup.php` |
| 403 "no access to the build analyzer" | the account has no role in `build-analyzer`: grant MEMBER in the IAM admin console |
| The sign-in page says "This account cannot use Build Analyzer." (or another IAM sentence) | the IAM refused the sign-in and the page shows its words as they are: grant a role in the IAM admin console, or see what the sentence asks. "The sign-in was cancelled." only means the person went back from the IAM's page |
| "Cannot reach the server" in the UI | API not running on 127.0.0.1:8400 (the Angular dev server proxies `/api`) |
| Icons missing | `/api/icons/…` could not fetch the image: `storage/icons/` writable? the provider reachable? |
| Headless screenshots blank | timing only; see the `verify-ui` skill |
| A key (`dashboard.title`) shows where a word should be | the database lacks that string: a migration with it waits (Sources page → Apply), or the key is new and its migration was not written; `php translations.php --status` lists the frontend's keys the database lacks |
| Everything in English although Slovak is chosen; the owner pages say to apply an update first | 503 `translations_unavailable`: migration 003 is not applied yet - Apply on the Sources page |
| A language is missing from the toolbar's menu | it is hidden: offer it on the Languages page |
| A reworded string is back to the old words | the database was replaced, or this is the other installation: each keeps its own strings; import the language's exported file |
