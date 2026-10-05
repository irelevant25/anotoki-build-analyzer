# Developing the build analyzer

How to get a copy running, work on it, test it, build it and put it on the FTP-only host. The reference behind
these steps (configuration keys, sign-in and roles, syncs, troubleshooting) is [docs/operations.md](docs/operations.md).

| Part | Folder | Stack | Address in development |
| --- | --- | --- | --- |
| Backend (API, importers, scrapers, analysis) | `backend/` | PHP 8.4+, Slim 4, PostgreSQL | http://localhost:8400 |
| Frontend | `frontend/` | Angular 21, Angular Material | http://localhost:4600 (proxies `/api` and `/setup.php` to :8400) |
| Sign-in | the anotoki IAM (`../anotoki-iam`) | | pages http://localhost:4300, API :8100 |

## Prerequisites

- **PHP 8.4 or newer** (8.5 works) with the extensions `pdo_pgsql`, `openssl`, `mbstring`, `curl`, `dom` and
  `json`. If the `php` on your PATH is older, call the newer one directly, or from bash set
  `BUILD_ANALYZER_PHP` to it and run `backend/tools/php.sh ...` in place of `php ...`.
- **Composer 2**.
- **PostgreSQL** (14 or newer; developed on 18) and a user that may create databases - setup creates the app's
  database, and the tests create throwaway ones.
- **Node.js 22.12+** with npm. (npm 10.9.2 crashes on this project; `npx -y npm@11 ci` works.)
- **Bun** (developed on 1.4): `npm start` in `frontend/` installs the packages with `bun ci`, from `bun.lock`,
  before it serves. npm installs from `package-lock.json`, so a change of the dependencies updates both lock
  files.
- To sign in: the **anotoki IAM** running locally (`../anotoki-iam`, see its DEV.md; it registers this app at
  http://localhost:4600 by itself). Sign-in is always the IAM's - there is no copy without it. Its administrator
  account is ADMIN here; any other account may sign in and use the site with its own accounts. Whether
  sign-in is *required* here is the IAM's switch too (its admin panel: Applications -> Build Analyzer ->
  Settings), which the backend reads from `GET http://localhost:4300/api/public/applications/build-analyzer`
  and keeps a minute in `storage/cache/iam/`: an IAM without that address yet (its migration 010) answers 404,
  and the site then requires sign-in - every page sends you to the IAM first - until it has it. To see the
  switch at work: turn it on there, wait a minute (or delete `storage/cache/iam/anotoki-iam-application-*.json`),
  and open the site in a private window - it goes to the IAM first; turn it off again the same way. The browser
  check does the same against its stand-in IAM.

## First setup

1. Install the packages:

   ```bash
   cd backend && composer install
   cd ../frontend && bun ci
   ```

2. Start the two servers, each in its own terminal:

   ```bash
   cd backend && composer start           # php -S localhost:8400 router.php
   cd frontend && npm start               # bun ci, then ng serve on http://localhost:4600
   ```

