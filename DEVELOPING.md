# Developing the build analyzer

How to get a copy running, work on it, test it, build it and put it on the FTP-only host. The reference behind
these steps (configuration keys, sign-in modes, syncs, troubleshooting) is [docs/operations.md](docs/operations.md).

| Part | Folder | Stack | Address in development |
| --- | --- | --- | --- |
| Backend (API, importers, scrapers, analysis) | `backend/` | PHP 8.4+, Slim 4, PostgreSQL | http://127.0.0.1:8400 |
| Frontend | `frontend/` | Angular 21, Angular Material | http://localhost:4600 (proxies `/api` and `/setup.php` to :8400) |
| Sign-in | the anotoki IAM (`../anotoki-iam`) | | pages http://localhost:4300, API :8100 |

## Prerequisites

- **PHP 8.4 or newer** (8.5 works) with the extensions `pdo_pgsql`, `openssl`, `mbstring`, `curl`, `dom` and
  `json`. If the `php` on your PATH is older, put `$env:BUILD_ANALYZER_PHP = 'C:\path\to\php.exe'` into
  `dev.local.ps1` at the repo root (gitignored); `dev.ps1` and `scripts/php.sh` use it.
- **Composer 2** (`dev.ps1 setup` downloads it into `tools/` when it is not on the PATH).
- **PostgreSQL** (14 or newer; developed on 18) and a user that may create databases - setup creates the app's
  database, and the tests create throwaway ones.
