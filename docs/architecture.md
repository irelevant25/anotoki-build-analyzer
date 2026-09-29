# Architecture

The build analyzer imports Genshin Impact / Honkai: Star Rail accounts, reads recommended builds from community
build sites when an admin syncs them, and compares every owned character with every build. People sign in
through the anotoki IAM; each person sees only their own game accounts, while the catalog and the builds are
shared.

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
| Routes | `api/routes/{meta,icons,accounts,imports,builds,sources,sync,admin}.php` | each returns `function (Slim\App $slim, App $app)` |
| Middleware | `api/middleware/errors.php` (JSON errors), `security.php` (headers), `setup_gate.php` (503 `not_set_up`), `auth.php` (`requireRole()`, `currentUser()`, `throttle()`) | Slim runs the last one added first |
| Wiring | `src/App.php` (a small container: `get()` builds a class once per request scope, `resetScope()`), `src/Config.php` | `Db` and the HTTP client last the whole process; everything else is per request |
| Configuration | `config/app.php` (committed: sources, catalog, showcase, aliases), `config/{database,auth,fetch}.local.php` (per installation, written by `setup.php`) | no environment variables, no `.env` |
| Database | `src/Db/Db.php` (PDO helpers: `one`, `all`, `value`, `insert`, `update`, `run`, `transaction`), `src/Db/Migrator.php` | always parameterised; identifiers are literals in the code |
| Rows | `src/Models/*` (plain classes made from rows), `src/Repositories/*` (the queries) | `Accounts::findOwned()` / `owned()` scope every account query to its owner |
| Domain basics | `src/Enums/Game.php`, `src/Support/{Stats,Talents,Key,MainStatValues}.php` | slots, stat keys, label parsing; see [stats-and-slots.md](stats-and-slots.md) |
| Sign-in | `src/Auth/{IamGuard,UserProvisioner,AuthMode,Role}.php`, `src/Support/Iam/IamTokenVerifier.php` (+ the vendored `anotoki_iam.php`) | see "Sign-in" below |
| Outgoing HTTP | `src/Http/Fetcher.php`, `CurlHttpClient.php`, `FetchDeferred.php` | per-host delay kept in the cache table, retries on 429/5xx, optional disk cache, step deadline |
| Cache and locks | `src/Cache/{Cache,Locks}.php` | the `cache` and `cache_locks` tables (the host has no Redis or shared memory) |
| Catalog | `src/Services/Catalog/{Catalog,CatalogSync,SetBonusGroups}.php` | resolve any source's names/IDs to catalog rows; see [name-matching.md](name-matching.md) |
| Imports | `src/Services/Import/ImportService` → parsers → `ParsedAccount` → `AccountImporter` | always for one user; see [import-formats.md](import-formats.md) |
| Builds | `src/Services/Builds/{BuildSync,BuildSourceRegistry}.php`, `Sources/*`, `Parsing/*` | see [build-sources.md](build-sources.md) |
| Syncs | `src/Services/Sync/{SyncRunner,RunHandler,RunLog,StepResult}.php` | runs worked through in short steps; see "Syncs" below |
| Analysis | `src/Services/Analysis/*` | see [analysis.md](analysis.md) |
| Output | `src/Presenters/*` (accounts, builds, sync runs, icon addresses), `src/Support/Neutral.php` | what the API sends, in the shapes of `frontend/src/app/core/models.ts` |
| Command line | `migration.php`, `sync-builds.php`, `sync-catalog.php`, `import-account.php` (→ `src/Commands/*`) | loop over the same steps as the Sources page; nothing is scheduled |
| Setup | `setup.php` + `src/Setup/Installer.php` | writes `config/*.local.php`, builds the database; see [operations.md](operations.md) |
| Deploy | `../scripts/build-dist.php` | `dist/`, the web root to upload; see DEVELOPING.md "Deploying" |

### Setup gate

`Config::load()` reads `config/app.php` and the three local files. When one is missing or incomplete, every
API request is answered `503 {"code": "not_set_up", "message": …, "setup": "/setup.php"}` (the log says which
file and which value), and the frontend shows its "Not set up yet" page instead of its pages. There is no
default database or IAM to fall back on.

### Sign-in

