# Architecture

The build analyzer imports Genshin Impact / Honkai: Star Rail accounts, reads recommended builds from community
build sites when an editor syncs them, and compares every owned character with every build. People sign in
through the anotoki IAM - the only way in, and open to every anotoki account; each person sees only their own
game accounts, while the catalog and the builds are shared (and public).

```text
game data provider ──catalog run──▶ game_characters / game_weapons / game_sets   (names, IDs, rarity, element, icons)
                                   ▲ name/ID resolution (Catalog)
showcase API / GOOD / HSR files ──ImportService──▶ accounts (per user), account_characters, _weapons, _items
build sites ──build run──▶ builds (+ build_sources, sync_runs)
                                   ▼
                     CharacterAnalyzer ──▶ REST API (/api, bearer token) ──▶ Angular app (/)
iam.anotoki.eu ──access token (RS256)──▶ Angular app ──Authorization: Bearer──▶ API (verified locally)
```

The providers behind those arrows are named in [build-sources.md](build-sources.md) and
[import-formats.md](import-formats.md). The UI never names them (see "Neutral names" below).

## Backend (`backend/`, PHP 8.4+, Slim 4, PostgreSQL)

No framework beyond Slim's router and PSR-7 messages: a front controller, route files, middleware, a small
parameterised PDO layer, SQL migrations and plain PHP scripts for the command line - the same shape as the
other anotoki sites (the genshin site, the IAM).

