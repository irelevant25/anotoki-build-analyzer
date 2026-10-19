# Developing the build analyzer

How to get a copy running, work on it, test it, build it and put it on the FTP-only host. The reference behind
these steps (configuration keys, sign-in and roles, syncs, troubleshooting) is [docs/operations.md](docs/operations.md).

| Part | Folder | Stack | Address in development |
| --- | --- | --- | --- |
| Backend (API, importers, scrapers, analysis) | `backend/` | PHP 8.4+, Slim 4, PostgreSQL | http://localhost:8400 |
| Frontend | `frontend/` | Angular 21, the anotoki family's kit (`@anotoki/lib`) | http://localhost:4600 (proxies `/api` and `/setup.php` to :8400) |
| Sign-in | the anotoki IAM (`../anotoki-iam`) | | pages http://localhost:4300, API :8100 |

## Prerequisites

- **PHP 8.4 or newer** (8.5 works) with the extensions `pdo_pgsql`, `openssl`, `mbstring`, `curl`, `dom`,
  `json` and `zlib` (the backups). If the `php` on your PATH is older, call the newer one directly, or from bash set
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

   Both bring the anotoki family's shared library, **anotoki-lib** (the migration engine, the site gate, the
   Migrations page and the translations module; see "The shared library" below), from GitHub: Composer `anotoki/lib` from the repository
   `irelevant25/anotoki-lib`, the frontend `@anotoki/lib` from that repository's release tarball.

2. Start the two servers, each in its own terminal:

   ```bash
   cd backend && composer start           # php -S localhost:8400 router.php
   cd frontend && npm start               # bun ci, then ng serve on http://localhost:4600
   ```

3. Open http://localhost:4600. A copy that has not been set up says so ("This site is not set up yet", the
   anotoki family's page) and links to **setup.php** (http://localhost:4600/setup.php) - as long as it was never
   installed: setup on a host writes `storage/installed.lock`, and from then on nobody is offered the setup page
   again; on this computer it never writes it. Opened on this computer, setup needs no token and can be run
   again at any time. It asks for two things:
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
| Update the shared library (anotoki-lib) | see "The shared library" below |

- **Database changes** are new files in `backend/migrations/` (`NNN_what_it_does.sql`), applied in order by
  `migration.php`, by the Migrations page's "Apply pending" and by `setup.php` - the same engine (anotoki-lib's,
  on this site's files and table: `backend/src/Db/Migrations.php`). Never edit a migration that has been applied
  somewhere; add another. A migration that changes data ends with a `DO $$ … $$` block that checks the result
  and raises an exception (rolling the migration back) when it is wrong. **While a migration waits the site is
  closed** (the site gate): everybody but the ADMIN on the Migrations page reads "The site is being updated" -
  on this computer too, so after pulling a new migration run `php migration.php` (or Apply on the page).
- **API changes**: the shapes live in `backend/src/Presenters/` and are mirrored in
  `frontend/src/app/core/models.ts`; change both together. The strings' routes are the library's: their
  shapes come with it, in both halves.
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
  changed, in plain words for the people using the app) and raise `backend/src/Version.php` and
  `frontend/package.json`'s `version` to the same version (`tests/auth.test.php` holds the three together). A
  version has three numbers, MAJOR.MINOR.PATCH: big changes, new features, bug fixes (0.4.0). What's new holds
  only what the people using the site notice - nothing technical or for developers (a library, the servers, a
  visitor counter). The version shows at the foot of every page, beside the button that opens "What's new".

## The shared library (anotoki-lib)

