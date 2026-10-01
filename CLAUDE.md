# Build analyzer

Imports Genshin Impact / Honkai: Star Rail accounts (the Character Showcase by UID; GOOD files and HSR scanner
exports), reads recommended builds from community build sites when an admin syncs them, and shows per
character what to improve. Backend: PHP 8.4+ / Slim 4 / PostgreSQL in `backend/` (no Laravel, no Illuminate
components). Frontend: Angular 21 + Angular Material in `frontend/`. Hosted at builder.anotoki.eu with sign-in
through the anotoki IAM (each person has their own accounts), or run on the owner's computer without sign-in.
Deployable on FTP-only shared hosting: no shell, cron, queue worker or long-running process. The member pages
read in English or Slovak: the site's words live in the database (migration 003) and the owner rewords them in
the browser (`/translations`, `/languages`); the Sources page and those two owner pages stay English.

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
   | any UI change or "look at the app" | `verify-ui` |

3. **Delegate to the project agents** when it saves context or runs in parallel:
   `build-source-researcher` (how a site stores its builds), `sync-doctor` (diagnose sync problems, proposes
   fixes), `analysis-auditor` (explain / audit scores, read-only), `build-analyzer-reviewer` (review a finished
   change).
4. **Keep docs current**: when behavior described in `docs/`, `DEV.md` or the README changes, update it in
   the same change; record notable choices in `docs/decisions.md`.

## Environment (Windows machine; details in docs/operations.md)

- `php` on PATH is PHP 8.5 with its own php.ini (pdo_pgsql, curl, mbstring, openssl, intl…). The host runs PHP
  8.4, so avoid what 8.5 deprecates (`$http_response_header`, `curl_close()`, `(integer)`-style casts). Where
  `php` is too old, `scripts/php.sh …` (bash) and `.\dev.ps1 …` take `BUILD_ANALYZER_PHP` (or the gitignored
  `dev.local.ps1`).
- PostgreSQL 18 on 5432. The app's connection is `backend/config/database.local.php` (written by `setup.php`;
  never print it). The tests make and drop their own `build_analyzer_test_*` databases on that server.
- npm 10.9.2 crashes on install here → `npx -y npm@11 install|ci`. Node 22.15 (hence Angular 21).
- No Python (`python` is the Store stub); script with node or PHP.
- Tool shells reset their working directory: start commands with `cd <repo root> &&`. Put longer PHP snippets
  into a scratch file (outside the repo) that requires `backend/vendor/autoload.php` and uses `App\Cli::app()`.
