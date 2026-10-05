# Build analyzer

Imports Genshin Impact / Honkai: Star Rail accounts (the Character Showcase by UID; GOOD files and HSR scanner
exports), reads recommended builds from community build sites when an editor syncs them, and shows per
character what to improve. Backend: PHP 8.4+ / Slim 4 / PostgreSQL in `backend/` (no Laravel, no Illuminate
components). Frontend: Angular 21 + Angular Material in `frontend/`. Hosted at builder.anotoki.eu. Sign-in is
through the anotoki IAM only - on the owner's computer too - and every anotoki account may use the site
(each person has their own accounts; every account holds MEMBER, an ordinary person here); the builds browser and
the sources' freshness are public - unless the IAM's "Sign-in required" switch for the application is on, which
the server reads from the IAM (SDK 1.1.0, `App\Auth\SignInPolicy`) and relays in `/api/meta`
(`auth.login_required`): then nobody and no visitor key gets anything but meta, the strings and the icons. Roles here:
EDITOR runs the syncs and rewords the site's strings; ADMIN does that and also switches sources, applies
database updates, adds and deletes languages and deletes a person's data. Deployable on FTP-only shared
hosting: no shell, cron, queue worker or long-running process. The member pages read in English or Slovak:
the site's words live in the database (migration 003) and editors reword them in the browser
(`/admin/translations`, `/admin/languages`); the admin panel (`/admin/...`) stays English.

**Version 0.3 - the family port** (the anotoki sites made one way): IAM-only sign-in with the EDITOR / ADMIN
split; **visitor accounts** (somebody not signed in imports and uses their own accounts under a visitor row
with a negative id, opened by an `X-Visitor-Key` the server made; moved into the person's data at sign-in);
each signed-in person's **own accounts as a file** (`GET /api/me/export`, `POST /api/me/import`: the newer
copy of an account wins, an import never deletes one); every page but the admin panel open to everybody
(nobody / a visitor / signed in), the admin panel under `/admin/` (editors: Sources, Translations; admins also
Languages, People and Migrations); and **the family's top bar** (`layouts/top-bar/`) with the version and What's new in the
footer. Migration **004** (the visitors' schema and every string of it, the bar's included) is complete: on a
host it is applied on the Migrations page (Apply pending) by an ADMIN at once after the upload, and **then** the IAM's migration
007 (docs/operations.md, "Deploying 0.3"). The browser check (`verify-ui`) runs all of it on a throwaway
database with the stand-in IAM.

## How to work in this repo

1. **Read the knowledge base page for the area you touch** before changing it — `docs/README.md` is the index:
   architecture (incl. setup gate, sign-in, neutral names, syncs and the site's words), data model and migrations, stats & slots,
   name matching, build sources, import formats, analysis, operations (configuration, commands, sign-in modes,
   deploying, machine quirks, troubleshooting), decisions. Setup, running, testing and deploying for people:
   `DEV.md`.
2. **Use the project skills** for these tasks (invoke them with the Skill tool, or read `.claude/skills/*/SKILL.md`):

   | Task | Skill |
   | --- | --- |
   | add or rewrite a build website scraper | `add-build-source` |
   | sync failed/partial, "! unknown weapon/set", wrong or missing builds, site redesign | `debug-build-sync` |
   | new import format / importer fix | `add-import-format` |
   | scores, grades, recommendations, inventory suggestions | `tune-analysis` |
   | any UI change or "look at the app" (the browser check: `node .claude/skills/verify-ui/scripts/run.mjs`) | `verify-ui` |

3. **Delegate to the project agents** when it saves context or runs in parallel:
   `build-source-researcher` (how a site stores its builds), `sync-doctor` (diagnose sync problems, proposes
   fixes), `analysis-auditor` (explain / audit scores, read-only), `build-analyzer-reviewer` (review a finished
   change).
4. **Keep docs current**: when behavior described in `docs/`, `DEV.md` or the README changes, update it in
   the same change; record notable choices in `docs/decisions.md`.

## Environment (Windows machine; details in docs/operations.md)

- `php` on PATH is PHP 8.5 with its own php.ini (pdo_pgsql, curl, mbstring, openssl, intl…). The host runs PHP
  8.4, so avoid what 8.5 deprecates (`$http_response_header`, `curl_close()`, `(integer)`-style casts). Where
  `php` is too old, `backend/tools/php.sh …` (bash) runs `BUILD_ANALYZER_PHP` instead.
- PostgreSQL 18 on 5432. The app's connection is `backend/config/database.local.php` (written by `setup.php`;
  never print it). The tests make and drop their own `build_analyzer_test_*` databases on that server (and
  `setup.test.php` a throwaway login, dropped after). Throwaway servers (the stand-in IAM of the tests and
  the browser check) go on 8590-8599; the owner's own servers may run on 4200-4700 and 8000-8500.