What the anotoki sites share lives in one library, [irelevant25/anotoki-lib](https://github.com/irelevant25/anotoki-lib),
in two halves with one version: the Composer package `anotoki/lib` (`backend/composer.json`: a `vcs` repository
entry and `"anotoki/lib": "^0.8.0"`; installed into `backend/vendor/`, which the upload carries) and the npm
package `@anotoki/lib` (`frontend/package.json`: the release's tarball). Both halves are 0.8.0 (0.6.0: the legal
pages - Terms, Privacy, Contact - and the kit's newer pieces; 0.6.1 and 0.6.2: the Privacy page's sentences made
true on every site; 0.6.3: the family's footer, this site's own made the library's; 0.7.0: the library's folders
renamed - its PHP half's files are under `vendor/anotoki/lib/backend/`; 0.7.1: the footer's version written short,
"v0.4.0"; 0.7.2: the feedback form saying itself whom its message is for, and the library's words in the reader's
language while no bundle is in memory; 0.8.0: the About and Support pages on `/about` and `/support`, linked first
in the footer). A minor version is taken by
hand while the version is 0.x: `^0.5.0` never took 0.6 by itself;
never 0.4.0, whose Feedback page fails on PHP 8.2. Besides the translations
module, the auth module (signing in through the anotoki IAM: the token check, the IAM's switch, the browser's
client, interceptor, guards and the sign-in hint), the people module (the People page, the scrub of the accounts
the IAM deleted, what a restore does again), the admin tools (the error log - the browser's errors too -, the
audit log, feedback, sealed backups, one reading of a request's address), the legal pages (with this site's
facts: `frontend/src/app/core/legal-facts.ts`) and the footer (`<anotoki-site-footer>`, in both layouts) - each set
up for this site where it is
used - they hold the migrations module, one behaviour for every anotoki site while its database is not ready:

- the engine (`Anotoki\Lib\Migrations\Migrator`), on this site's files and bookkeeping table through
  `backend/src/Db/Migrations.php` - `migration.php`, `setup.php`, the Migrations page and the tests; it runs
  the library's `anotoki_admin` (the audit log's and feedback's tables), this site's set (`build_analyzer`), then
  the library's `anotoki_translations` (its tables' check and its words) and `anotoki_people` (where the scrub
  stands), each library file named with its set;
- the site gate in front of every API route (`SiteState`, `SiteGate`: `backend/api/middleware/site_gate.php`),
  `GET /api/site-status`, and the Migrations page's API (`MigrationsRoutes`: `backend/api/routes/admin.php`);
- in the frontend, the gate with its page - the router outlet given to it as a marked template,
  `<ng-template anotokiSitePages>`, so the pages are made only while the site is open -, and the Migrations
  page, from its own entry point `@anotoki/lib/migrations/page` (`frontend/src/app/core/site-status.ts` wires
  them to this site);