- Configuration is only `backend/config/{database,auth,fetch}.local.php` (gitignored), written by
  `backend/setup.php` - no `.env`, no environment variables. Without them the API answers 503 `not_set_up`.
  `auth.local.php` `mode: none` = no sign-in (everything is `local_user_id`, only this computer's own pages);
  never on a host (setup there always writes `iam`).
- Database times are UTC (`TIMESTAMPTZ`). Nothing is scheduled.
- Git Bash: heredocs with apostrophes or backslashes can come out wrong — create files with the editor tools.
- Git repository on `main` with a GitHub remote (`origin`); commit or push only when the user asks.

## Commands

```bash
cd backend && php tests/run.php                      # backend tests (throwaway databases, 18 files, ~216 tests)
(cd frontend && npx ng build && npx ng test --watch=false)   # Vitest: 36 spec files, ~415 tests
cd backend && composer start                         # API: php -S 127.0.0.1:8400 router.php (+ setup.php, + the built app)
cd frontend && npx ng serve                          # http://localhost:4600, proxies /api and /setup.php
cd backend && php migration.php [--status|--dry-run] # apply pending migrations
cd backend && php sync-builds.php [source] [--character=slug] [--force] [--check-names]   # stepped runs, like the Sources page
cd backend && php sync-builds.php --dry-run --cache-hours=96    # replay cached pages vs stored builds, writes nothing
cd backend && php sync-catalog.php [genshin|hsr] [--force]
cd backend && php import-account.php <genshin|hsr> <uid|file> [--account=ID] [--user=IAM_ID]
cd backend && php translations.php [--status | --export=sk [file] | --import=sk file]   # the site's strings
php scripts/build-dist.php                           # dist/: what to upload to the host (.\dev.ps1 dist)
.\dev.ps1 start                                      # API :8400 + Angular :4600 (nothing else needed)
```

## Code map (backend paths under `backend/`)

- Entry: `api/index.php` → `api/app.php` (`buildAnalyzerApi()`), `api/routes/*.php`, `api/middleware/*.php`; container `src/App.php`, config `src/Config.php`
- Database: `src/Db/Db.php` (PDO helpers), `src/Db/Migrator.php`, `migrations/*.sql`; rows `src/Models/*`, queries `src/Repositories/*`
- Stats & slots: `src/Support/Stats.php`, `src/Enums/Game.php`, `src/Support/Talents.php` (canonical keys: `critRate_`, `atk_`, `spd`, `pyro_dmg_`…)
- Sign-in: `src/Auth/IamGuard.php` (+ `UserProvisioner`, `AuthMode`, `Role`), `src/Support/Iam/` (vendored IAM verifier + wrapper), `api/middleware/auth.php` (`requireRole`); frontend `core/auth*.ts`, `core/iam/` (vendored SDK)
- Catalog / names: `src/Services/Catalog/Catalog.php` (+ aliases in `config/app.php`), `CatalogSync.php`
- Imports: `src/Services/Import/ImportService.php` → parsers → `ParsedAccount` → `AccountImporter.php` (always for one user)
- Scrapers: `src/Services/Builds/Sources/*` (+ `Parsing/AstroIslands`, `NextFlight`, `StatTargets`), `BuildSync.php`, `BuildSourceRegistry.php` ("Source N" labels)
- Builds files (a source's builds exported / imported between installations): `src/Services/Builds/BuildFile.php` (format, strict reading), `BuildTransfer.php`; frontend `pages/sources/build-file.ts`
- Syncs: `src/Services/Sync/SyncRunner.php` (steps, lock, due runs), `src/Http/Fetcher.php` (per-host delay in the cache, `FetchDeferred`); frontend `core/sync-driver.ts`
- Analysis: `src/Services/Analysis/BuildEvaluator.php`, `ItemScorer.php`, `SetMatcher.php`, `CharacterAnalyzer.php`
- Output: `src/Presenters/*` (mirrored in `frontend/src/app/core/models.ts`), `src/Support/Neutral.php`
- What the server says as a kind (the page words it): recommendations `BuildEvaluator::KINDS`; import errors and warnings `src/Services/Import/ImportKinds.php` (`ERRORS`, `WARNINGS`; `ImportException` `kind` / `params`, `ParsedAccount::warn()`)
- The site's words, backend: `migrations/003_languages_and_translations.sql` (tables, English + Slovak, every string), `src/Repositories/Translations.php` (`available()`), `src/Services/Translations/{TranslationCheck,TranslationEditor}.php`, `src/Presenters/TranslationPresenter.php`, `api/routes/translations.php`, `translations.php` (→ `src/Commands/Translations.php`), the key scan `tests/support/translation_keys.php`
- The site's words, frontend `core/i18n/`: `translation.ts` (`TranslationService`), `en.ts` + `en/*.json` (the keys and the compiled English), pipes `translate` / `translatePlural` / `localDate`, `dynamic-keys.ts` (the run-time families), `error-text.ts`, `recommendation-words.ts`, `import-words.ts`, `title-strategy.ts`, `testing.ts`; game terms `core/game-terms.ts`, `shared/build-terms.ts`; the backend test that holds it all together `tests/translation_catalogue.test.php`
- Owner pages: `pages/translations/*`, `pages/languages/*`, `core/localization-drafts.ts` (`LocalizationDrafts`), `core/unsaved-changes.guard.ts`, `shared/confirm-dialog.*`
- Setup / deploy: `setup.php` + `src/Setup/Installer.php`, `../scripts/build-dist.php`
- Frontend pages: `frontend/src/app/pages/*`; shared UI: `frontend/src/app/shared/*`; wizard guides: `pages/import/import-guides.ts` (keys; the English in `core/i18n/en/import.json`); changelog: `frontend/public/changelog.json` (+ `backend/src/Version.php`)

## Rules that matter

- Everything compares canonical keys; resolve names through `Catalog`, never by ad-hoc string matching.
- Game accounts are per user: every account / import query goes through `Accounts::owned()` / `findOwned()` or
  takes the owner; never look an account up by id or UID alone. The catalog, builds and sources are shared,
  and imports never create catalog rows (`create: false`).
- Who a request is comes only from the verified IAM token (or, in auth mode "none", the configured owner);
  never from request parameters. Admin-only routes use `requireRole($app, Role::ADMIN)`.
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
  `Client::asIam()`), and each test file uses its own throwaway database.
- Queries are parameterised; table and column names come from literals in the code, never from input.
- Schema changes are new files in `backend/migrations/`; never edit one that has been applied. Data-changing
  migrations end with a `DO $$` verification gate.
- Angular components always have three files: `.ts`, `.html`, `.scss`.
- The vendored IAM SDK files (`backend/src/Support/Iam/anotoki_iam.php`, `frontend/src/app/core/iam/*`) stay
  byte-identical to `anotoki-iam/sdk/`; update by copying.
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
- English pages: `/sources`, `/translations` and `/languages` keep their English code and read English with
  `<html lang="en">` whatever language is chosen; the server's admin-only sentences stay English.
- Never commit or print secrets: `backend/config/*.local.php`, `dev.local.ps1`, database credentials, tokens,
  player cookies. Don't edit the user's `php.ini` or global tools without asking.
- Done means: backend tests pass (`php tests/run.php`), `ng build` + `ng test` pass, UI checked with `verify-ui`
  when it changed (a member page in English and in Slovak at 390 px), docs updated.