- npm 10.9.2 crashes on install here → `npx -y npm@11 install|ci`. Node 22.15 (hence Angular 21).
- No Python (`python` is the Store stub); script with node or PHP.
- Tool shells reset their working directory: start commands with `cd <repo root> &&`. Put longer PHP snippets
  into a scratch file (outside the repo) that requires `backend/vendor/autoload.php` and uses `App\Cli::app()`.
- Configuration is only `backend/config/{database,auth}.local.php` (required) and `fetch.local.php`
  (optional: defaults in `Config::FETCH_DEFAULTS`; setup writes it with them only when it is missing) -
  gitignored, written by `backend/setup.php`; no `.env`. One environment variable, for the browser check
  alone: `BUILD_ANALYZER_TEST_CONFIG` names a settings folder that stands in for `config/` and `storage/` -
  honoured only for a `build_analyzer_test_` database (anything else is "not set up"). Without the two the API
  answers 503 `not_set_up`. Setup asks for the database and the IAM only (defaults `anotoki_builder`, port
  5432). Sign-in is the IAM's, always: a `mode` or `local_user_id` left in an older `auth.local.php` is ignored
  and opens nothing.
- Database times are UTC (`TIMESTAMPTZ`). Nothing is scheduled.
- Git Bash: heredocs with apostrophes or backslashes can come out wrong — create files with the editor tools.
- Git repository on `main` with a GitHub remote (`origin`); commit or push only when the user asks.

## Commands

```bash
cd backend && php tests/run.php                      # backend tests (throwaway databases, 22 files, ~280 tests)
(cd frontend && npx ng build && npx ng test --watch=false)   # Vitest: 48 spec files, ~530 tests
node .claude/skills/verify-ui/scripts/run.mjs [--no-build] [--keep]   # the browser check (8590 + 8597, a throwaway database)
cd backend && composer start                         # API: php -S localhost:8400 router.php (+ setup.php, + the built app)
cd frontend && npx ng serve                          # http://localhost:4600, proxies /api and /setup.php
cd backend && php migration.php [--status|--dry-run] # apply pending migrations
cd backend && php sync-builds.php [source] [--character=slug] [--force] [--check-names]   # stepped runs, like the Sources page
cd backend && php sync-builds.php --dry-run --cache-hours=96    # replay cached pages vs stored builds, writes nothing
cd backend && php sync-catalog.php [genshin|hsr] [--force]
cd backend && php import-account.php <genshin|hsr> <uid|file> --user=IAM_ID [--account=ID]
cd backend && php translations.php [--status | --export=sk [file] | --import=sk file]   # the site's strings
cd backend && php tools/build.php [--skip-frontend]  # ../dist/: what to upload to the host
```

## Code map (backend paths under `backend/`)