- **Node.js 22.12+** with npm. (npm 10.9.2 crashes on this project; `npx -y npm@11 ci` works.)
- To sign in: the **anotoki IAM** running locally (see its DEVELOPING.md; it registers this app at
  http://localhost:4600 by itself). Without it, set sign-in to "switched off" in setup - a copy of your own,
  where everything belongs to one owner.

## First setup

1. Install the packages:

   ```bash
   cd backend && composer install
   cd ../frontend && npm ci
   ```

   (`.\dev.ps1 setup` does both on Windows.)

2. Start the two servers, each in its own terminal:

   ```bash
   cd backend && php -S 127.0.0.1:8400 router.php
   cd frontend && npm start
   ```

   (`.\dev.ps1 start` opens both in their own windows.)

3. Open http://localhost:4600. A copy that has not been set up says so and links to **setup.php**
   (http://localhost:4600/setup.php). Opened on this computer, setup needs no token and can be run again at any
   time. Fill in:
   - **Database**: host, port, database name (`build_analyzer`), user and password of your PostgreSQL. "Test
     connection" says whether it can connect and what is there.
   - **Sign-in**: "Through the anotoki IAM" (IAM address `http://localhost:4300`, client id `build-analyzer`;
     "Test connection" asks the IAM for its OpenID configuration) or "Switched off".
   - **The owner's user id**: your anotoki IAM user id (1 on a copy of your own). With sign-in switched off
     everything belongs to this user; command-line imports go to them too.
   - **Outgoing requests**: how the app identifies itself to the sites it reads, and the delays. The defaults
     are fine.

   "Save and build the database" writes `backend/config/database.local.php`, `auth.local.php` and
   `fetch.local.php`, creates the database if it is missing and applies every migration.

4. Back at http://localhost:4600: open **Sources** and press **Refresh catalog**, then **Sync all**, and keep the
   tab open (a full sync takes a while: some sites ask for 10 seconds between pages). Or from the command line:
   `cd backend && php sync-catalog.php && php sync-builds.php`.

5. **Import** an account: by UID (the in-game Character Showcase) or from an inventory export file.

The three `config/*.local.php` files hold this installation's settings, the database password included. They
are gitignored; never commit or share them.

### A database from the first (Laravel) version

Point setup at it: a PostgreSQL database made by the Laravel version is recognised and brought forward in
place, with all its accounts, builds and runs (`backend/migrations/001_adopt_laravel_database.sql`; its
framework tables are kept aside as `laravel_*`). Back it up first (`pg_dump`). A local copy that ran on SQLite
is not carried over: import the accounts again and sync; the old `backend/.env` and `database.sqlite` are no
longer read and can be deleted.

## Day to day

| Task | Command (from the folder shown) |
| --- | --- |
| Run everything | `.\dev.ps1 start`, or the two servers above |
| Apply new migrations | `backend: php migration.php` (`--status`, `--dry-run`); or "Apply" on the Sources page |
| Sync builds | Sources page, or `backend: php sync-builds.php [source] [--force] [--character=hu_tao] [--dry-run]` |
| Refresh the catalog | Sources page, or `backend: php sync-catalog.php [genshin\|hsr] [--force]` |
| Import from the command line | `backend: php import-account.php genshin 700123456` or `… hsr path/to/export.json --account=3` |
| Change the configuration | run `setup.php` again (the password field left empty keeps the saved one) |

- **Database changes** are new files in `backend/migrations/` (`NNN_what_it_does.sql`), applied in order by
  `migration.php`, by the Sources page's "Apply" and by `setup.php` - the same engine. Never edit a migration
  that has been applied somewhere; add another. A migration that changes data ends with a `DO $$ … $$` block
  that checks the result and raises an exception (rolling the migration back) when it is wrong.
- **API changes**: the shapes live in `backend/src/Presenters/` and are mirrored in
  `frontend/src/app/core/models.ts`; change both together.
- **Nothing names another site** in the UI or in what the API sends to be shown: build sources are "Source 1",
  "Source 2" …, the showcase service and the tools are described, never named. See
  [docs/architecture.md](docs/architecture.md) ("Neutral names").
- **A new release**: add an entry at the top of `frontend/public/changelog.json` (version, date, and what
  changed, in plain words for the people using the app) and raise `backend/src/Version.php` to the same
  version. The version shows in the app's top bar and opens "What's new".

## Testing

```bash
cd backend && php tests/run.php            # every backend test file (or one: php tests/auth.test.php)
cd frontend && npx ng test --watch=false   # frontend unit tests (Vitest)
cd frontend && npx ng build                # the production build must pass too
```

The backend tests need `config/database.local.php` (run setup first): each test file creates its own
throwaway database on that server (`build_analyzer_test_…`), builds it from the migrations and drops it when
it ends. Nothing reaches the network - build pages and API answers are small fixtures, and a fake HTTP client
fails a test on any request it was not told about. IAM tokens are signed by the tests' own key.
`.\dev.ps1 test` runs both suites.

For a look at the UI in a real browser, the `verify-ui` skill (`.claude/skills/verify-ui/`) takes headless
Edge screenshots.

## Building

- `cd frontend && npx ng build` writes the app to `frontend/dist/frontend/browser/`; the PHP server on :8400
  then serves it too (`router.php`), without the Angular dev server.
- `.\dev.ps1 dist` (or `php scripts/build-dist.php`) builds the upload - see below.

## Deploying (FTP-only hosting)

The host needs Apache with `.htaccess` support (`mod_rewrite`), **PHP 8.4+** with `pdo_pgsql`, `openssl`,
`mbstring`, `curl` and `dom`, and a **PostgreSQL** database made in the hosting panel. No shell, cron job,
queue worker or long-running process is needed: files go up by FTP, the database is built by `setup.php` and
later changed from the Sources page.

1. **Build** on your computer: `.\dev.ps1 dist` (or `php scripts/build-dist.php`). It builds the frontend and
   assembles:

   | Local | Upload to | |
   | --- | --- | --- |
   | `dist/build-analyzer/*` (with its `.htaccess` files) | the site's web root, e.g. `~/builder.anotoki.eu/` | the app, `api/`, `src/`, `config/app.php`, `migrations/`, `vendor/`, an empty `storage/` |
   | `dist/setup.php` | the same web root | the first install only; it deletes itself |

   The `.htaccess` files send `/api` to `api/index.php`, every other path that is not a file to the app (a
   missing `.js`, `.css`, font or image is a 404), and deny `src/`, `config/`, `migrations/`, `vendor/` and
   `storage/` to the web. The build contains no `config/*.local.php`, no command-line scripts and no tests.

2. **Permissions**: `config/` and `storage/` (with its folders) must be writable for the web server (FTP: 755
   or 775).

3. **Register the site at the IAM** (its admin console, client `build-analyzer`): redirect URI
   `https://builder.anotoki.eu/auth/callback`, post-logout URI `https://builder.anotoki.eu/`, web origin
   `https://builder.anotoki.eu`. Give your account the `ADMIN` role there.

4. **First install**: open `https://builder.anotoki.eu/setup.php`. It writes a token into
   `config/setup-token.local.php`; download that file by FTP and paste the token into the form, with the
   PostgreSQL connection and the IAM (`https://iam.anotoki.eu`, client id `build-analyzer`), and test both. "Set
   up" checks the requirements and that `storage/` is not served, builds the database, writes the three config
   files, writes `storage/installed.lock` and deletes the token and `setup.php` itself (delete them by FTP if it
   says it could not).

5. **Check**: `https://builder.anotoki.eu/api/meta` answers JSON with `"mode":"iam"`; `/api/accounts` answers
   401; `/config/database.local.php` and `/storage/logs/app.log` do not download (403).

6. **First data**: sign in with the ADMIN account, open Sources, press **Refresh catalog**, then **Sync all**,
   and keep the tab open until it is done.

**A new version**: build again and upload `dist/build-analyzer/*` over the old files (FTP never deletes remote
files; old hashed `*.js` do no harm). When it brings new migrations, the Sources page shows the waiting
database updates; back the database up (hosting panel) and press **Apply**. `config/*.local.php` and
`storage/` stay as they are.

**Replacing an install of the Laravel version**: its layout was different (a `public/` document root and
Laravel's folders beside it). Upload the new build into a fresh folder, make that the site's document root,
upload `setup.php`, and point it at the existing database - it is brought forward with all its data. When
the new site works, delete the old folder, including its `.env`.