- The anotoki IAM (iam.anotoki.eu, OpenID Connect) signs people in; this app is its client `build-analyzer`.
  Roles in the token's `roles` claim: `ADMIN` (syncs, sources, migrations) and `MEMBER` (imports and
  analysis). ADMIN includes MEMBER. The contract: `anotoki-iam/docs/integrating-an-app.md`.
- `IamGuard` reads `Authorization: Bearer`, verifies the token with the vendored verifier (RS256 only,
  `typ at+jwt`, `iss` = the configured issuer, `aud` contains the client id, `exp`/`nbf` with 60 s leeway, a key
  from `{issuer}/.well-known/jwks.json` cached in `storage/cache/iam/`). Failures are logged with the reason
  (`storage/logs/app.log`) and answered 401 `Sign in to continue.` with `WWW-Authenticate: Bearer`.
- Just-in-time users (`UserProvisioner`): `users.id` is the IAM user id (`sub`), `users.name` follows
  `preferred_username`. Roles are never stored; they come with each token.
- Auth mode `none` (the owner's own copy only, `config/auth.local.php`): every request is `local_user_id` with
  every role, but only from the app's own pages on this computer (`IamGuard::fromOwnPages()`: loopback client
  and `Host`, no `Sec-Fetch-Site: cross-site`, an `Origin` on this computer; 403 otherwise), because other sites
  open in the owner's browser connect from 127.0.0.1 too. Any other value means `iam`. The mode is configuration
  only; nothing in a request changes it. `setup.php` offers `none` only when it is opened on this computer.
- No CORS headers at all: the app and the API share one origin (the Angular dev server proxies `/api`), so
  browsers keep every other site from reading the API.
- `requireRole($app, Role::MEMBER)` guards everything but `/api/meta` and `/api/icons/*`; the admin routes use
  `Role::ADMIN`.

### Per-user data

`accounts` and `import_runs` have `user_id`. `Accounts::owned()` / `findOwned()` scope every list and lookup;
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
  keys.
- Text that came from elsewhere (errors, notes copied from a page, old run logs) passes through
  `Neutral::text()` on the way out: web addresses become "the source page", names of sites and tools "the
  source". `scripts/build-dist.php` refuses a frontend build that contains one of those names.
- Icons: the catalog stores the provider's icon address; the API sends `/api/icons/{c|w|s}/{id}`, which fetches
  the image once into `storage/icons/` and serves it from this site.

### Main flows