| Area | Where | Notes |
| --- | --- | --- |
| Entry points | `api/index.php` (front controller, `/api/*`), `api/app.php` (`buildAnalyzerApi()`: routes + middleware), `router.php` (PHP's built-in server in development), `setup.php` (installer) | the tests call `buildAnalyzerApi()` in-process |
| Routes | `api/routes/{meta,icons,accounts,imports,visitor,me,builds,sources,sync,admin,translations,people}.php` | each returns `function (Slim\App $slim, App $app)`; the sign-in or role check sits on each route group |
| Middleware | `api/middleware/errors.php` (JSON errors), `security.php` (headers), `site_gate.php` (the site gate - anotoki-lib's `SiteState` and `SiteGate`: 503 `not_set_up` / `update_pending`, its open paths), `auth.php` (`requireSignIn()`, `requireOwner()`, `requireRole()`, `requireTokenRole()`, `optionalSignIn()`, `currentUser()`, `currentUserOrNull()`, `throttle()`) | Slim runs the last one added first |
| Wiring | `src/App.php` (a small container: `get()` builds a class once per request scope, `resetScope()`), `src/Config.php` | `Db` and the HTTP client last the whole process; everything else is per request |
| Configuration | `config/app.php` (committed: sources, catalog, showcase, aliases), `config/{database,auth}.local.php` (per installation, written by `setup.php`; required) and `fetch.local.php` (optional, `Config::FETCH_DEFAULTS` without it) | no `.env`; one environment variable, `BUILD_ANALYZER_TEST_CONFIG`, for the browser check alone (a settings folder standing in for `config/` and `storage/`, honoured only for a `build_analyzer_test_` database) |
| Database | `src/Db/Db.php` (PDO helpers: `one`, `all`, `value`, `insert`, `update`, `run`, `transaction`; `ensureDatabase()`), `src/Db/Migrations.php` (this site's files and bookkeeping table on the anotoki family's engine, anotoki-lib's `Migrator`) | always parameterised; identifiers are literals in the code |
| Rows | `src/Models/*` (plain classes made from rows), `src/Repositories/*` (the queries) | `Accounts::findOwned()` / `owned()` scope every account query to its owner |
| Domain basics | `src/Enums/Game.php`, `src/Support/{Stats,Talents,Key,MainStatValues}.php` | slots, stat keys, label parsing; see [stats-and-slots.md](stats-and-slots.md) |
| Sign-in | `src/Auth/{IamGuard,UserProvisioner,Role}.php`, `src/Support/Iam/IamTokenVerifier.php` (+ the vendored `anotoki_iam.php`); the stand-in IAM of the tests `tests/tools/fake-iam.php` | see "Sign-in" below |
| Outgoing HTTP | `src/Http/Fetcher.php`, `CurlHttpClient.php`, `FetchDeferred.php` | per-host delay kept in the cache table, retries on 429/5xx, optional disk cache, step deadline |
| Cache and locks | `src/Cache/{Cache,Locks}.php` | the `cache` and `cache_locks` tables (the host has no Redis or shared memory) |
| Catalog | `src/Services/Catalog/{Catalog,CatalogSync,SetBonusGroups}.php` | resolve any source's names/IDs to catalog rows; see [name-matching.md](name-matching.md) |
| Imports | `src/Services/Import/ImportService` → parsers → `ParsedAccount` → `AccountImporter`; `ImportKinds.php` | always for one user; errors and warnings are kinds with params; see [import-formats.md](import-formats.md) |
| Own data as a file | `src/Services/Transfer/{AccountsFile,AccountsTransfer,AccountsFileException}.php`, `api/routes/me.php` | a signed-in person's game accounts exported to / imported from a file (never a visitor's); see "Main flows" below and [data-model.md](data-model.md) ("Accounts files") |
| Builds | `src/Services/Builds/{BuildSync,BuildSourceRegistry}.php`, `Sources/*`, `Parsing/*`; `BuildFile.php`, `BuildTransfer.php` | see [build-sources.md](build-sources.md); a source's builds exported to / imported from a file: [data-model.md](data-model.md) ("Builds files") |
| Syncs | `src/Services/Sync/{SyncRunner,RunHandler,RunLog,StepResult}.php` | runs worked through in short steps; see "Syncs" below |
| Analysis | `src/Services/Analysis/*` | recommendations are kinds with params (`BuildEvaluator::KINDS`); see [analysis.md](analysis.md) |
| The site's words | `src/Repositories/Translations.php` (`available()`: are the tables there), `src/Services/Translations/{TranslationCheck,TranslationEditor}.php`, `src/Presenters/TranslationPresenter.php`, `api/routes/translations.php`, migration 003 | see "The site's words" below and [data-model.md](data-model.md) |
| Output | `src/Presenters/*` (accounts, builds, sync runs, icon addresses, translations), `src/Support/Neutral.php` | what the API sends, in the shapes of `frontend/src/app/core/models.ts` |
| Command line | `migration.php`, `sync-builds.php`, `sync-catalog.php`, `import-account.php`, `translations.php` (→ `src/Commands/*`) | loop over the same steps as the Sources page; nothing is scheduled |
| Setup | `setup.php` + `src/Setup/Installer.php` | writes `config/*.local.php`, builds the database; see [operations.md](operations.md) |
| People | `api/routes/people.php`, `src/Repositories/Users.php` (`withCounts()`, `visitorCounts()`, `deletePerson()`) | ADMIN: who used the site, and deleting a person's data |
| Deploy | `tools/build.php` | `dist/` (beside `backend/`), the web root to upload; see DEV.md "Deploying" |

### The site gate

One behaviour for every anotoki site while it is not ready (the shared library anotoki-lib, the owner's
decision of 2026-10-07 - [decisions.md](decisions.md)). For every request the server decides where the site
stands (`api/middleware/site_gate.php`: `siteState()`), afresh each time:

| State | When | What the API answers |
| --- | --- | --- |
| not set up | `Config::load()` found a required local file missing or incomplete (no database, no IAM issuer or client id; the log says which file and which value) | every path `503 {"code": "not_set_up", "installed": …, "message": …}`, with `"setup": "/setup.php"` only while the site was never installed - `storage/installed.lock`, which `setup.php` writes when it finishes on a real host (never on this computer) |
| update pending | a migration file is ready to apply | every path `503 {"code": "update_pending", "message": "The site is being updated. Try again in a few minutes."}` with `Retry-After: 60` - but the open paths (`SITE_OPEN_PATHS`): `/api/meta` (where people sign in), `/api/translations/{code}` (the pages' words; its route answers `translations_unavailable` itself while the tables are not there) and the Migrations page's three routes, `/api/admin/migrations`, `…/file` and `…/apply` (an ADMIN's, judged from the token alone: `requireTokenRole()`). Each is a route exactly, never a prefix: the gate reads the path decoded, as the router does, and neither resolves dot segments, so a crafted `/api/admin/migrations/../x` (or `%2e%2e`, `%2F`) stays closed |
| unavailable | the database cannot be reached | everything passes: the routes answer as they do without a database (500 `server_error`) |
| ready | otherwise | everything passes |

`GET /api/site-status` always answers `{state, installed}` - how the pages learn which - to anybody: it reads no
credentials, so it answers while the IAM requires sign-in too (a 401 would read on the pages as "not
available"), and the frontend sends it neither token nor visitor key (`core/auth.interceptor.ts`). The gate sits inside
the JSON errors and in front of routing, so an unknown path is a 503 too while the site is closed. There is
no default database or IAM to fall back on. The routes that need the newer tables keep their own 503s
(`translations_unavailable`, `visitors_unavailable`) as a second line, for whatever reaches them with the
tables missing.

The frontend (`@anotoki/lib/migrations`, wired in `core/site-status.ts`): `SiteStatus.check()` at the start,
beside `/api/meta` (`app.config.ts`); `siteStatusInterceptor` hears any 503 `not_set_up` / `update_pending`;
`<anotoki-site-gate>` (`app.html`) shows the gate's page instead of the site:
"The site is being updated" in the reader's language to everybody (a quiet Sign in while nobody is signed in;
it asks again every 30 s and reloads once the site is back - since anotoki-lib 0.2.0 without first telling the
gate the site is ready, so no page is made in the page about to go), "A database update is waiting" with Open
Migrations to the site's ADMIN (asking again the same way: another administrator may apply it meanwhile) - the
Migrations page (`/admin/migrations`) is the one page that shows then -, "This site is not set up yet" with the
setup page only while never installed, "The site is not available right now" otherwise. The router outlet is
the gate's marked template
(`<anotoki-site-gate><ng-template anotokiSitePages><router-outlet /></ng-template></anotoki-site-gate>`,
`SitePagesDirective` in `app.ts`; anotoki-lib 0.1.1 took any template, 0.2.0 only the marked one - an unmarked
one draws nothing), which the gate makes only while it shows the pages: a page behind the gate's page is not
made at all, so it sends no request and leaves no error, snack bar or sync state behind. (0.1.0 took the outlet
as plain content, which Angular made whether it showed or not; `app.ts` kept it out with a condition of its
own, `pagesMade`, gone since.) Nothing in the boot stops while an update waits: the sign-in (and its callback)
completes, the words are asked for. The gate's pages and the Migrations page are drawn with the library's kit
(0.2.0), in this site's colours: the `--anotoki-*` properties in `styles.scss`.

### Sign-in

- The anotoki IAM (iam.anotoki.eu, OpenID Connect) signs people in - the only way in, on a host and on the
  owner's computer alike; this app is its client `build-analyzer`. Who may sign in here is the IAM's to decide
  (every account holds `MEMBER` in every application; the IAM's admin panel picks which roles may sign in to
  this one). Roles in the token's `roles` claim: `EDITOR` (syncs, the sync history, a source's builds file, the
  site's strings) and `ADMIN` (all of that, and sources on / off, migrations, adding / renaming / switching /
  deleting a language, People). ADMIN includes EDITOR; `MEMBER` - and any other role name - is an ordinary
  signed-in person: no check needs it and none refuses it (`User::withRoles()` keeps `Role::STAFF` only).
  Taking somebody's `MEMBER` away at the IAM blocks their next sign-in and refresh (the access token they hold
  still verifies until it expires, minutes); while sign-in is not required they can still use the site as a
  visitor - blocking stops signing in, not visiting; to keep somebody out entirely, require sign-in. The
  contract: `anotoki-iam/docs/integrating-an-app.md`.
- **Whether sign-in is required here is the IAM's switch** ("Sign-in required" on the application's page in
  the IAM's admin panel), read by the server: `App\Auth\SignInPolicy`, whose `IamSignInPolicy` calls the
  IAM SDK's `anotoki_iam_login_required()` (v1.1.0, vendored in `src/Support/Iam/anotoki_iam.php`) - `GET
  {issuer}/api/public/applications/build-analyzer`; a 200 about this client is its `login_required`, a 404
  (an unknown or switched-off application) is *required*; no answer, a 5xx or anything else is the last value
  read, or *required* when none ever was (fail closed). The answer is kept a minute in `storage/cache/iam/`
  (`anotoki-iam-application-<sha1>.json` - a folder only the site writes: a kept "not required" is what opens
  the site to visitors); while one request asks the IAM (2 s at most) the others use the last value, and a
  failed ask is not repeated for a minute. Asked once a request, and only for a request from nobody or a
  visitor (a signed-in person's never need it) - and by `/api/meta`, which relays it as `auth.login_required`
  so the page never asks the IAM. `auth.local.php` may set `application_ttl`, `application_timeout` and
  `application_file` (the IAM's answer from a file: the tests' seam); a host sets none of them.
- **Sign-in required**: every route answers nobody - and a visitor key - 401 `sign_in_required`, but
  `/api/meta`, `/api/translations/{code}` and `/api/icons/*` (what the sign-in page needs, and `<img>`s, which
  send no token), and `/api/site-status` (where the site stands, which every page asks first). The key is refused before it is looked up (`requireOwner()`), so no visitor is seen,
  counted, made or swept early; the move at sign-in (token and key) still works, so what a visitor imported
  comes into the account when they sign in, and switching the requirement off again within 30 days brings the
  key back. **Not required**: the site as below - the builds browser for everybody, visitors.
- `IamGuard` reads `Authorization: Bearer`, verifies the token with the vendored verifier (RS256 only,
  `typ at+jwt`, `iss` = the configured issuer, `aud` contains the client id, `exp`/`nbf` with 60 s leeway, a key
  from `{issuer}/.well-known/jwks.json` cached in `storage/cache/iam/`). Failures are logged with the reason
  (`storage/logs/app.log`) and answered 401 `Sign in to continue.` with `WWW-Authenticate: Bearer`.
- Just-in-time users (`UserProvisioner`): `users.id` is the IAM user id (`sub`), `users.name` follows
  `preferred_username`. Roles are never stored; they come with each token.
- **Credentials that are sent must verify.** A request with an `Authorization: Bearer ...` header (with or
  without a token after the word; or Apache's `HTTP_AUTHORIZATION` / `REDIRECT_HTTP_AUTHORIZATION`) is that
  person or it is answered 401 `sign_in_required` - on public routes too (`requestPerson()` in
  `api/middleware/auth.php`); it is never answered as if it had sent nothing, and its visitor key is not read.
  Only the Bearer scheme counts (`IamGuard::sentCredentials()`, the SDK's `anotoki_iam_sent_bearer()`): a
  `Basic ...` header, which a host behind a password file sends with every request, is as if none were sent.
  Nothing else in a request (a body, a parameter, an address, another header) makes anybody somebody. A
  `mode` or `local_user_id` left in an older `auth.local.php` is ignored.
- No CORS headers at all: the app and the API share one origin (the Angular dev server proxies `/api`), so
  browsers keep every other site from reading the API or sending it a token.
- The checks sit on route groups, so a route added to a group cannot go without one:
  `optionalSignIn()` for the public routes (`/api/builds/*`, `GET /api/sources`; 401 for nobody while sign-in
  is required), `requireOwner()` for one's
  own accounts and imports (the signed-in person, else this browser's visitor - below), `requireSignIn()` for
  what only a signed-in person has (the accounts file `/api/me/*`, the move `/api/visitor/move`),
  `requireRole(...Role::STAFF)` for the editors' routes (403 `editor_only`), `requireRole(Role::ADMIN)` for
  the admins' own (403 `admin_only`) and `requireTokenRole(Role::ADMIN)` for the Migrations page's (the same
  answers from the token alone - no row of `users` read or made -, as they answer while an update waits).
  `/api/meta`, `/api/icons/*`, `/api/translations/{code}` and `/api/site-status` read no token at all.

### Visitors

Somebody who uses the site without signing in gets a **visitor account** (`src/Auth/Visitors.php`; why:
`decisions.md`, 2026-10-04): a row of `users` with a negative id, opened by a key the server made. Who a
request is, decided in `requireOwner()` and nowhere else: a token (it must verify), else - while the IAM
requires sign-in - 401 `sign_in_required` without reading the key, else `X-Visitor-Key` (its SHA-256 is a
visitor row's, or 401 `visitor_unknown`), else nobody (401 `sign_in_required`). So a visitor
reaches exactly what `requireOwner()` guards - its own accounts and imports, through the same
`Accounts::owned()` / `findOwned()` a member's go through - and nothing else; it never has a role.

- **Made** by `POST /api/imports/showcase|file` with neither token nor key: `requireOwner($app, true)` lets
  such a request on as "a visitor to be made", the route's throttles count it per address, and `makeVisitor()`
  (innermost) checks the visitor limits, makes the row and the key, runs the import, and on success adds
  `X-Visitor-Key` (and `Cache-Control: no-store`) to the answer - the only time the key leaves the server.
  A failed first import deletes the visitor again and gives its counts back.
- **Limits** (`config/app.php` `visitors`, `client_address`): per address (`App\Web\ClientAddress`:
  `REMOTE_ADDR`, a trusted proxy's header - a list read from the right -, IPv6 by /64) and site-wide; a
  visitor's imports throttled per address; per visitor a few accounts and smaller files (422 `visitor_limit`;
  the accounts counted by the route before any work, and again by `AccountImporter` under the owner's lock,
  so two imports at once cannot both add the last one).
- **Gone meanwhile**: every writer of an owner's accounts takes their `users` row first
  (`Users::lockForWrite()`); when the row went while the request was on its way - the visitor moved at
  sign-in in another tab, or swept; a person's data deleted on the People page - the answer is 401
  (`visitor_unknown` for a visitor, `sign_in_required` for a person, whose next request starts afresh),
  nothing is written and no failed run is recorded. The account an import targets is read again under the
  lock (gone: 422 `account_missing`).
- **Seen**: `last_seen_at` is written when it is over an hour old. **Swept**: `Visitors::sweep()` deletes
  visitors unseen for `keep_days` (30) in small batches with a statement timeout, after the answer
  (`App\Web\AfterResponse`: `api/index.php` holds the work until Slim has sent the answer and calls
  `fastcgi_finish_request()` where PHP has it; the tests run it at the end of each request); a failure is
  logged, never the request's. It runs when a visitor is made and at most once an hour (a `cache` key) on an
  own-data request.
- **Moved** at sign-in by `POST /api/visitor/move` (token and key): `Users::moveVisitor()`, one transaction,
  the visitor's row then the person's locked; the person's copy of a game account both have stays. A person
  whose row an admin deleted meanwhile is 401 `sign_in_required` with nothing moved (`Visitors::ownerGone()`).
- **Before migration 004** the site gate closes the site until it is applied; behind it, every visitor path
  answers 503 `visitors_unavailable` (a second line) and the clean-up does nothing.
- `/api/meta` sends `visitors.keep_days` for the page's notice; the People page counts visitors apart and never
  lists them.
- **In the browser** (`frontend/src/app/core/visitor-key.ts`, see "Frontend"): the key is kept in
  localStorage `build-analyzer-visitor`, read again for every request, never replaced by a second one, sent only
  on a request issued as a visitor's while the site does not require a sign-in, forgotten on 401
  `visitor_unknown`, and handed to the move once somebody signs in. While the IAM requires sign-in
  (`auth.login_required` in `/api/meta`, `AuthService.loginRequired`) the key is kept but never sent, and no new
  one is taken; a 401 `sign_in_required` never forgets it - the next sign-in moves what it holds, and switching
  the requirement off within 30 days brings it back. Every page then asks for a sign-in first
  (`siteSignInGuard`).

### Per-user data

`accounts` and `import_runs` have `user_id` - a person's IAM id, or a visitor's negative id. `Accounts::owned()` / `findOwned()` scope every list and lookup;
an account id in a path that belongs to someone else is a 404. Imports get the owner passed down to
`AccountImporter` (target account, UID lookup, failed runs). The catalog, builds, sources and sync runs are
shared.

### Neutral names

The UI names no other website, service or tool - not in labels, links, credits, errors, tooltips or the
changelog. So the API never sends a site's name or address either:

- Build sources are "Source 1", "Source 2" … per game, numbered in the order of `config/app.php` `sources`
  (add new ones at the end of their game so the numbers stay). Their API key is `{game}-{n}` (`genshin-2`); the
  command line accepts both that and the internal key (`prydwen.genshin`). `BuildSourceRegistry::label()` /
  `publicKey()`; the `build_sources.name` column holds the label too.
- A build's page address (`builds.source_url`) is kept for the command line and never sent.
- Import runs say `showcase` or `file`, never the service or tool; an account's `meta` is filtered to neutral
  keys. A person's accounts file is held to the same: sources as `showcase` / `file`, the neutral meta keys only,
  no tool name, every text through `Neutral::text()` on the way out and again on the way in.
- Text that came from elsewhere (errors, notes copied from a page, old run logs) passes through
  `Neutral::text()` on the way out: web addresses become "the source page", names of sites and tools "the
  source". `backend/tools/build.php` refuses a frontend build that contains one of those names.
- Icons: the catalog stores the provider's icon address; the API sends `/api/icons/{c|w|s}/{id}`, which fetches
  the image once into `storage/icons/` and serves it from this site.
- The site's words: no string names another site or holds a web address, whether a migration seeded it or the
  editor reworded it in the browser (or imported it from a file, or the command line). `TranslationCheck`
  refuses one (422 `names_another_site`, checked last, in any language, also in a language's names), and
  `tests/translation_catalogue.test.php` puts every seeded string through the same check. A word refused only
  because it contains a name is reworded; the list of names is not changed for it.

### Main flows

- **Import**: `POST /api/imports/showcase` or `/file` (account_id checked against the user's accounts) →
  `ImportService::fromEnka()` / `fromFile()` (format detected from the JSON) → the parser builds a
  `ParsedAccount` with catalog *references* (`external_id` / `name` / `key`) → `AccountImporter::import($parsed,
  $owner, $target)` refreshes a missing catalog list if allowed, resolves references (lookups only, never
  creating catalog rows), upserts characters, replaces gear, writes an `ImportRun`. Failures are recorded as
  failed `ImportRun`s of the user; a refusal is 422 `{message, code, params}`, which the wizard words from
  `code` and `params` (import-formats.md, "Errors and warnings are kinds").
- **Build sync**: an editor presses "Sync now" / "Sync all" (or runs `php sync-builds.php`) →
  `SyncRunner::startBuilds()` creates a `sync_runs` row per source (and a catalog run first when the game's
  catalog is stale) → the page calls `POST /api/sync/step` repeatedly → `BuildSync::step()` lists the site's
  characters, then fetches and stores one character after another until the step's budget is used or the site's
  crawl delay does not fit; names resolved (characters created if unknown; weapons/sets only looked up) →
  upsert `builds` by (source, character, variant) with a content hash.
- **Builds file**: `GET /api/sources/{key}/export` → `BuildTransfer::export()` (`BuildFile::entry()` per build,
  a download); `POST /api/sources/{key}/import` → `BuildFile::read()` checks the whole file → under the step
  lock (`SyncRunner::exclusively()`), refused while the source syncs, one transaction: `BuildSync::write()` per
  character, the source's other builds deleted, a builds run recorded and closed by `BuildSync::finish()`.
- **Accounts file** (a signed-in person's own data): `GET /api/me/export` → `AccountsTransfer::export()` (every
  query takes the person; `AccountsFile::account()` per account; compact JSON, refused as `export_too_big` when it
  would not fit what the import reads); `POST /api/me/import[?dry_run=1]` (the file as the body) → the raw body's
  size checked against `me.file_max_mb` (after PHP and the body parser have it: `post_max_size` bounds a body)
  → `AccountsFile::read()` checks the whole file → `AccountsTransfer::import()`:
  one transaction that first takes the person's `users` row `FOR UPDATE` (`Users::lockForWrite()`, which
  `AccountImporter` takes too, so a file and a game import of the same person take turns; when the row is gone
  - an admin deleted the person's data meanwhile - 401 `sign_in_required` and nothing written), then per account of
  the file: matched by game + UID, or without a UID by game + `created_at` (UTC, to the second); added when there
  is no match; replaced when the file's `last_import_at` is newer (its details, characters, weapons and items; the
  file's import runs it lacks added; meta keys the file does not carry kept); kept otherwise. Never deletes an
  account. Catalog keys this copy lacks are skipped and counted. A dry run answers the same and writes nothing.
- **Analysis**: `GET /api/accounts/{id}` → `CharacterAnalyzer::overview()` (best build per character);
  `GET /api/accounts/{id}/characters/{key}` → `CharacterAnalyzer::detail()` (all evaluations, consensus,
  suggestions, set plans) + full builds via `BuildPresenter`.

### Syncs

No queue worker, scheduler or long-running process (the host has none). A run is a `sync_runs` row with
`state` (the character list and a cursor, or the catalog lists), `next_step_at` (when its site allows the next
request) and `last_step_at`. `SyncRunner::step()`:

1. takes the global step lock (`sync-step`, a `cache_locks` row); another tab or the command line holding it →
   `busy`;
2. picks the due run that waited longest (build runs of a game wait while its catalog run is going);
3. sets the fetcher's deadline to now + `step_seconds` (20, at least 12 so one request always fits) and lets
   the run's handler work, which saves after every character / list;
4. a `FetchDeferred` (crawl delay or a retry does not fit) → `next_step_at`; anything unexpected → run failed;
5. answers the active runs and `wait_ms` until the next one is due.

The browser (`SyncDriver`) waits `wait_ms` and asks again while anything is active; closing the tab pauses. The
command-line scripts do the same in a loop. Cancel waits for a running step, then marks the run `cancelled`.

### The site's words

The member pages read in English or Slovak (more languages can be added in the browser). The words are rows
of the database ([data-model.md](data-model.md), "The site's words"), because the host has no shell and an
upload overwrites the build: a string is a key the code names and a value per language an editor may reword.

- **Keys, not sentences.** A template words a key with the `translate` / `translatePlural` pipes; state holds
  keys, kinds and errors, and a template or a `computed()` words them, so a page follows a change of language
  without reloading. Keys put together at run time come only from the closed lists in
  `frontend/src/app/core/i18n/dynamic-keys.ts`.
- **What the server says to a member is a kind with params**: recommendations (`BuildEvaluator::KINDS` →
  `recommendation-words.ts`), import errors and warnings (`ImportKinds` → `import-words.ts`), and a failed
  request's `code` (`error-text.ts`, which never shows the server's sentence to a member). The English beside
  each kind stays for the command line and for rows stored before kinds; such a row is shown in that English
  with `lang="en"`.
- **Game terms stay as the game writes them** in every language: stat, slot, talent, set, weapon and
  character names, "Lv.", "4pc", UID, grades, and the game's notation ("62.4%", "R1"). They are never keys;
  they reach a sentence as a `{placeholder}` or stand before a colon, so no Slovak word agrees with one
  (`core/game-terms.ts`, `shared/build-terms.ts`, and what `/api/meta` sends).
- **The fallbacks**: the server merges English under every bundle; a key the database lacks shows as the key,
  on purpose. The compiled English (`core/i18n/en.ts` and its parts `en/*.json`) is the list of keys (an
  unknown key is a compile error) and the last resort: it is shown only while no bundle is in memory - before
  migration 003 is applied (every translation route answers 503 `translations_unavailable` then) or while a
  first visit waits for its words - and never fills a single missing string.
- **The language**: `?lang=` (kept for the tab), the menu's remembered choice, the IAM token's `locale`, the
  browser's languages (Czech readers get Slovak while no Czech is offered), else English
  (`TranslationService`). The last bundle is kept in the browser, so a returning reader starts in their
  language. `<html lang>` and the page titles (`title-strategy.ts`) follow it; dates and numbers use
  `Intl` (`localDate`).
- **The account's language, theme and dates** (the IAM's migration 015, SDK 1.2.0; the owner's decision of
  2026-10-06 - "it should be in the iam only and apply for every project/application"): while somebody is
  signed in at an IAM that keeps them (`AuthService.preferences()` not null), the account's `locale` comes
  before the menu's remembered choice - only `?lang=` and a choice of this visit come first - and is followed
  when it changes (on another anotoki site, in another tab); the menu's choice is saved to the account
  (`savePreferences({ language })`, one save at a time, the latest last; a language the IAM does not offer -
  `invalid_request` - stays this site's own for the visit, silently; any other refusal keeps it for the visit
  and says why in a snack bar). The theme is the account's on every page, the admin panel included
  (`core/theme.ts`, below). Dates follow the account's date order and clock where it has chosen them
  (`core/i18n/date-patterns.ts`): What's new's days (`localDate: 'day'`), and in the admin panel when a
  migration was applied and when a builds file was exported; relative times ("3 hours ago") have neither
  and read the same for everybody. A visitor, and anybody signed in at an IAM before 015 (no `preferences`),
  keeps this device's choices exactly as before, and nothing is saved anywhere else.
- **English pages**: the admin panel - `/admin/...` (Sources, Translations, Languages, People, Migrations) - keeps its
  English code and reads English with `<html lang="en">` whatever language is chosen; the server's admin-only
  sentences stay English and those pages show them as they come (`errorMessage()`).
- **The Translations and Languages pages** (editors; adding, renaming, switching and deleting a language:
  admins): Translations is a grid of every key with its description and a cell per
  language, saved together (`PUT /api/admin/translations`, checked whole by `TranslationCheck` and written in
  one transaction), plus export and import of one language as a `{key: text}` file. Languages adds, renames,
  orders, offers or hides and deletes languages; a new language starts hidden, so its strings can be written
  before readers are offered it. What neither page has saved is kept for the tab (`LocalizationDrafts`, in
  `sessionStorage`, per person), leaving inside the app asks first (`unsavedChangesGuard`, through
  `shared/confirm-dialog`), and closing the tab asks the browser's question.
- **The tests hold the pieces together** (`tests/translation_catalogue.test.php`): the compiled English is,
  key for key, the seeded English; every key the frontend uses (the scan `tests/support/translation_keys.php`,
  which reads the sources and `dynamic-keys.ts` as text) is in the database and every database key is used;
  the server's kinds are the declared tails; every seeded string passes the checks a save makes.

### REST API (`api/routes/`)

| Method & path | Who | Purpose |
| --- | --- | --- |
| `GET /api/meta` | anyone (also while sign-in is required) | slots, stat labels, talent names per game; `version`; `auth {issuer, client_id, login_required}` (whether the IAM requires sign-in here); `visitors {keep_days}` |
| `GET /api/icons/{c\|w\|s}/{id}` | anyone (also while sign-in is required) | a character / weapon / set icon, served from `storage/icons/` |
| `GET /api/accounts`, `GET/PATCH/DELETE /api/accounts/{id}` | signed in or visitor (own) | list / overview (+ characters with best build) / rename / delete |
| `GET /api/accounts/{id}/characters/{key}?build=ID` | signed in or visitor (own) | full analysis of one character (key = catalog key, e.g. `hutao`) |
| `GET /api/accounts/{id}/imports` | signed in or visitor (own) | import history |
| `POST /api/imports/showcase` `{game, uid, account_id?}` | signed in or visitor (own); nobody: makes a visitor | Character Showcase import (10 a minute per person; a visitor's per address, all visitors' 20 together) |
| `POST /api/imports/file` `{content \| file, game?, account_id?, file_name?}` | signed in or visitor (own); nobody: makes a visitor | GOOD / HSR scanner import (20 a minute per person; a visitor's per address) |
| `POST /api/visitor/move` (`X-Visitor-Key`) | signed in, with the key | a visitor's accounts and imports become the person's: `{found, moved, kept, imports}` |
| `GET /api/me/export` | signed in (never a visitor) | the person's game accounts as a file to save (`build-analyzer-accounts-{date}.json`, compact JSON; data-model.md "Accounts files") |
| `POST /api/me/import[?dry_run=1]` (the file as the body) | signed in (never a visitor) | `{dry_run, added, replaced, kept, skipped, accounts: [{game, uid, nickname, outcome, skipped}]}`; a dry run writes nothing (5 a minute per person, dry runs included) |
| `GET /api/builds/{game}`, `GET /api/builds/{game}/{key}` | anyone (signed in only while sign-in is required) | builds browser |
| `GET /api/sources` | anyone (signed in only while sign-in is required) | source status (the dashboard shows it); who started the latest run only for editors |
| `PATCH /api/sources/{key}` `{enabled}` | admin | switch a source on / off |
| `POST /api/sources/{key}/sync` | editor | start (or join) a sync of one source, however recent its last one |
| `GET /api/sources/{key}/export` | editor | the source's builds as a file to save (`builds-{key}-{date}.json`) |
| `POST /api/sources/{key}/import` (the file's JSON) | editor | the source's builds become the file's; the counts, skipped characters and the source |
| `POST /api/sync/builds` `{force?}` | editor | "Sync all": every enabled source not synced in 20 h (+ stale catalogs) |
| `POST /api/sync/catalog` `{games?}` | editor | refresh the game catalog |
| `GET /api/sync`, `POST /api/sync/step` | editor | the active runs and `wait_ms`; do one bounded step |
| `GET /api/sync-runs`, `GET /api/sync-runs/{id}`, `POST /api/sync-runs/{id}/cancel` | editor | history (`?source=`, `?kind=`), log, cancel |
| `GET /api/site-status` | anyone, always (the gate lets it through) | `{state: ready\|not_set_up\|update_pending\|unavailable, installed}` - nothing else (anotoki-lib's `MigrationsRoutes::status`) |
| `GET /api/admin/migrations` | admin (from the token alone; open while an update waits) | `{sets: ["build_analyzer"], applied: [{set, name, applied_at}], pending: [{set, name, ready, blocked}], missing: [{set, name, applied_at}]}` - the files applied, in order, with when (UTC, `…Z`); the files not applied yet, in the order they will run; files recorded but not on disk (anotoki-lib's `MigrationsRoutes::admin`) |
| `GET /api/admin/migrations/file?set=&name=` | admin (likewise) | `{set, name, sql}` of one of the set's files; 404 `not_found` for anything else (no path tricks) |
| `POST /api/admin/migrations/apply` | admin (likewise) | applies every pending file in order, each in its own transaction with its record, stopping at the first that fails: `{applied: [{set, name}], failed: {set, name}\|null, error: string\|null, note: null}` (200 either way; the failed file rolled back whole; `error` is `statement N of M: <the database's message>` and the statement's first lines); 409 `busy` while another apply holds the advisory lock every apply takes (the page, `migration.php`, setup). No shell on the host; the same engine as `migration.php` and setup. Given five minutes and not cut short when the browser goes; the log says what was applied and where it stopped |
| `GET /api/translations/{code}` | anyone (no token read) | the bundle: `{language, languages (the offered ones), values, english?}`, the language's strings with English underneath; ETag and 304 |
| `GET /api/admin/languages` | editor | the languages, hidden ones too, with their string counts |
| `POST /api/admin/languages`, `PUT/DELETE /api/admin/languages/{code}` | admin | add one / change name, native name, offered, order / delete one and its strings (never `en` or `sk`) |
| `GET/PUT /api/admin/translations` `{values: {key: {code: text}}}` | editor | the grid (every key, its description, its strings) / save the changed strings, all or none |
| `GET /api/admin/translations/{code}/export`, `PUT …/{code}/import` `{key: text}` | editor | one language's own strings as a file / back in, with a save's checks |
| `GET /api/admin/people` | admin | `{people: [{id, name, accounts, import_runs, created_at, last_import_at}], visitors: {count, accounts}}` - the IAM people who used the site |
| `DELETE /api/admin/people/{id}` | admin | delete a person's data (accounts with everything in them, import runs); `{accounts, import_runs}`; their syncs and strings stay, by nobody; 404 for no such person |

Errors are JSON `{message}` and, where a page words them, a `code` (validation adds `errors: {field:
[message]}`): 401 `Sign in to continue.` `sign_in_required` (no token, or one that does not verify; while the IAM requires sign-in, also a visitor key), 401
`visitor_unknown` (a visitor key nobody has, or none at all), 403
`editor_only` "Only an editor or an admin can do this." / `admin_only` "Only an admin can do this.", 404 `Not found.` (also for other users' accounts and unknown paths), 409 (a step holds
the lock; a builds file for a source that is syncing; `language_exists`, `fallback_language`,
`seeded_language`), 422 (import problems `{code, params}`, a builds file that does not fit, validation, a
string or language the checks refuse - with the key and the language; a write to the translation routes whose body is not
`application/json`, such as a form, is `invalid_language` / `invalid_value` like a malformed one; a visitor's limits,
`visitor_limit` `{limit: accounts | file_size, max}`; an accounts file not read, `{code, params}`: `no_file`,
`file_too_big` `{max_mb}`, `not_a_data_file`, `newer_version` `{version}`, `bad_entry` `{path}`, `too_many_accounts`
`{max}`, and an export too large for one file, `export_too_big` `{max_mb, max_accounts}`), 429 (`too_many_imports`; `too_many_visitors`, which says to sign in), 503
`not_set_up` and `update_pending` (the site gate's: `{code, message}`, `not_set_up` with `installed` and - only
while never installed - `setup`; see "The site gate"), 503 `translations_unavailable` from the translation routes
while the tables of migration 003 are not there, 503 `visitors_unavailable` from the visitor paths while 004's
columns are not (both behind the gate: a second line), and 503 `visitor_move_retry` (ask again). The Migrations
page's API answers its own: 404 `not_found` (a file that is none of the set's), 409 `busy`.

## Frontend (`frontend/`, Angular 21 + Angular Material 21)

- `core/api.ts` typed client (`API_BASE = '/api'`, same origin) + `errorMessage()`; `core/models.ts` mirrors the
  API responses (keep in step with the presenters); `core/meta-store.ts` loads `/api/meta` at startup (labels,
  formatting, sign-in settings); `core/site-status.ts` wires the family's site gate and Migrations page
  (`@anotoki/lib/migrations`; the page itself from `@anotoki/lib/migrations/page`) to this site - see "The site
  gate" above; `core/format.ts` colors
  and time helpers; `core/changelog.ts` loads `public/changelog.json` (the version shown in the footer, and
  "What's new" once per release per browser); `core/theme.ts` (`ThemeService`: light, dark or the device's, a
  class on `<html>`, kept in localStorage `build-analyzer-theme`; signed in at an IAM that keeps the
  preferences, the account's - shown from the first frame, followed when it changes, and the toggle saves to
  it: shown at once, one save at a time with the latest choice last, never flicking back while its own save
  is on its way, and a refusal puts the account's theme back with a snack bar worded from the code
  (`preferencesProblem()`: `error.offline`, `error.sign_in_required`, `error.unknown`); the theme on the page is
  written to localStorage either way, so the next load starts in it). `core/i18n/date-patterns.ts`: the
  account's four date orders and two clocks, as the IAM writes them, and `chosenMoment()` for a day with its
  time (each part the account's where chosen, the page's own where not; all the page's own when neither is).
- Sign-in, through the anotoki IAM only: `core/iam/anotoki-auth.mjs` + `.d.ts` (the IAM's browser SDK,
  vendored unchanged, v1.2.0; imported without extension so TypeScript takes the `.d.ts`), `core/auth.ts`
  (`AuthService`: `preferences` - the account's theme, date order and clock, null for a visitor or an IAM before
  its migration 015 -, `savePreferences()` - a choice saved to the account for every anotoki site -,
  `available` - the server said where its IAM is -, `user`, `signedIn`, `roles`, `isStaff`
  (EDITOR or ADMIN), `isAdmin`; `init()` from the app initializer after `/api/meta`; `login()` answers whether
  the browser is on its way to the IAM, and leaves `signIn.storageUnavailable` in `lastError` when the browser
  refuses the site's storage), `core/sign-in.ts` (`signInOrSayWhy()`: every Sign in button - the IAM's pages in
  the reader's language, `uiLocales` - and a snack bar when it cannot start), `core/auth.guards.ts`
  (`signInGuard` on `/admin`: anybody not signed in - a visitor too - goes to the IAM and back; it lets the page
  through to the admin layout's card after a sign-in that came back without one or could not start, or with no
  IAM; `siteSignInGuard` on every child of the AppLayout: while `loginRequired` (the IAM's switch, relayed in
  `/api/meta`; true until the server says otherwise, and when it could not be asked) somebody not signed in goes
  to the IAM and back, or to `/sign-in?returnTo=...` where that would loop or cannot happen; visiting or not, a
  sign-in this page load came back from refused (`access_denied` - also how the IAM turns away somebody whose
  MEMBER was taken) is shown once on `/sign-in` (`AuthService.takeRefusalOnReturn()`), never swallowed;
  `adminOnlyGuard` on Languages, People and Migrations: an editor goes to `/admin/sources`). `pages/sign-in/`
  explains a failed sign-in and is the `/auth/callback` route's fallback (before `**`). `AuthService.login()`
  leaves only once the writes on their way have answers (`core/saves-in-flight.ts`, 30 s at most): a visitor's
  first import brings the key the move needs. AuthService never injects VisitorKey (NG0200: VisitorKey reads
  AuthService).
- Who a request is, in the browser - decided when it is ISSUED, never from whether a token happens to be there
  when it leaves: `Api` marks each request (`ISSUED_AS`, an `HttpContext` token) as the person's while somebody
  is signed in and the visitor's otherwise - and always the person's for the admin panel, the syncs, the own-data
  file and the move. `core/auth.interceptor.ts`, on this site's `/api` only, never on `/api/translations/` (the
  public words, sent at once), acts on that mark (a request `Api` did not mark is decided as it is handed over):
  a person's request gets the bearer (one retry with `refresh()` after a 401) and never the visitor key, and
  with no token it is **never sent** - it fails there as a 401 `sign_in_required`, so a person's import never
  goes out as a visitor's and never makes one; a visitor's request, while the site does not require a sign-in,
  gets the key `VisitorKey.current()` reads at that moment, and the `X-Visitor-Key` of the answer that made a
  visitor is kept (`remember()`: the first key stays, so two tabs that both imported first do not lose one); a
  401 `visitor_unknown` forgets the key that was sent (compare-and-delete); while the site requires a sign-in a
  visitor's request carries neither, takes no key and forgets none. The move (`Api.moveVisitor()`, a person's,
  marked `CARRIES_VISITOR_KEY`) is the one request with the token and the key. Every write is counted while it
  is on its way (`SavesInFlight`).
- `core/visitor-key.ts` (`VisitorKey`, root): the key in localStorage `build-analyzer-visitor` through the
  never-throwing helpers of `core/storage.ts` (in memory when storage refuses), kept in step across tabs by the
  `storage` event and read again at send time; `key`, `active` (not signed in, sign-in not required, a key
  held), `lost` (forgotten on `visitor_unknown`: the dashboard says so once), `moves` (a counter the pages that list accounts reload on),
  `moved` (what the last move did, for the shell's snack bar - `visitor.moved` / `visitor.kept`). Its effect on
  `auth.signedIn()`: signed in with a key held, `POST /api/visitor/move` once at a time; a 200 (found or not)
  forgets the key, a 422 too; a network error or a 5xx (`visitor_move_retry`, `visitors_unavailable`) keeps it
  and asks again after the answer's Retry-After (else 2, 10, 30 s - `MOVE_RETRY_MS`), then at the next page
  load; a 401 keeps it for the next sign-in. The app initializer injects it right after the start, so the
  effect exists before the sign-in settles.
- `core/sync-driver.ts` (`SyncDriver`, provided in root): steps the syncs while the tab is open; the top bar shows
  an editor or an admin a spinning icon while it drives (in the phone's menu on a phone).
- `core/i18n/` (see "The site's words" above): `translation.ts` (`TranslationService`: the language, the
  bundle, `init()` from the app initializer, the English pages), `en.ts` with the compiled English parts
  `en/{core,shell,builds,import}.json` (one flat `{"key": "English"}` object each, a key in exactly one part),
  the pipes `translate`, `translatePlural`, `localDate`, `dynamic-keys.ts`, `error-text.ts` (a failed request
  by its `code`), `recommendation-words.ts`, `import-words.ts`, `title-strategy.ts` (`AppTitleStrategy`: a
  member route's `title` is a key) and `testing.ts` (the specs' helpers). The game's own words:
  `core/game-terms.ts`, `shared/build-terms.ts`. `pages/import/stepper-words.ts` words the Material stepper's
  own labels.
- The admin panel (`/admin/...`, English with `lang="en"`; the old `/sources`, `/translations`, `/languages`
  redirect there): `layouts/admin-layout/` (`AdminLayout`: for staff the area's sections - Sources and
  Translations for an editor, Languages, People and Migrations too for an admin - and the page; for nobody signed in "You are
  signed out" or, after a sign-in that did not complete, "You are not signed in", with a Sign in button; for
  somebody without a role "This part is for editors and admins"), under the top bar with the area's sections
  as its links and the footer. The frontend only hides: the server refuses (403 `editor_only` / `admin_only`).
  `core/localization-drafts.ts` (`LocalizationDrafts`: what the Translations and Languages pages have not saved,
  for the tab and the IAM person who typed it, and the one `beforeunload` question),
  `core/unsaved-changes.guard.ts` (`unsavedChangesGuard`, `canDeactivate` on both routes),
  `shared/confirm-dialog`.
- The frame: `app.ts` is the router inside the site gate (`<anotoki-site-gate>`, the outlet its template: the
  gate's own page while the site is not ready, and no page made behind it - see "The site gate") plus what
  belongs to no page - the theme on `<html>`, What's new to somebody who has used the site before (signed in,
  or holding a visitor key) while the site is there to use (its dialog is the shell's, outside the gate, so
  `app.ts` itself holds it back while `SiteStatus.blocked()`), the move's snack bar. Two layouts draw the rest:
  `layouts/app-layout/` (every page outside `/admin`: `<app-top-bar area="app">`, the page, the footer) and
  `layouts/admin-layout/` (`area="admin"`). The footer (`layouts/footer/`): "Version 0.4.0 · What's new" - the
  newest entry of `public/changelog.json` (`core/changelog.ts`). What's new opens by itself with the releases
  after the one this browser saw last (`build-analyzer-version-seen`), compared by their numbers
  (`compareVersions()`: MAJOR.MINOR.PATCH, a missing part 0 - so "0.3", kept from before the versions had
  three numbers, is 0.3.0).
- The top bar (`layouts/top-bar/`, the anotoki family's bar - the survey's is the reference; here Angular
  Material pieces with the survey's own menu markup): one row - the brand (`shared/brand/`: the anotoki mark,
  "anotoki" and `brand.area`; on a phone the mark and `brand.short`), the area's links (`APP_LINKS`: Dashboard
  with `/accounts/` under it, Import, Builds with `/builds/` under it - the same for nobody, a visitor and a
  person; `ADMIN_LINKS`: Sources, Translations, and for ADMIN Languages, People and Migrations, English, staff only), then
  the sync spinner (staff, while this tab drives a sync), the language (`shared/language-switcher/`: 40 px, a
  menu of the languages in their own names; only with more than one and never under `/admin`; signed in, a
  choice is the account's too), the theme (`shared/theme-toggle/`: 102 x 40, a radio group; signed in, the
  account's, in both areas), and the person's menu (name, email, the role pill
  "Administrator" / "Editor"; "Your anotoki account"; "Admin panel" for staff, or "Back to Build Analyzer" in the
  admin panel; Sign out, which first asks about strings typed on the Translations and Languages pages and not
  saved) - or Sign in (`signInOrSayWhy()`). Up to `PHONE_BAR_MAX` = 880 px (a `matchMedia` signal; the bar
  sets `is-phone` for the scss) the links, the spinner and the person's menu (or Sign in) fold into one menu
  button; the person's name shows from 1081 px and gives way first (an ellipsis). 880 is measured, not copied:
  the row needs 772 px in English and 781 in Slovak signed out, 799 for an admin with a sync running
  (`verify-ui`'s `run.mjs --measure`). The admin panel's row is longer - an admin has five sections - so
  it has its own `ADMIN_PHONE_BAR_MAX` = 960 (954 px measured with a sync running; the name from 1161 px, so a
  1024 px screen has the whole row and a 1280 px one the name too); its brand is short for it, as the IAM's:
  "anotoki admin panel" (`brand.admin`; on a phone `brand.short`), the app's name in the link's accessible
  name ("anotoki Build Analyzer: the admin panel", `topbar.homeAdmin`). The menus are not `mat-menu`: a spec test showed it closes on Tab and
  focuses its trigger but leaves the Tab to the browser, which moves the focus on past the trigger. The bar's
  menus (and the language's) follow the family's rule: focus into the menu on open, arrows / Home / End,
  Escape and an outside click close it (Escape back to the button), Tab and Shift+Tab from any item
  `preventDefault()` and close it with the focus on its button, a breakpoint crossing closes it. Every member
  word is a key (`brand.*`, `topbar.*`, `footer.version`, `language.button`, `theme.*`); under `/admin` the
  translation service answers in English, so the same keys read English there.
- `shared/`: `build-detail` (renders a build, optionally annotated with an evaluation), `item-card`,
  `grade-badge`, `score-bar`, `char-avatar` (`app-avatar`), `changelog-dialog`, `confirm-dialog`,
  `stat-pipes` (`statLabel`, `statValue`, `slotLabel`, `timeAgo`), `visitor-notice/` (for somebody not signed
  in: kept for this browser on the server for `visitors.keep_days` days after the last visit, signing in keeps
  it - with a Sign in button), `visitor-hero/` (nobody's ways in: import, each game's builds, sign in),
  `data-card/` ("Your data", signed in only: Export downloads `GET /api/me/export` under the server's name;
  Import refuses a file over 8 MB before sending, sends its text unchanged as a dry run - the preview: added,
  replaced by a newer copy, kept, entries left out, each account's outcome - and Confirm sends it for real; the
  file's problems are worded from `data.error.<code>` with their params, anything else as a failed request).
  `core/download.ts` (`downloadName()`, `saveFile()`) saves a file the API sent, for the card and the admin pages.
- `pages/`: every page outside `/admin` is open to everybody. `dashboard` - three states: nobody (no token, no
  key: `visitor-hero` and the sources' freshness, no request for anybody's accounts), a visitor (the accounts
  under the visitor notice), signed in (the accounts and the data card); it loads the accounts again when the
  state changes and after a move. `import` (wizard + `import-guides.ts`, whose texts are keys; the notice for
  somebody not signed in; nobody's wizard asks for no accounts), `account` (its import history wraps a row
  under 600 px, so the last column is never cut) and `character` (the notice for a visitor; on a 401 for
  somebody not signed in, `visitor-hero` instead of an error), `builds` (list + per character; the Sources page
  is a link for staff, its name alone for anybody else), `admin/sources` (staff: sources, running syncs with
  pause / resume / cancel, a source's builds exported and imported as a file - `build-file.ts` checks a chosen
  file before anything is sent; an admin also switches sources), `admin/translations` (staff), `admin/languages`
  and `admin/people` (admin: everybody who signed in, what they keep, Delete their data after a confirmation;
  visitors only counted), `admin/migrations` (admin: the family's `<anotoki-migrations-page>` in the panel's
  `.page` - Pending in the order they will run, Applied with their dates in the account's order and clock and each
  file's SQL, Apply pending after a confirmation, and after it "Up to date", "There was nothing to apply" or
  "Stopped at <file>" with what was applied first and the database's error; once nothing is left the site opens
  again in the tab and the strings are read again), `sign-in`. Lazy-loaded
  from `app.routes.ts`; route params arrive as signal inputs (`withComponentInputBinding`).
- Conventions: standalone components, always three files (`.ts`, `.html`, `.scss`), signals (`signal`,
  `computed`, `input`), `inject()`, built-in control flow (`@if`, `@for`, `@let`), zoneless change detection,
  Material 3 theme with CSS tokens in `src/styles.scss` (`--app-*`, `--grade-*`, `--el-*`, light/dark via
  `light-dark()`). Fonts (Roboto, Material Symbols) come from npm packages and are bundled; nothing is loaded
  from another site.
- The dev server (http://localhost:4600) proxies `/api` and `/setup.php` to `http://localhost:8400`
  (`proxy.conf.json`). `ng build` writes `dist/frontend/browser/`, which `router.php` serves in development and
  `backend/tools/build.php` puts into the upload.