- Entry: `api/index.php` → `api/app.php` (`buildAnalyzerApi()`), `api/routes/*.php`, `api/middleware/*.php`; container `src/App.php`, config `src/Config.php`
- Database: `src/Db/Db.php` (PDO helpers), `src/Db/Migrator.php`, `migrations/*.sql`; rows `src/Models/*`, queries `src/Repositories/*`
- Stats & slots: `src/Support/Stats.php`, `src/Enums/Game.php`, `src/Support/Talents.php` (canonical keys: `critRate_`, `atk_`, `spd`, `pyro_dmg_`…)
- Sign-in: `src/Auth/IamGuard.php` (the token, nothing else - only the Bearer scheme counts; + `UserProvisioner`, `Role`: ADMIN, EDITOR, STAFF), `src/Support/Iam/` (the vendored IAM SDK 1.1.0 + the verifier's wrapper), whether sign-in is required `src/Auth/SignInPolicy.php` (`IamSignInPolicy`: the SDK's `anotoki_iam_login_required()`, kept in `storage/cache/iam/`; the tests' `tests/support/FixedSignInPolicy.php`, `T::$signIn`, `realSignInPolicy()`), `api/middleware/auth.php` (`requireSignIn`, `requireOwner`, `requireRole`, `optionalSignIn`, `currentUser[OrNull]`); the stand-in IAM `tests/tools/fake-iam.php` (also the IAM's public answer about the application, `login_required` false by default); frontend `core/auth.ts` (`AuthService`: `available`, `signedIn`, `loginRequired` - the IAM's switch from `/api/meta`, `isStaff`, `isAdmin`; `login()` waits for `core/saves-in-flight.ts`), `core/api.ts` (`ISSUED_AS`: each request is the person's or the visitor's as it is issued), `core/auth.interceptor.ts` (the token - or, with none, the request is never sent - for a person's request, the visitor key for a visitor's while sign-in is not required), `core/auth.guards.ts` (`signInGuard` on `/admin`, a visitor too; `siteSignInGuard` on everybody's pages: sign-in first while required, a refused sign-in shown once; `adminOnlyGuard`), `core/sign-in.ts` (`signInOrSayWhy()`), `core/iam/` (vendored SDK), `layouts/admin-layout/`
- Migrations (ADMIN, the IAM's page): `api/routes/admin.php` (`GET /api/admin/migrations` {applied: [{name, applied_at}], pending: [{name}]}, `POST /api/admin/migrations/apply` {applied, failed, error} under the `migrate` lock, 409 `migrations_busy`), `src/Db/Migrator.php` (`status()`, `applyPending()`; each file under an advisory lock, never twice; the routes take it from the container, so a test can give it another folder); frontend `pages/admin/migrations/` (the Sources page only notes that updates wait, with a link)
- People (ADMIN): `api/routes/people.php` (list, delete a person's data), `src/Repositories/Users.php` (`withCounts()`, `deletePerson()`); frontend `pages/admin/people/`
- Visitors: `src/Auth/Visitors.php` (find / make / unmake / sweep / move; the only code that writes a visitor row, through `Users`), `requireOwner($app, mayMakeVisitor)` + `makeVisitor($app)` in `api/middleware/auth.php`, `api/routes/visitor.php` (`POST /api/visitor/move`), `src/Web/ClientAddress.php` (which address the limits count), `src/Web/AfterResponse.php` (the clean-up after the answer), limits in `config/app.php` (`visitors`, `client_address`), `migrations/004_visitors_and_family_bar.sql`, `tests/visitors.test.php`; frontend `core/visitor-key.ts` (`VisitorKey`: the key in localStorage, read at send time, the first one kept, forgotten on `visitor_unknown`, kept but not sent while sign-in is required, the move on sign-in), `core/storage.ts`, `shared/visitor-notice/`, `shared/visitor-hero/`
- A person's own accounts as a file (signed in only; never a visitor's): `api/routes/me.php` (`GET /api/me/export`, `POST /api/me/import[?dry_run=1]`), `src/Services/Transfer/AccountsFile.php` (format, strict reading, writing only what it reads), `AccountsTransfer.php` (export; import: match by game + UID or game + `created_at` in UTC to the second, the newer `last_import_at` wins, never deletes, unknown catalog keys skipped and counted, the person's `users` row `FOR UPDATE` via `Users::lockForWrite()`, which `AccountImporter` takes too), limits `config/app.php` `me`, `tests/data_transfer.test.php`; frontend `shared/data-card/` (export, import: the dry run's preview, then Confirm), `core/download.ts`
- Catalog / names: `src/Services/Catalog/Catalog.php` (+ aliases in `config/app.php`), `CatalogSync.php`
- Imports: `src/Services/Import/ImportService.php` → parsers → `ParsedAccount` → `AccountImporter.php` (always for one user)
- Scrapers: `src/Services/Builds/Sources/*` (+ `Parsing/AstroIslands`, `NextFlight`, `StatTargets`), `BuildSync.php`, `BuildSourceRegistry.php` ("Source N" labels)
- Builds files (a source's builds exported / imported between installations): `src/Services/Builds/BuildFile.php` (format, strict reading), `BuildTransfer.php`; frontend `pages/admin/sources/build-file.ts`
- Syncs: `src/Services/Sync/SyncRunner.php` (steps, lock, due runs), `src/Http/Fetcher.php` (per-host delay in the cache, `FetchDeferred`); frontend `core/sync-driver.ts`
- Analysis: `src/Services/Analysis/BuildEvaluator.php`, `ItemScorer.php`, `SetMatcher.php`, `CharacterAnalyzer.php`
- Output: `src/Presenters/*` (mirrored in `frontend/src/app/core/models.ts`), `src/Support/Neutral.php`
- What the server says as a kind (the page words it): recommendations `BuildEvaluator::KINDS`; import errors and warnings `src/Services/Import/ImportKinds.php` (`ERRORS`, `WARNINGS`; `ImportException` `kind` / `params`, `ParsedAccount::warn()`)
- The site's words, backend: `migrations/003_languages_and_translations.sql` (tables, English + Slovak, every string), `src/Repositories/Translations.php` (`available()`), `src/Services/Translations/{TranslationCheck,TranslationEditor}.php`, `src/Presenters/TranslationPresenter.php`, `api/routes/translations.php`, `translations.php` (→ `src/Commands/Translations.php`), the key scan `tests/support/translation_keys.php`
- The site's words, frontend `core/i18n/`: `translation.ts` (`TranslationService`), `en.ts` + `en/*.json` (the keys and the compiled English), pipes `translate` / `translatePlural` / `localDate`, `dynamic-keys.ts` (the run-time families), `error-text.ts`, `recommendation-words.ts`, `import-words.ts`, `title-strategy.ts`, `testing.ts`; game terms `core/game-terms.ts`, `shared/build-terms.ts`; the backend test that holds it all together `tests/translation_catalogue.test.php`
- Translations and Languages pages (editors; the Languages page and adding / deleting a language: admins): `pages/admin/translations/*`, `pages/admin/languages/*`, `core/localization-drafts.ts` (`LocalizationDrafts`), `core/unsaved-changes.guard.ts`, `shared/confirm-dialog.*`
- Setup / deploy: `setup.php` + `src/Setup/Installer.php`, `tools/build.php` (the upload), `tools/php.sh`
- The frame (the anotoki family's top bar, as the survey's, made of Angular Material pieces and this app's own menu markup - not mat-menu, which leaves a Tab to the browser): `layouts/app-layout/` (everybody's pages: bar, page, footer), `layouts/admin-layout/` (the bar with the admin sections, the three states, footer), `layouts/top-bar/` (`APP_LINKS`, `ADMIN_LINKS`, `PHONE_BAR_MAX = 880` and the admin panel's `ADMIN_PHONE_BAR_MAX = 960` (its brand "anotoki admin panel", the app's name in the link's name), the menus' keyboard rules; the keys `brand.*`, `topbar.*`), `layouts/footer/` (version, What's new), `shared/brand/` (the anotoki mark `public/anotoki.png`), `shared/theme-toggle/` + `core/theme.ts` (`ThemeService`), `shared/language-switcher/`; `app.ts` is the boot decision only; the browser check `.claude/skills/verify-ui/scripts/run.mjs` + `flow.mjs`, its database `tests/tools/browser-db.php`
- Frontend pages: `frontend/src/app/pages/*`; shared UI: `frontend/src/app/shared/*`; wizard guides: `pages/import/import-guides.ts` (keys; the English in `core/i18n/en/import.json`); changelog: `frontend/public/changelog.json` (+ `backend/src/Version.php`)

## Rules that matter

- Everything compares canonical keys; resolve names through `Catalog`, never by ad-hoc string matching.
- Game accounts are per user: every account / import query goes through `Accounts::owned()` / `findOwned()` or
  takes the owner; never look an account up by id or UID alone. The catalog, builds and sources are shared,
  and imports never create catalog rows (`create: false`).
- Who a request is comes only from the verified IAM token - never from a body, a parameter, an address or a
  header of the site's own - with one deliberate exception, read only by `requireOwner()` and only while the
  IAM does not require sign-in here: a visitor key the server made (`X-Visitor-Key`, its SHA-256 a visitor row's), which reaches that visitor's own accounts and
  imports and nothing else (no role, no admin panel, no own-data export / import). A request that sends an
  `Authorization: Bearer` header is that person or it is refused (401 `sign_in_required`, public routes too): it
  is never answered as if it had sent none, and never falls back to the key (another scheme, `Basic`, is no
  credentials). While the IAM requires sign-in, nobody and a visitor key get 401 `sign_in_required` from
  `optionalSignIn()` and `requireOwner()` - the key refused before it is looked up; only `/api/meta`, the
  strings and the icons stay open, and the move still takes a key with a token. A new route with no sign-in
  check must decide what it does then. The key is never logged, never in a
  URL or an answer's body (only the `X-Visitor-Key` response header of the import that made the visitor);
  only `App\Auth\Visitors` makes, finds, sweeps or moves a visitor row, and tests never assume a visitor's id. Role checks sit
  on route groups, so a route added later cannot go without one: `requireRole($app, ...Role::STAFF)` for the
  editors' routes (syncs, their history, a source's builds file, the strings grid, the languages list),
  `requireRole($app, Role::ADMIN)` for the admins' own (sources on / off, migrations, adding / renaming /
  switching / deleting a language, People); own data `requireOwner($app)`; what only a signed-in person has
  (the own accounts file `/api/me/*`, the move) `requireSignIn($app)`; public routes `optionalSignIn($app)`.
  403 `editor_only` / `admin_only`. MEMBER (every account holds it) is an ordinary signed-in person, as is
  one with no role: no check needs it, none refuses it.
- The UI names no other website, service or tool (labels, links, credits, errors, tooltips, titles, the
  changelog): sources are "Source N", the API never sends a site's name or address, text from elsewhere goes
  through `Neutral::text()`. Game names are fine. That covers every string in the database, seeded or edited:
  `TranslationCheck` refuses one that names another site or holds a web address (422 `names_another_site`).
- No request may run long or sleep long: sync work goes through `SyncRunner` steps (bounded by `step_seconds`),
  crawl delays become `FetchDeferred`. No queue jobs, schedules or background processes.
- Scrapers go through `Fetcher`, respect robots.txt / crawl delays (Prydwen 10 s) and are developed against
  cached or saved pages, not by repeatedly hitting live sites. Samples go outside the repo.
- Tests use small synthetic fixtures and never touch the network (`Tests\Support\FakeHttpClient` fails on any
  request it was not told about; `FakeBuildSource`); API tests sign their own tokens (`iamToken()`,
  `Client::asIam()`; `tests/stand_in_iam.test.php` signs in through the stand-in IAM itself), and each test
  file uses its own throwaway database.
- Queries are parameterised; table and column names come from literals in the code, never from input.
- Schema changes are new files in `backend/migrations/`; never edit one that has been applied. Data-changing
  migrations end with a `DO $$` verification gate.
- Angular components always have three files: `.ts`, `.html`, `.scss`.
- The vendored IAM SDK files (`backend/src/Support/Iam/anotoki_iam.php`, `frontend/src/app/core/iam/*`) stay
  byte-identical to `anotoki-iam/sdk/` (in this repository's LF line endings: the backend's `.gitattributes`); update
  by copying.
- What a member reads is a key, worded by the page in the reader's language: never a sentence written into a
  template or a component, and never one kept in state (state holds keys, kinds and errors; a template or a
  `computed()` words them). Recommendations, import errors and import warnings are **kinds with params** that
  the page words (`recommendation.<kind>`, `import.error.<code>`, `import.warning.<code>`); their English
  `message` stays short and actionable, kept for the command line and for rows stored before kinds - a row
  without a known kind shows that English with `lang="en"`. A new string is its key and English in
  `core/i18n/en/*.json` plus a new migration with the key, its description, its English and its Slovak (the
  catalogue test holds the two to each other). English is the fallback; a key the database lacks shows as
  the key, on purpose.
- Slovak here is informal ("ty"). Game terms and game values are never translated: stat names, slot, talent,
  set, weapon and character names, "Lv.", "4pc", UID, grades, and the game's notation ("62.4%", "R1"). They
  reach a sentence as a `{placeholder}` or stand before a colon; no Slovak word has to agree with one.
- English pages: the admin panel, `/admin/...` (Sources, Translations, Languages, People, Migrations), keeps its English
  code and reads English with `<html lang="en">` whatever language is chosen; the server's admin-area sentences
  stay English. Every other page is open to everybody and reads in the reader's language.
- Never commit or print secrets: `backend/config/*.local.php`, database credentials, tokens,
  player cookies. Don't edit the user's `php.ini` or global tools without asking.
- Done means: backend tests pass (`php tests/run.php`), `ng build` + `ng test` pass, UI checked with `verify-ui` (its run.mjs passes, and the screenshots are looked at)
  when it changed (a member page in English and in Slovak at 390 px), docs updated.