- **Import**: `POST /api/imports/showcase` or `/file` (account_id checked against the user's accounts) →
  `ImportService::fromEnka()` / `fromFile()` (format detected from the JSON) → the parser builds a
  `ParsedAccount` with catalog *references* (`external_id` / `name` / `key`) → `AccountImporter::import($parsed,
  $owner, $target)` refreshes a missing catalog list if allowed, resolves references (lookups only, never
  creating catalog rows), upserts characters, replaces gear, writes an `ImportRun`. Failures are recorded as
  failed `ImportRun`s of the user; `ImportException` messages are shown to users as they are (422).
- **Build sync**: an admin presses "Sync now" / "Sync all" (or runs `php sync-builds.php`) →
  `SyncRunner::startBuilds()` creates a `sync_runs` row per source (and a catalog run first when the game's
  catalog is stale) → the page calls `POST /api/sync/step` repeatedly → `BuildSync::step()` lists the site's
  characters, then fetches and stores one character after another until the step's budget is used or the site's
  crawl delay does not fit; names resolved (characters created if unknown; weapons/sets only looked up) →
  upsert `builds` by (source, character, variant) with a content hash.
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

### REST API (`api/routes/`)

| Method & path | Who | Purpose |
| --- | --- | --- |
| `GET /api/meta` | anyone | slots, stat labels, talent names per game; `version`; `auth {mode, issuer, client_id}` |
| `GET /api/icons/{c\|w\|s}/{id}` | anyone | a character / weapon / set icon, served from `storage/icons/` |
| `GET /api/accounts`, `GET/PATCH/DELETE /api/accounts/{id}` | member (own) | list / overview (+ characters with best build) / rename / delete |
| `GET /api/accounts/{id}/characters/{key}?build=ID` | member (own) | full analysis of one character (key = catalog key, e.g. `hutao`) |
| `GET /api/accounts/{id}/imports` | member (own) | import history |
| `POST /api/imports/showcase` `{game, uid, account_id?}` | member | Character Showcase import (10 a minute per user) |
| `POST /api/imports/file` `{content \| file, game?, account_id?, file_name?}` | member | GOOD / HSR scanner import |
| `GET /api/builds/{game}`, `GET /api/builds/{game}/{key}` | member | builds browser |
| `GET /api/sources` | member | source status (the dashboard shows it) |
| `PATCH /api/sources/{key}` `{enabled}` | admin | switch a source on / off |
| `POST /api/sources/{key}/sync` | admin | start (or join) a sync of one source, however recent its last one |
| `POST /api/sync/builds` `{force?}` | admin | "Sync all": every enabled source not synced in 20 h (+ stale catalogs) |
| `POST /api/sync/catalog` `{games?}` | admin | refresh the game catalog |
| `GET /api/sync`, `POST /api/sync/step` | admin | the active runs and `wait_ms`; do one bounded step |
| `GET /api/sync-runs`, `GET /api/sync-runs/{id}`, `POST /api/sync-runs/{id}/cancel` | admin | history (`?source=`, `?kind=`), log, cancel |
| `GET /api/admin/migrations`, `POST /api/admin/migrations/apply` | admin | pending migrations; apply them (no shell on the host) |

Errors are JSON `{message}` (validation adds `errors: {field: [message]}`): 401 `Sign in to continue.` (no or
bad token), 403 (no role here / not an admin / "none" mode from another computer), 404 `Not found.` (also for
other users' accounts and unknown paths), 409 (a step holds the lock), 422 (import problems, validation), 429
(too many showcase imports), 503 `not_set_up`.

## Frontend (`frontend/`, Angular 21 + Angular Material 21)

- `core/api.ts` typed client (`API_BASE = '/api'`, same origin) + `errorMessage()`; `core/models.ts` mirrors the
  API responses (keep in step with the presenters); `core/meta-store.ts` loads `/api/meta` at startup (labels,
  formatting, sign-in settings) and notices a server that is not set up (`notSetUp`); `core/format.ts` colors
  and time helpers; `core/changelog.ts` loads `public/changelog.json` (the version shown in the top bar, and
  "What's new" once per release per browser).
- Sign-in: `core/iam/anotoki-auth.mjs` + `.d.ts` (the IAM's browser SDK, vendored unchanged, v1.1.1; imported
  without extension so TypeScript takes the `.d.ts`), `core/auth.ts` (`AuthService`: mode, user, roles as
  signals; `init()` from the app initializer after `/api/meta`), `core/auth.interceptor.ts` (bearer on this
  site's `/api` only, one retry with `refresh()` after a 401), `core/auth.guards.ts` (`memberGuard`,
  `adminGuard`). `pages/sign-in/` has the sign-in page and the `/auth/callback` route (before `**`). With sign-in
  off (`mode: 'none'`) no sign-in controls are shown and every page opens.
- `core/sync-driver.ts` (`SyncDriver`, provided in root): steps the syncs while the tab is open; the toolbar shows
  a spinning icon while it drives.
- `shared/`: `build-detail` (renders a build, optionally annotated with an evaluation), `item-card`,
  `grade-badge`, `score-bar`, `char-avatar` (`app-avatar`), `changelog-dialog`, `stat-pipes` (`statLabel`,
  `statValue`, `slotLabel`, `timeAgo`).
- `pages/`: `dashboard`, `import` (wizard + `import-guides.ts` content), `account`, `character`, `builds`
  (list + per character), `sources` (admin: sources, running syncs with pause / resume / cancel, database
  updates), `sign-in`, `not-set-up`. Lazy-loaded from `app.routes.ts`; route params arrive as signal inputs
  (`withComponentInputBinding`).
- Conventions: standalone components, always three files (`.ts`, `.html`, `.scss`), signals (`signal`,
  `computed`, `input`), `inject()`, built-in control flow (`@if`, `@for`, `@let`), zoneless change detection,
  Material 3 theme with CSS tokens in `src/styles.scss` (`--app-*`, `--grade-*`, `--el-*`, light/dark via
  `light-dark()`). Fonts (Roboto, Material Symbols) come from npm packages and are bundled; nothing is loaded
  from another site.
- The dev server (http://localhost:4600) proxies `/api` and `/setup.php` to `http://127.0.0.1:8400`
  (`proxy.conf.json`). `ng build` writes `dist/frontend/browser/`, which `router.php` serves in development and
  `scripts/build-dist.php` puts into the upload.