3. Open http://localhost:4600. A copy that has not been set up says so and links to **setup.php**
   (http://localhost:4600/setup.php). Opened on this computer, setup needs no token and can be run again at any
   time. It asks for two things:
   - **Database**: host, port (`5432`), database name (`anotoki_builder`), user and password of your
     PostgreSQL. "Test connection" answers as every anotoki setup page does: connected and empty, N update(s)
     to apply, up to date, not there yet (Save creates it), or why it cannot connect.
   - **Sign-in**: the anotoki IAM's address (`http://localhost:4300`) and this site's client id there
     (`build-analyzer`); "Test connection" asks the IAM for its OpenID configuration.

   "Save" writes `backend/config/database.local.php` and `auth.local.php` (and `fetch.local.php` with its
   defaults when there is none: how the app names itself to the sites it reads, the delays, the sync step -
   edit it by hand if you must; setup keeps it as it is), creates the database if it is missing and applies
   every migration.

4. Back at http://localhost:4600, signed in with the IAM's administrator: open **Sources** and press **Refresh catalog**, then **Sync all**, and keep the
   tab open (a full sync takes a while: some sites ask for 10 seconds between pages). Or from the command line:
   `cd backend && php sync-catalog.php && php sync-builds.php`.

5. **Import** an account: by UID (the in-game Character Showcase) or from an inventory export file.

The `config/*.local.php` files hold this installation's settings, the database password included. They
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
| Run everything | the two servers above |
| Apply new migrations | `backend: php migration.php` (`--status`, `--dry-run`); or "Apply pending" on the Migrations page (`/admin/migrations`, ADMIN) |
| Sync builds | Sources page, or `backend: php sync-builds.php [source] [--force] [--character=hu_tao] [--dry-run]` |
| Refresh the catalog | Sources page, or `backend: php sync-catalog.php [genshin\|hsr] [--force]` |
| Move a source's builds to another installation | Sources page: **Export** on one, **Import** on the other ([docs/operations.md](docs/operations.md)) |
| Import from the command line | `backend: php import-account.php genshin 700123456 --user=1` or `… hsr path/to/export.json --user=1 --account=3` (`--user`: whose account, an IAM user id - required) |
| Reword the site's strings, add or hide a language | the Translations page (editors) and the Languages page (adding, renaming, switching, deleting a language: admins); or `backend: php translations.php [--status \| --export=sk [file] \| --import=sk file]` |
| Change the configuration | run `setup.php` again (the password field left empty keeps the saved one) |

- **Database changes** are new files in `backend/migrations/` (`NNN_what_it_does.sql`), applied in order by
  `migration.php`, by the Migrations page's "Apply pending" and by `setup.php` - the same engine. Never edit a migration
  that has been applied somewhere; add another. A migration that changes data ends with a `DO $$ … $$` block
  that checks the result and raises an exception (rolling the migration back) when it is wrong.
- **API changes**: the shapes live in `backend/src/Presenters/` and are mirrored in
  `frontend/src/app/core/models.ts`; change both together.
- **Nothing names another site** in the UI or in what the API sends to be shown: build sources are "Source 1",
  "Source 2" …, the showcase service and the tools are described, never named. See
  [docs/architecture.md](docs/architecture.md) ("Neutral names").
- **Words a member reads are keys**: a template words a key with the `translate` pipe; a new string is its key
  and English in `frontend/src/app/core/i18n/en/*.json` plus a new migration with the key, its description, the
  English and informal Slovak ("ty"). What the server says to a member (recommendations, import errors and
  warnings) is a kind with params that the page words. Game terms and the game's notation are never
  translated. The admin panel (`/admin/`: Sources, Translations, Languages, People) stays English. See
  [docs/architecture.md](docs/architecture.md) ("The site's words").
- **A new release**: add an entry at the top of `frontend/public/changelog.json` (version, date, and what
  changed, in plain words for the people using the app) and raise `backend/src/Version.php` to the same
  version. The version shows at the foot of every page, beside the button that opens "What's new".

## Testing

```bash
cd backend && php tests/run.php            # every backend test file (or one: php tests/auth.test.php)
cd frontend && npx ng test --watch=false   # frontend unit tests (Vitest)
cd frontend && npx ng build                # the production build must pass too
```

The backend tests need `config/database.local.php` (run setup first): each test file creates its own
throwaway database on that server (`build_analyzer_test_…`), builds it from the migrations and drops it when
it ends. Nothing reaches the network - build pages and API answers are small fixtures, and a fake HTTP client
fails a test on any request it was not told about. IAM tokens are signed by the tests' own key;
`tests/stand_in_iam.test.php` signs in through the stand-in IAM (`tests/tools/fake-iam.php`, the survey's,
run as `php -S` on a throwaway port 8590-8599 and stopped at the end) as a browser would, and reads whether
sign-in is required from it (the real `IamSignInPolicy`: kept a minute, 404 = required, out of reach = the last
answer); every other test gives a fixed answer instead (`T::$signIn`, false unless a test sets it). `setup.test.php`
checks "Test connection" with a throwaway login (`build_analyzer_test_…_nocreate`) when the test login may make
one, and drops it at the end.

The browser check (the `verify-ui` skill) runs the site end to end in headless Edge, signed in through the
stand-in IAM, on a throwaway database - nobody, a visitor's import and the move at sign-in, a member's own
data, an editor and an admin, the top bar at 360-1280 px in light and dark with a real Tab press, Slovak at
390 px with no key on the page:

```bash
node .claude/skills/verify-ui/scripts/run.mjs [--no-build] [--keep]   # from the root; ports 8590 and 8597
```

It makes `build_analyzer_test_browser` (`backend/tests/tools/browser-db.php`), points the site at it with
`BUILD_ANALYZER_TEST_CONFIG` (a settings folder outside the repository - honoured only for a
`build_analyzer_test_` database), stops everything and drops the database at the end, and leaves the
screenshots to look at (`--keep`). It never touches your database, settings or servers.

`tests/translation_catalogue.test.php` holds the site's words together: the compiled English against the
seeded English, every key the frontend uses against the database's, the server's kinds against the declared
key families, and every seeded string against the checks a save makes. A new string with no migration fails it.

## Building

- `cd frontend && npx ng build` writes the app to `frontend/dist/frontend/browser/`; the PHP server on :8400
  then serves it too (`router.php`), without the Angular dev server.
- `cd backend && php tools/build.php` builds the upload into `dist/` at the repository root - see below.

## Deploying (FTP-only hosting)

The host needs Apache with `.htaccess` support (`mod_rewrite`), **PHP 8.4+** with `pdo_pgsql`, `openssl`,
`mbstring`, `curl` and `dom`, and a **PostgreSQL** database made in the hosting panel. No shell, cron job,
queue worker or long-running process is needed: files go up by FTP, the database is built by `setup.php` and
later changed from the Sources page.

1. **Build** on your computer: `cd backend && php tools/build.php`. It builds the frontend and assembles:

   | Local | Upload to | |
   | --- | --- | --- |
   | `dist/build-analyzer/*` (with its `.htaccess` files) | the site's web root, e.g. `~/builder.anotoki.eu/` | the app, `api/`, `src/`, `config/app.php`, `migrations/`, `vendor/`, an empty `storage/` |
   | `dist/setup.php` | the same web root | the first install only; it deletes itself |

   The `.htaccess` files send `/api` to `api/index.php`, every other path that is not a file to the app (a
   missing `.js`, `.css`, font or image is a 404), and deny `src/`, `config/`, `migrations/`, `vendor/` and
   `storage/` to the web. The build contains no `config/*.local.php`, no command-line scripts and no tests.

2. **Permissions**: `config/` and `storage/` (with its folders) must be writable for the web server (FTP: 755
   or 775).

3. **Register the site at the IAM** (its admin panel, client `build-analyzer`): redirect URI
   `https://builder.anotoki.eu/auth/callback`, post-logout URI `https://builder.anotoki.eu/`, web origin
   `https://builder.anotoki.eu`. Any anotoki account may sign in; give the people who run it `ADMIN` (everything)
   or `EDITOR` (syncs and the site's strings) there.

4. **First install**: open `https://builder.anotoki.eu/setup.php`. It writes a token into
   `config/setup-token.local.php`; download that file by FTP and paste the token into the form, with the
   PostgreSQL connection and the IAM (`https://iam.anotoki.eu`, client id `build-analyzer`), and test both. "Set
   up" checks the requirements and that `storage/` is not served, builds the database, writes the config
   files, writes `storage/installed.lock` and deletes the token and `setup.php` itself (delete them by FTP if it
   says it could not).

5. **Check**: `https://builder.anotoki.eu/api/meta` answers JSON with the IAM's address under `auth`;
   `/api/accounts` answers 401; `/config/database.local.php` and `/storage/logs/app.log` do not download (403).

6. **First data**: sign in with the ADMIN account, open Sources, press **Refresh catalog**, then **Sync all**,
   and keep the tab open until it is done. A source whose site refuses the host (its syncs fail with HTTP 403):
   sync it on your own computer, **Export** it there and **Import** the file on the site's Sources page
   ([docs/operations.md](docs/operations.md), "Moving builds between installations").

**A new version**: build again and upload `dist/build-analyzer/*` over the old files (FTP never deletes remote
files; old hashed `*.js` do no harm). When it brings new migrations, the admin panel's Migrations page lists
them as pending (the Sources page says they wait); back the database up (hosting panel) and press **Apply
pending** there (an ADMIN). `config/*.local.php` and
`storage/` stay as they are.

- **The release that brings the site's words** (migration 003): upload, then press **Apply pending** on the
  Migrations page. Until then the site reads the English compiled into the app - Slovak readers too - and afterwards its
  words come from the database. A tab that was open keeps its words until its next navigation or reload.
- **A later release that adds strings shows their keys until Apply is pressed** (a missing string shows its
  key, by the owner's decision). Upload and press Apply in one sitting.
- **Version 0.3: visitor accounts and the family's bar** (migration 004, then the IAM's migration 007),
  in this order: make sure an account holds ADMIN on `build-analyzer` in the IAM *before* uploading; upload;
  sign in with it and press **Apply pending** on `/admin/migrations` at once (until then visitors get 503
  `visitors_unavailable`, and the new bar and footer show their keys where words should be - members can
  still use their accounts); then apply IAM 007. If nobody holds ADMIN here yet, apply IAM 007 right after the upload, then
  Apply. Then check in a private window that a file imports without signing in and stays after a reload. The
  whole sequence: [docs/operations.md](docs/operations.md), "Deploying".
- **The IAM's sign-in switch** (IAM SDK 1.1.0): upload only once the IAM answers
  `https://iam.anotoki.eu/api/public/applications/build-analyzer` with 200 - before that the site requires
  sign-in (fail closed) and turns visitors away. [docs/operations.md](docs/operations.md), "Deploying".
- **Each installation holds its own strings.** Strings reworded in the browser live only in that
  installation's database; your computer and the host each have their own, and no upload carries them.
  Export a language (Translations page, or `php translations.php --export=sk file` on your computer) before a
  database is replaced, and import it on the other installation to carry edits across. Deleting an added
  language deletes its strings.

**Replacing an install of the Laravel version**: its layout was different (a `public/` document root and
Laravel's folders beside it). Upload the new build into a fresh folder, make that the site's document root,
upload `setup.php`, and point it at the existing database - it is brought forward with all its data. When
the new site works, delete the old folder, including its `.env`.