and the translations module (since this site's 0.4.0), the strings every anotoki site keeps the same way:

- the server's half (`Anotoki\Lib\Translations`) on this site's three tables: the bundle a page reads, the
  admin pages' routes, every check of a string, the command line (`php translations.php`) -
  `backend/src/Services/Translations/SiteTranslations.php` gives it what is this site's (its released
  languages, its check that no string names another site, how its frontend's keys are found), and
  `backend/api/routes/translations.php` puts the routes behind this site's own rights;
- the browser's half (`@anotoki/lib/translations`): the service, the pipes and the language switcher's state,
  with this site's typed keys over them (`frontend/src/app/core/i18n/translation.ts`) and the English compiled
  into the app as the fallback (`translations.config.ts`); the admin Translations and Languages pages from
  `@anotoki/lib/translations/admin`.

Every page is drawn with the library's kit and shell since this site's 0.4.0 - its own pages too, and the top
bar and the page's frame are the family's (`@anotoki/lib/shell`): `@anotoki/lib/styles` in `styles.scss` with
this site's colours over the family's tokens, light and dark, and the pieces each from its own entry point
(never the `@anotoki/lib/ui` barrel). There is no Angular Material and no CDK.

To update it, both halves together (while its version is 0.x a minor version may break: read its
`CHANGELOG.md` first):

```bash
cd backend && composer update anotoki/lib --prefer-dist   # after raising the constraint in composer.json, where the new version needs it
cd frontend && bun install && npx -y npm@11 install --package-lock-only   # after putting the new release's tarball URL in package.json: both lock files
```

Composer reads the library through GitHub's API, 60 requests an hour without a token; when it stops on that
limit, wait for the reset (`https://api.github.com/rate_limit` says when). It is installed from GitHub only,
never from a local copy. Then run the tests, the production build and the browser check.

## Testing

```bash
cd backend && php tests/run.php            # every backend test file (or one: php tests/auth.test.php)
cd frontend && npx ng test --watch=false   # frontend unit tests (Vitest)
cd frontend && npx ng build                # the production build must pass too
bun run format:check                       # Prettier on every file of both halves, at the root (bun install there first)
```

The backend tests need `config/database.local.php` (run setup first): each test file creates its own
throwaway database on that server (`build_analyzer_test_…`), builds it from the migrations and drops it when
it ends. Nothing reaches the network - build pages and API answers are small fixtures, and a fake HTTP client
fails a test on any request it was not told about. IAM tokens are signed by the library's test IAM
(`Anotoki\Lib\Auth\Testing\TestIam`: its key, its tokens - `iamToken()` - and its answers as files - whether
sign-in is required, `iamRequiresSignIn()`, false unless a test says otherwise; the accounts it deleted);
`tests/stand_in_iam.test.php` signs in through the library's stand-in IAM
(`vendor/anotoki/lib/backend/testing/stand-in-iam.php`, run as `php -S` on a throwaway port 8590-8599 and stopped
at the end) as a browser would, reads whether sign-in is required from it (the library's `SignInRule`: kept a
minute, 404 = required, out of reach = the last answer), its sign-in hint, and the accounts it deleted (the
scrub). `setup.test.php`
checks "Test connection" with a throwaway login (`build_analyzer_test_…_nocreate`) when the test login may make
one, and drops it at the end.

The browser check (the `verify-ui` skill) runs the site end to end in headless Edge, signed in through the
stand-in IAM, on a throwaway database - nobody, a visitor's import and the move at sign-in, a member's own
data, an editor and an admin, the top bar at 360-1280 px in light and dark with a real Tab press, Slovak at
390 px with no key on the page, the account's theme, dates and language saved from the bar to the
stand-in IAM, the automatic sign-in by the IAM's hint, the screen of a sign-in that failed, the feedback form,
the People page and the admin tools (light and dark, 1280 and 390 px), Terms, Privacy and Contact from the
footer (1280 and 390 px, light and dark, English and Slovak; read while sign-in is required too), and the site
while an update waits and while it is not set up (the site gate's pages with no page made behind them, the
Migrations page, Sign in from the waiting page):

```bash
node .claude/skills/verify-ui/scripts/run.mjs [--no-build] [--keep] [--measure]   # from the root; ports 8590 and 8597
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
`mbstring`, `curl`, `dom` and `zlib` (the backups), and a **PostgreSQL** database made in the hosting panel - the
setup page checks each extension. No shell, cron job,
queue worker or long-running process is needed: files go up by FTP, the database is built by `setup.php` and
later changed on the admin panel's Migrations page.

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
files; old hashed `*.js` do no harm). When it brings new migrations, **the site is closed from the moment the
files are up until they are applied** (the site gate, as on every anotoki site): everybody else reads "The
site is being updated" in their language, and their page comes back by itself once the update is in;
signed in as the site's ADMIN you read "A database update is waiting" with **Open Migrations** - the one page
that works then (not signed in: that page's **Sign in** brings you back to it); it too asks again by itself,
and opens the site once another administrator has applied the update. Back the database up (hosting panel)
and press **Apply pending** there at once - the site also backs its database up itself just before it applies
one (the Backups page, "before an update"). `config/*.local.php` and `storage/` stay as they are.

- **The release that brings the site's words** (migration 003): upload, then press **Apply pending** on the
  Migrations page. Until then the site reads the English compiled into the app - Slovak readers too - and afterwards its
  words come from the database. A tab that was open keeps its words until its next navigation or reload.
- **A release that adds strings**: they are there once Apply is pressed - and until then the site is closed
  anyway. Upload and press Apply in one sitting.
- **Version 0.3: visitor accounts and the family's bar** (migration 004, then the IAM's migration 007),
  in this order: make sure an account holds ADMIN on `build-analyzer` in the IAM *before* uploading; upload;
  sign in with it and press **Apply pending** on `/admin/migrations` at once (until then the site is closed to
  everybody else); then apply IAM 007. If nobody holds ADMIN here yet, apply IAM 007 right after the upload, then
  Apply. Then check in a private window that a file imports without signing in and stays after a reload. The
  whole sequence: [docs/operations.md](docs/operations.md), "Deploying".
- **The family's site gate and Migrations page** (version 0.4.0: anotoki-lib in `vendor/` and in the app,
  migration 005): upload `vendor/` with the rest (it holds `vendor/anotoki/lib`), then Apply on
  `/admin/migrations` - 005 only deletes the six words of the old "Not set up yet" page. From this version on
  every upload with a migration closes the site until it is applied, and the setup page is never offered
  again once `storage/installed.lock` is there.
- **The site's words through the anotoki library** (version 0.4.0: anotoki-lib 0.3.0, migration 006 and the
  library's two files): Export each language on the Translations page first, upload with `vendor/`, then one
  Apply runs 006 (the language button's two strings move to the library's keys, every rewording kept) and the
  library's `anotoki_translations/001` (checks this site's tables, changes nothing) and `002` (its own words,
  never over a string that is there). [docs/operations.md](docs/operations.md), "Deploying".
- **Signing in, people and the admin tools through the anotoki library** (version 0.4.0: anotoki-lib 0.5.0,
  migrations 007-010 between the library's `anotoki_admin` and `anotoki_translations` 003-004 / `anotoki_people`):
  Export each language first, upload with `vendor/`, then one Apply runs them all after a backup of the database
  as it was (007: `users.anonymized_at`; 008: what the Laravel version left dropped - its login tables
  `laravel_users`, `laravel_password_reset_tokens`, `laravel_sessions` and its queue's `laravel_jobs`,
  `laravel_job_batches`, `laravel_failed_jobs`, only `laravel_migrations` kept; 009: the sign-in screen's words
  under the library's keys, every rewording kept, and the feedback button's and its sections'; 010: the top
  bar's words under the library's keys likewise - the bar is the family's now). Then delete that
  "before-migration" backup on the Backups page once the site works: it still holds those tables. Nothing on disk
  is deleted by the update (`storage/logs/app.log` stays; the error log and the backups are new beside it).
  [docs/operations.md](docs/operations.md), "Deploying".
- **Terms, Privacy and Contact, and the kit's newer pieces** (version 0.4.0: anotoki-lib 0.6.2, migrations 011
  and 012 and the library's `anotoki_translations` 005-011): in the same Apply as the files above, or after them -
  Export each language first, upload with `vendor/`, Apply at once. 011 adds the site's facts (`legal.*`), the
  pages' tab titles and the footer's fan clause, in English and Slovak; 012 the words the kit's stepper and a
  build's tab badge are heard by; the library's files add the pages' own words and the kit's. Then the foot of
  every page has Terms · Privacy · Contact and the fan clause, and `/privacy` links "your anotoki account" to the
  IAM's own Privacy page. A change to what the site keeps, or for how long, changes the facts' strings (a
  migration) and `SITE_LEGAL_UPDATED` (`frontend/src/app/core/legal-facts.ts`). [docs/operations.md](docs/operations.md), "Deploying".
- **The IAM's sign-in switch** (now the library's `SignInRule`): upload only once the IAM answers
  `https://iam.anotoki.eu/api/public/applications/build-analyzer` with 200 - before that the site requires
  sign-in (fail closed) and turns visitors away. [docs/operations.md](docs/operations.md), "Deploying".
- **The account's theme, dates and language** (version 0.4.0: the IAM's browser client, now the library's; no
  migration here):
  upload in either order with the IAM's migration 015 - before it the site keeps its own settings, as it did.
  Then, signed in, the bar's theme and language are saved to the anotoki account and What's new writes dates in
  the account's order. [docs/operations.md](docs/operations.md), "Deploying".
- **Each installation holds its own strings.** Strings reworded in the browser live only in that
  installation's database; your computer and the host each have their own, and no upload carries them.
  Export a language (Translations page, or `php translations.php --export=sk file` on your computer) before a
  database is replaced, and import it on the other installation to carry edits across. Deleting an added
  language deletes its strings.

**Replacing an install of the Laravel version**: its layout was different (a `public/` document root and
Laravel's folders beside it). Upload the new build into a fresh folder, make that the site's document root,
upload `setup.php`, and point it at the existing database - it is brought forward with all its data. When
the new site works, delete the old folder, including its `.env`.
