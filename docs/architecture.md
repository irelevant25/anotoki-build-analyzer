# Architecture

The build analyzer imports Genshin Impact accounts, brings in recommended builds as a file an editor imports on
the admin panel's Builds page - one file per game, put together elsewhere from community build sites; the site
itself never fetches a build -, and compares every owned character with every build of theirs, by the
character's constellation. Since 0.5.0 it offers Genshin Impact alone (Honkai: Star Rail went, the owner's
decision of 2026-10-20 - [decisions.md](decisions.md)); the multi-game shape is kept: `App\Enums\Game` has one
case, and every fact that differs between games is one of its methods. People sign in through the anotoki IAM -
the only way in, and open to every anotoki account; each person sees only their own game accounts, while the
catalog and the builds are shared (and public).

```text
genshin.anotoki.eu ──catalog refresh──▶ game_characters / game_weapons / game_sets   (names, IDs, rarity, element, region, version, icons)
                                   ▲ name/ID resolution (Catalog)
showcase API / GOOD files ──ImportService──▶ accounts (per user), account_characters, _weapons, _items
a builds file (one per game) ──BuildImport──▶ builds (+ build_imports)
                                   ▼
                     CharacterAnalyzer ──▶ REST API (/api, bearer token) ──▶ Angular app (/)
iam.anotoki.eu ──access token (RS256)──▶ Angular app ──Authorization: Bearer──▶ API (verified locally)
```

The catalog comes from the owner's own genshin.anotoki.eu (its public catalog lists); the showcase service and
the file formats are named in [import-formats.md](import-formats.md). How a builds file is put together - by the
owner's untracked local tools, which read the build sites - is [builds.md](builds.md), which names no site: the
repository is public and names none. The UI never names the services, tools or sites behind those arrows (see
"Neutral names" below).

## Backend (`backend/`, PHP 8.4+, Slim 4, PostgreSQL)

No framework beyond Slim's router and PSR-7 messages: a front controller, route files, middleware, a small
parameterised PDO layer, SQL migrations and plain PHP scripts for the command line - the same shape as the
other anotoki sites (the genshin site, the IAM).

| Area | Where | Notes |
| --- | --- | --- |
| Entry points | `api/index.php` (front controller, `/api/*`), `api/app.php` (`buildAnalyzerApi()`: routes + middleware), `router.php` (PHP's built-in server in development), `setup.php` (installer) | the tests call `buildAnalyzerApi()` in-process |
| Routes | `api/routes/{meta,icons,accounts,imports,visitor,me,builds,admin_builds,admin,admin_tools,translations,people,feedback}.php` | each returns `function (Slim\App $slim, App $app)`; the sign-in or role check sits on each route group |
| Middleware | `api/middleware/errors.php` (JSON errors; an exception into the error log), `security.php` (headers), `site_gate.php` (the site gate - anotoki-lib's `SiteState` and `SiteGate`: 503 `not_set_up` / `update_pending`, its open paths), `upkeep.php` (after an answer: the people's scrub, the day's backup, the day's tidying up, a stale catalog list - see "The upkeep after an answer"), `auth.php` (`requireSignIn()`, `requireOwner()`, `requireRole()`, `requireTokenRole()`, `optionalSignIn()` - each the library's `Identify` and checks in order, `App\Web\Pipeline` -, `makeVisitor()`, `currentUser()`, `currentUserOrNull()`, `throttle()`); the library's `ErrorLogMiddleware::outer` around them all | Slim runs the last one added first |
| Wiring | `src/App.php` (a small container: `get()` builds a class once per request scope, `resetScope()`), `src/Config.php` | `Db` and the HTTP client last the whole process; everything else is per request |
| Configuration | `config/app.php` (committed: each game's catalog provider and how often a list is refreshed, showcase, the limits, `client_address`, `upkeep`, aliases), `config/{database,auth}.local.php` (per installation, written by `setup.php`; required) and `fetch.local.php` (optional, `Config::FETCH_DEFAULTS` without it); by hand `client_address.local.php`, `cloudflare.local.php` and `neutral.local.php` (the names of the build sites, read by `Config::readOptionalFile()`; see "Neutral names") - each read cleanly or not at all (`Config::readLocalFile()`: never compiled unless it starts with `<?php`, nothing it prints sent), one passed over never read looser than none ([operations.md](operations.md), "Configuration") | no `.env`; one environment variable, `BUILD_ANALYZER_TEST_CONFIG`, for the browser check alone (a settings folder standing in for `config/` and `storage/`, honoured only for a `build_analyzer_test_` database) |
| Database | `src/Db/Db.php` (PDO helpers: `one`, `all`, `value`, `insert`, `update`, `run`, `transaction`; `ensureDatabase()`), `src/Db/Migrations.php` (this site's files and bookkeeping table on the anotoki family's engine, anotoki-lib's `Migrator`) | always parameterised; identifiers are literals in the code |
| Rows | `src/Models/*` (plain classes made from rows), `src/Repositories/*` (the queries) | `Accounts::findOwned()` / `owned()` scope every account query to its owner |
| Domain basics | `src/Enums/Game.php` (one case, Genshin; every per-game fact a method - slots, roles, the highest constellation, set kinds and piece counts, elements, weapon types, labels ...), `src/Support/{Stats,Talents,Key,MainStatValues}.php` | slots, stat keys, label parsing; see [stats-and-slots.md](stats-and-slots.md) |
| Sign-in | anotoki-lib's auth module (`Anotoki\Lib\Auth`: `IamClient`, `AccessTokens`, `SignInRule`, `Http\Identify`, `RequirePerson`, `RequireRole`, `RequireCaller`; `AuthRoutes::configBody()` in `/api/meta`), made in `src/Auth/Iam.php`; `src/Auth/{Role,Visitors,RequestCaller}.php`, `src/Models/User.php` (`fromPerson()`); the tests' IAM `Anotoki\Lib\Auth\Testing\TestIam`, the stand-in `vendor/anotoki/lib/backend/testing/stand-in-iam.php` | see "Sign-in" below |
| People | anotoki-lib's people module (`Anotoki\Lib\People`: `People`, `PeopleRecorder` through `RecordPerson`, `Scrubber`, `PeopleUpkeep`, `PeopleRoutes::admin()`; its set `anotoki_people`) on this site's `users` - `src/People/SitePeople.php` (the mapping, the columns, the scrub hooks, the delete), `api/routes/people.php`, `src/Repositories/Users.php` (`visitorCounts()`, `deletePerson()`) | ADMIN: who used the site, and deleting a person's data; the accounts the IAM deleted made nobody's |
| Admin tools | anotoki-lib's (`Anotoki\Lib\ErrorLog`, `Audit`, `Feedback`, `Backups`; `Admin\AdminContext`; its set `anotoki_admin`) made in `src/Admin/AdminTools.php`; `api/routes/admin_tools.php`, `feedback.php`; `backup.php` | ADMIN alone; see "The admin tools" below and [operations.md](operations.md) |
| Outgoing HTTP | `src/Http/Fetcher.php`, `CurlHttpClient.php` | the catalog's provider and the showcase service: a per-host delay kept in the cache table, retries on 429/5xx (in a web request once at most, after a short wait), an optional disk cache (`storage/http-cache`, which the day's tidying up clears) |
| Cache and locks | `src/Cache/{Cache,Prune}.php` | the `cache` table (the host has no Redis or shared memory): request delays, the limits' counters, short claims (`Cache::add()`), where each catalog list stands; `Prune` the day's tidying up. What must be one at a time across a transaction takes a PostgreSQL advisory lock (an apply of migrations, an import of builds) |
| Catalog | `src/Services/Catalog/{Catalog,CatalogSync,CatalogUpkeep,CatalogProvider,CatalogProviders,CatalogAnswer}.php`, `Providers/GenshinCatalog.php` | each game's catalog from its provider (Genshin Impact's from genshin.anotoki.eu), list by list, never deleting a row; imports' names and IDs resolved to catalog rows; see "Main flows" below and [name-matching.md](name-matching.md) |
| Imports | `src/Services/Import/ImportService` → parsers → `ParsedAccount` → `AccountImporter`; `ImportKinds.php` | always for one user; errors and warnings are kinds with params; see [import-formats.md](import-formats.md) |
| Own data as a file | `src/Services/Transfer/{AccountsFile,AccountsTransfer,AccountsFileException}.php`, `api/routes/me.php` | a signed-in person's game accounts exported to / imported from a file (never a visitor's); see "Main flows" below and [data-model.md](data-model.md) ("Accounts files") |
| Builds | `src/Services/Builds/{BuildFile,BuildImport,BuildImportBusy,BuildFileException}.php`, `src/Repositories/Builds.php`, `src/Models/Build.php`, `api/routes/admin_builds.php`, `api/routes/builds.php` | a game's builds - each a variation of a character's, with the teams it is played in - imported from a file (format version 3) and exported to one on the admin panel's Builds page; the builds browser. See "Main flows" below, [builds.md](builds.md) and [data-model.md](data-model.md) ("Builds", "Builds files") |
| Analysis | `src/Services/Analysis/*` | by the character's constellation; recommendations are kinds with params (`BuildEvaluator::KINDS`); see "Main flows" below and [analysis.md](analysis.md) |
| The site's words | anotoki-lib's translations (`Anotoki\Lib\Translations`: `Translations`, `TranslationsRoutes`, `TranslationsCommand`, `KeyScanner`; its migration set `anotoki_translations`) with this site's own in `src/Services/Translations/SiteTranslations.php` (the released languages, `names_another_site`, the key scan's configuration), `api/routes/translations.php`, migrations 003-006 | see "The site's words" below and [data-model.md](data-model.md) |
| Output | `src/Presenters/*` (accounts, builds, icon addresses), `src/Support/Neutral.php` | what the API sends, in the shapes of `frontend/src/app/api/models.ts` |
| Command line | `migration.php`, `sync-catalog.php`, `import-account.php`, `translations.php`, `backup.php` (→ `src/Commands/*`; `translations.php` is anotoki-lib's `TranslationsCommand`, `backup.php` its `BackupsCommand`); `tools/check-names.php` (see "Neutral names") | the same work as the pages (`sync-catalog.php [game] [--list=characters\|weapons\|sets] [--force]`: the catalog, as the Builds page refreshes it); nothing is scheduled |
| Setup | `setup.php` + `src/Setup/Installer.php` | writes `config/*.local.php`, builds the database; see [operations.md](operations.md) |
| Deploy | anotoki-deploy (anotoki-lib's), `composer.json`'s `extra["anotoki-deploy"]`, `tools/check-release.mjs` | `dist/frontend/` and `dist/backend/`, a release replaced whole on the host; see DEV.md "Deploying" |

### The site gate

One behaviour for every anotoki site while it is not ready (the shared library anotoki-lib, the owner's
decision of 2026-10-07 - [decisions.md](decisions.md)). For every request the server decides where the site
stands (`api/middleware/site_gate.php`: `siteState()`), afresh each time:

| State | When | What the API answers |
| --- | --- | --- |
| not set up | `Config::load()` found a required local file missing or incomplete (no database, no IAM issuer or client id; the log says which file and which value) | every path `503 {"code": "not_set_up", "installed": …, "message": …}`, with `"setup": "/setup.php"` only while the site was never installed - `storage/installed.lock`, which `setup.php` writes when it finishes on a real host (never on this computer) |
| update pending | a migration file is ready to apply | every path `503 {"code": "update_pending", "message": "The site is being updated. Try again in a few minutes."}` with `Retry-After: 60` - but the open paths (`siteOpenPaths()`): `/api/meta` (where people sign in), `/api/translations/{code}` (the pages' words, opened by the library's own pattern, `TranslationsRoutes::openPath()`; its route answers `translations_unavailable` itself while the tables are not there), the Migrations page's three routes, `/api/admin/migrations`, `…/file` and `…/apply`, the error log's (`ErrorLogRoutes::openPaths()`: `/api/admin/errors` and one fingerprint) and the backups' but a restore (`BackupsRoutes::openPaths()`: the list, `/proof`, one backup, its ticket, file and preview - a backup just before Apply is the point) - an ADMIN's, judged from the token alone: `requireTokenRole()`. Each is a route exactly, never a prefix: the gate reads the path decoded, as the router does, and neither resolves dot segments, so a crafted `/api/admin/migrations/../x` (or `%2e%2e`, `%2F`) stays closed |
| unavailable | the database cannot be reached | everything passes: the routes answer as they do without a database (500 `server_error`) |
| closed for maintenance | ready, and the site's ADMIN has switched the site settings' Getting in on (anotoki-lib 0.11.0, "The site settings", below) | every path `503 {"code": "maintenance", "message", "maintenance_message": {en, sk, …}}` with `Retry-After: 300` - but the open paths above and the settings' reserved API paths (`/api/meta`, `/api/icons`, the status, the words, the settings' two), each itself and everything under it - to everybody but the site's ADMIN, judged from a verified token alone (the gate's `exempt`: `AccessTokens::personOf()` - it runs before any route group's Identify; a forged, expired or other site's token is nobody). The status says `maintenance`; nothing of it goes into the error log |
| ready | otherwise | everything passes |

`GET /api/site-status` always answers `{state, installed}` - how the pages learn which - to anybody: it reads no
credentials, so it answers while the IAM requires sign-in too (a 401 would read on the pages as "not
available"), and the frontend sends it neither token nor visitor key (the family's interceptor skips it, `api.skip` in `app.config.ts`). The gate sits inside
the JSON errors and in front of routing, so an unknown path is a 503 too while the site is closed. There is
no default database or IAM to fall back on. The routes that need the newer tables keep their own 503s
(`translations_unavailable`, `visitors_unavailable`) as a second line, for whatever reaches them with the
tables missing.

The frontend (`@anotoki/lib/migrations`, wired in `shared/site-status.ts`): `SiteStatus.check()` at the start,
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
made at all, so it sends no request and leaves no error or toast behind. The gate's page stands
bare - no bar, no footer, nothing of the frame - while an update waits, the database is out of reach or the site
is not set up: the bar and the footer are the layouts', routes inside that template too (`app.spec.ts` holds it
in every state, with the real layouts). (0.1.0 took the outlet
as plain content, which Angular made whether it showed or not; `app.ts` kept it out with a condition of its
own, `pagesMade`, gone since.) Nothing in the boot stops while an update waits: the sign-in (and its callback)
completes, the words are asked for. The gate's pages and the Migrations, Translations and Languages pages -
and since 0.4.0 the People page, the admin tools, the feedback form and the screen of a sign-in that failed -
are drawn with the library's kit (0.2.0; 0.3.0 gave every class of the kit its own name,
`anotoki-<piece>__<part>`), in this site's colours: the `--anotoki-*` properties in `styles.scss`. Their words
are the database's (`anotoki.siteStatus.*`, `anotoki.ui.*`: `TranslationService.forKit()` and
`siteStatusWords()`), the library's own under them. The Migrations page names this site's files by their names
(`004_….sql`) and the library's with their set (`anotoki_admin/001_….sql`, `anotoki_translations/001_….sql`,
`anotoki_people/001_….sql`): four sets are configured, one of them the site's.

### The site settings, robots.txt and sitemap.xml

The anotoki family's site settings (anotoki-lib 0.11.0, the owner's request of 2026-10-25; [decisions.md](decisions.md);
the routes and what each part does: [operations.md](operations.md), "The site settings"): the library's `SiteSettings`
on its tables (`anotoki_admin/003`), with this site's part in `App\Web\SitePages` - all three parts, the reserved API
paths (`RESERVED_API_PATHS`: `/api/meta`, `/api/icons`, beside the library's own), the reserved pages
(`RESERVED_PAGES`: `/sign-in`, beside the library's), and the public pages Page access governs with the API paths each
reads (`GOVERNED`). The container keeps one `SiteSettings` across requests (`App::LASTING`): it reads its tables afresh
for every question, and while `003` waits every switch is at its default and no page is governed.

- **Getting in**: `siteState()` gives the settings to the library's `SiteState` and `siteGate()` the ADMIN's check to
  its `SiteGate` (`exempt`; the library builds no gate for a site that can be closed without one). `GET
  /api/site-status` says `maintenance` with the administrators' message.
- **Page access**: `pageAccess($app)` (`api/middleware/auth.php`) - the library's `PageAccessGate` - in every route
  group's middleware, right after its own check (`RequirePerson`, `RequireCaller`, the role check): the person Identify
  found is the reader (a visitor is nobody), an API path a row refuses with its page answers `423 page_locked` in this
  site's body, never to the ADMIN and never on a reserved path; for the ADMIN it reads no table at all. The routes with
  no Identify (`/api/meta`, the icons, the bundle, the status, the settings' public route) are all reserved.
- **The admin routes** (`/api/admin/site-settings[/pages[/{id}]]`, `SiteSettingsRoutes::admin()`) behind
  `requireRole(ADMIN)`, audited by the site's `AuditLog`; the People page's delete leaves what a person changed by
  nobody (`SitePeople::deleteData()`).
- **robots.txt and sitemap.xml** (`api/routes/robots.php`, the library's `Robots` from `SitePages::robots()`): the
  site's address from `Config::siteAddress()` (config/site.local.php, else where the code runs), never a request's
  `Host`; the sitemap's own entries `SitePages::publicEntries()` - the dashboard, the import, each game's builds and
  every character with builds (`Builds::charactersWithBuilds()`, its newest `changed_at` or `edited_at`) - only while
  the IAM lets visitors in (`SignInRule`); what Page access hides the library leaves out. Neither path is under `/api`:
  `router.php` (`App\DevRouter::ROBOTS_PATHS`), the release's `.htaccess` (`composer.json`'s `routes`) and the dev
  proxy send both to the front controller.

### Sign-in

- The anotoki IAM (iam.anotoki.eu, OpenID Connect) signs people in - the only way in, on a host and on the
  owner's computer alike; this app is its client `build-analyzer`. Who may sign in here is the IAM's to decide
  (every account holds `MEMBER` in every application; the IAM's admin panel picks which roles may sign in to
  this one). Roles in the token's `roles` claim: `EDITOR` (the Builds page - a game's builds imported and
  exported, changed by hand in the builds editor with the site's notes on them, the catalog refreshed - and the site's
  strings) and `ADMIN` (all of that, and migrations, adding /
  renaming / switching / deleting a language, People, the admin tools). ADMIN includes EDITOR; `MEMBER` - and
  any other role name - is an ordinary signed-in person: no check needs it and none refuses it
  (`User::withRoles()` keeps `Role::STAFF` only).
  Taking somebody's `MEMBER` away at the IAM blocks their next sign-in and refresh (the access token they hold
  still verifies until it expires, minutes); while sign-in is not required they can still use the site as a
  visitor - blocking stops signing in, not visiting; to keep somebody out entirely, require sign-in. The
  contract: `anotoki-iam/docs/integrating-an-app.md`.
- **The anotoki family's auth module does the signing in** (anotoki-lib 0.5.0, `Anotoki\Lib\Auth`; the owner's
  round of 2026-10-11): its `IamClient` (`src/Auth/Iam.php` makes it from `config/auth.local.php`), the token
  check, the IAM's switch and the request's person are the library's, the route groups' rules this site's.
- **Whether sign-in is required here is the IAM's switch** ("Sign-in required" on the application's page in
  the IAM's admin panel), read by the server: the library's `SignInRule` - `GET
  {issuer}/api/public/applications/build-analyzer`; a 200 about this client is its `login_required`, a 404
  (an unknown or switched-off application) is *required*; no answer, a 5xx or anything else is the last value
  read, or *required* when none ever was (fail closed). The answer is kept a minute in `storage/cache/iam/`
  (`anotoki-iam-application-<sha1>.json` - a folder only the site writes: a kept "not required" is what opens
  the site to visitors). Asked once a request, and only for a request from nobody or a visitor (a signed-in
  person's never need it) - and by `/api/meta`, whose `auth` is the library's boot configuration
  (`AuthRoutes::configBody()`: `issuer`, `client_id`, `login_required`, and `sign_in_available` - false once the
  IAM answered that it does not know the site), so the page never asks the IAM. `auth.local.php` may set
  `jwks_file`, `application_file` and `anonymized_file` (the IAM's answers from files: the tests' seam,
  `TestIam`); a host sets none of them.
- **Sign-in required**: every route answers nobody - and a visitor key - 401 `sign_in_required`, but
  `/api/meta`, `/api/translations/{code}` and `/api/icons/*` (what the sign-in page needs, and `<img>`s, which
  send no token), and `/api/site-status` (where the site stands, which every page asks first). The key is refused before it is looked up (`requireOwner()`), so no visitor is seen,
  counted, made or swept early; the move at sign-in (token and key) still works, so what a visitor imported
  comes into the account when they sign in, and switching the requirement off again within 30 days brings the
  key back. **Not required**: the site as below - the builds browser for everybody, visitors.
- The library's `Identify` (on each route group, before the group's rule) reads `Authorization: Bearer` and
  verifies the token with its `AccessTokens` (RS256 only, `typ at+jwt`, `iss` = the configured issuer, `aud`
  contains the client id, `exp`/`nbf` with leeway, a key from `{issuer}/.well-known/jwks.json` cached in
  `storage/cache/iam/`). A failure is logged with its reason (`[anotoki auth] token refused`, through the site's
  `storage/logs/app.log`) and answered 401 `invalid_token` with `WWW-Authenticate: Bearer realm="build-analyzer",
  error="invalid_token"`; no token where one is needed is 401 `sign_in_required`.
- The person's row (the library's `RecordPerson`, `App\People\SitePeople`): `users.id` is the IAM user id
  (`sub`), `users.name` follows `preferred_username` ("user {id}" without one), `last_seen_at` is written at most
  every five minutes; a row the scrub made nobody's is never written again. Roles are never stored; they come
  with each token. The error log names who asked from the token as well (`App\Auth\RequestCaller`).
- **Credentials that are sent must verify.** A request with an `Authorization: Bearer ...` header (with or
  without a token after the word; or Apache's `HTTP_AUTHORIZATION` / `REDIRECT_HTTP_AUTHORIZATION`) is that
  person or it is answered 401 `invalid_token` - on public routes too; it is never answered as if it had sent
  nothing, and its visitor key is not read. Only the Bearer scheme counts: a `Basic ...` header, which a host
  behind a password file sends with every request, is as if none were sent. Nothing else in a request (a body,
  a parameter, an address, another header, the IAM's sign-in hint cookie) makes anybody somebody. A `mode` or
  `local_user_id` left in an older `auth.local.php` is ignored.
- No CORS headers at all: the app and the API share one origin (the Angular dev server proxies `/api`), so
  browsers keep every other site from reading the API or sending it a token.
- The checks sit on route groups, so a route added to a group cannot go without one:
  `optionalSignIn()` for the public routes (`/api/builds` and the paths under it; 401 for nobody while sign-in
  is required), `requireOwner()` for one's
  own accounts and imports (the signed-in person, else this browser's visitor - below), `requireSignIn()` for
  what only a signed-in person has (the accounts file `/api/me/*`, the move `/api/visitor/move`),
  `requireRole(...Role::STAFF)` for the editors' routes - the Builds page's, the strings grid, the languages
  list (403 `editor_only`) -, `requireRole(Role::ADMIN)` for
  the admins' own - People, the audit log, feedback's admin side (403 `admin_only`) - and
  `requireTokenRole(Role::ADMIN)` for the Migrations page's, the error log's and the backups' (the same answers
  from the token alone - no row of `users` read or made -, as they answer while an update waits). Each is the
  library's pieces in order (`App\Web\Pipeline`: `Identify`, then `RequirePerson` / `RequireRole` /
  `RequireCaller`, then `RecordPerson`), in this site's words (`{message, code}`). The library's own route
  groups (People, the admin tools) check the ADMIN again themselves. `POST /api/feedback` is
  `optionalSignIn()`'s. `/api/meta`, `/api/icons/*`, `/api/translations/{code}` and `/api/site-status` read no
  token at all; nor does a backup's download (`GET /api/admin/backups/{id}/file?ticket=`: the single-use ticket
  is its check, as a browser's navigation sends no token).

### Visitors

Somebody who uses the site without signing in gets a **visitor account** (`src/Auth/Visitors.php`; why:
`decisions.md`, 2026-10-04): a row of `users` with a negative id, opened by a key the server made. Who a
request is, decided in `requireOwner()` and nowhere else: a token (it must verify), else - while the IAM
requires sign-in - 401 `sign_in_required` without reading the key, else `X-Visitor-Key` (its SHA-256 is a
visitor row's, or 401 `visitor_unknown`), else nobody (401 `sign_in_required`). So a visitor
reaches exactly what `requireOwner()` guards - its own accounts and imports, through the same
`Accounts::owned()` / `findOwned()` a member's go through - and nothing else; it never has a role.

- **Made** by `POST /api/imports/showcase|file` with neither token nor key: `requireOwner($app, true)` lets
  such a request on as "a visitor to be made" - when it is JSON (`jsonFromNobody()`: anything else, which any
  page could send without asking first, is 415 `unsupported_media_type` before anything is counted or made) -,
  the route's throttles count it per address, and `makeVisitor()`
  (innermost) checks the visitor limits, makes the row and the key, runs the import, and on success adds
  `X-Visitor-Key` (and `Cache-Control: no-store`) to the answer - the only time the key leaves the server.
  A failed first import deletes the visitor again and gives its counts back.
- **Limits** (`config/app.php` `visitors`, `client_address`): per address (`App\Web\ClientAddress`, by the
  anotoki library's one rule, `Support\ClientAddress`: `REMOTE_ADDR`, or on a connection from one of
  Cloudflare's ranges - the committed `trusted_proxies`, the owner's decision of 2026-10-11 - its
  `CF-Connecting-IP`, one address or none believed - with the origin secret, `config/cloudflare.local.php`
  (anotoki-lib 0.5.2, the owner's decision of 2026-10-13), only on a request that carries it in
  `X-Anotoki-Origin`, any other from Cloudflare counted as its edge - and with a file there that cannot be read
  cleanly or names no usable secret, on no request at all (it fails closed; only deleting the file switches the
  secret off); IPv6 by /64, `::ffff:` IPv4 as IPv4 - the
  admin tools read by the same rule, list and secret) and
  site-wide; a visitor's imports throttled per address; per visitor a few accounts and smaller files (422
  `visitor_limit`; the accounts counted by the route before any work, and again by `AccountImporter` under the
  owner's lock, so two imports at once cannot both add the last one). The counters never keep the address:
  their `cache` keys hold an HMAC of it under the installation's key (`ClientAddress::countedKey()`).
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
  After the IAM's hint's silent hop the page says so (`{silent: true}`), and then nothing moves into a person
  who has a game account or an import run here already - asked under the same locks - `held: true`, the visitor
  as it was (the owner's decision of 2026-10-11).
- **Before migration 004** the site gate closes the site until it is applied; behind it, every visitor path
  answers 503 `visitors_unavailable` (a second line) and the clean-up does nothing.
- `/api/meta` sends `visitors.keep_days` for the page's notice; the People page counts visitors apart and never
  lists them.
- **In the browser** (`frontend/src/app/shared/visitor-key.ts`, see "Frontend"): the key is kept in
  localStorage `build-analyzer-visitor`, read again for every request, never replaced by a second one, sent only
  on a request issued as a visitor's while the site does not require a sign-in, forgotten on 401
  `visitor_unknown`, and handed to the move once somebody signs in. While the IAM requires sign-in
  (`auth.login_required` in `/api/meta`, `AuthService.loginRequired`) the key is kept but never sent, and no new
  one is taken; a 401 `sign_in_required` never forgets it - the next sign-in moves what it holds, and switching
  the requirement off within 30 days brings it back. Every page then asks for a sign-in first
  (`sitePagesGuard`: the family's `personOrVisitorGuard`).

### Per-user data

`accounts` and `import_runs` have `user_id` - a person's IAM id, or a visitor's negative id. `Accounts::owned()` / `findOwned()` scope every list and lookup;
an account id in a path that belongs to someone else is a 404. Imports get the owner passed down to
`AccountImporter` (target account, UID lookup, failed runs). The catalog, the builds and the record of their
imports are shared.

### People, and the admin tools

The anotoki family's people module and admin tools (anotoki-lib 0.5.0; the owner's rounds of 2026-10-11 -
[decisions.md](decisions.md)), wired once in `src/App.php` from `src/People/SitePeople.php` and
`src/Admin/AdminTools.php`:

- **People** read this site's own `users` through a mapping (`PeopleTable`: `name`, `created_at`,
  `last_seen_at`, `updated_at`), never a table of the library's; visitors' rows (negative ids) are never read or
  written. The People page's columns are this site's (game accounts, imports, last import), its Delete
  `SitePeople::deleteHook()` → `deleteData()` (the person's row and, by the foreign keys, their accounts and
  imports; the strings they saved and the record of the builds files they imported stay, by nobody -
  `translations.updated_by` forgotten, `build_imports.imported_by` NULL with the row). It runs inside the
  library's transaction (`People::delete()`, which also forgets the person's feedback - its address and browser -
  and writes the `person.data_deleted` entry in the same transaction), and does all its work on the connection it
  is given, beginning and ending no transaction of its own: a restore runs it again on its own connection, every
  restored table locked.
  **The scrub** (the library's `Scrubber`, after an answer at most hourly - `api/middleware/upkeep.php` - or
  Check now, and at once after a restore) asks the IAM for the accounts it deleted and makes each one nobody's
  here, in one transaction per account: `deleted-{id}`, `anonymized_at`, and the hook, on the scrub's
  connection - their feedback's address and browser and their name in the audit log's details are blanked. Their
  game accounts and imports stay, and so does the record of the builds files they imported, which keeps their id
  and never their name.
- **The admin tools** (`AdminContext`: who acts - the token's person -, the ADMIN from the token, the proof - a
  sign-in of the last ten minutes, from the token's `auth_time` -, the proxies whose header names an address -
  `client_address`'s, read by the library's rule as `App\Web\ClientAddress` reads -, the people's names by id,
  this site's error body): the error log is the library's `ErrorLog` around every request
  (`ErrorLogMiddleware::outer`), in `jsonErrors()` for an exception, and behind `POST /api/client-errors` for
  the errors the pages meet in the browser (`ErrorLogRoutes::client()`, `optionalSignIn()`'s; the app's
  `provideAnotokiErrorReport()`); the audit log is written where something is done (`AuditLog::record()` in the
  routes of the Builds page - `builds.imported`, inside the import's own transaction, `builds.edited`, inside the
  editor's save's, and `catalog.refreshed` -,
  translations, people, migrations, and the tools' own - a person's or a message's delete inside its own
  transaction); feedback's public form is `optionalSignIn()`'s; backups are COPY on a
  connection of their own (`AdminTools::copyConnection()`: `Pdo\Pgsql` where PHP has it), made before every
  Apply (`beforeApply`, the Migrations page's and `migration.php`'s), daily after an answer, before every
  restore, and by hand, each sealed under this installation's key - only a backup made here is restored here
  (one made while that key could be neither read nor made is unsealed, the error log says so, and it is
  restored offline only) - and a restore does again, inside its transaction, every People-page and feedback
  delete made since its backup, then the scrub (`People::restoreHooks()`); what people deleted themselves since
  comes back with it. The `cache` table is never restored (`keep: ['cache']`): what the site counts by the
  minute, its claims and where each catalog list stands stay as they are. Their tables come with the library's
  set `anotoki_admin`, which runs before this site's files; while it waits, the audit log keeps its entries in
  `storage/logs/audit-pending.jsonl` and writes them after the next Apply, and the audit and feedback routes
  answer 503 `feature_unavailable`.

### Neutral names

The UI names no other website, service or tool - not in labels, links, credits, errors, tooltips or the
changelog. The one exception is the legal pages (`/terms`, `/privacy`, `/contact`), which name who else handles
the data, as the law asks: the hosting company WebSupport s.r.o. (`HOSTING` in `sites/main/legal/legal-facts.ts`, its address
the code's), Cloudflare and Umami (the library's words) - never a build site, a data provider, a tool or the
showcase service, which they describe without naming. The family's About and Support pages (`/about`, `/support`,
anotoki-lib 0.8.0: the owner's words, the same on every anotoki site) name and link the anotoki sites and the
owner's Ko-fi page - the library's words and addresses, nothing of the site's. And since 1.0.0 the import guide's
file method shows the project's own scanner's PowerShell line and links to its README - the project's GitHub
repository, the only address of the site's own, kept in the frontend's code (`sites/main/import/scanner.ts`) and
never in a string ([game-scanner.md](game-scanner.md); the owner's request of 2026-10-27). So the API never sends a
site's name or address either:

- **The build sites are named nowhere** - not on the site, not by the API, and not in the repository, which is
  public (the owner's decision of 2026-10-20, [decisions.md](decisions.md)): no tracked file, commit message,
  branch or pull request names one. A build has no source and no page address; the builds file names no site,
  and every text in it passes through `Neutral::text()` on the way out and again on the way in (`BuildFile`), and
  once more in every answer (`BuildPresenter`). Their names live only in the owner's untracked, optional
  `config/neutral.local.php` (`['names' => [...], 'tree_words' => [...]]`, written by hand and uploaded to a
  host's `config/` once, like `cloudflare.local.php`), read once by `Config::readOptionalFile()` - cleanly or
  not at all: a file that cannot be read cleanly, or names nothing, adds nothing, and the names `Neutral` holds
  itself still apply. Its `names` are added to `Neutral`'s own (`Neutral::localNames()`; the tests set none,
  `Neutral::setLocalNames([])`, so the owner's file never changes a result). `backend/tools/check-names.php`
  reads every file git tracks and every file `git add -A` would add (`--staged`: what is staged;
  `--message=<file>`: a commit message, git's comment lines aside) for the file's `names` (whole words, as
  `Neutral` matches them; in a path however the path joins their words, `a-name/`, `aName.php`) and `tree_words`
  (anywhere: a typo, a page's part, a label that would give a site away), its paths too - a binary file's as
  well; prints each place it finds one - the path and line, and what it found -, never the file's list; and
  exits 1 on any. The owner's untracked git hooks run it before a commit (`pre-commit` with
  `--staged`, `commit-msg` with `--message`). Without the file it has nothing to look for, and says so. The
  scraping itself lives in the untracked `local/` folder (the root `.gitignore`), and nothing is copied from it
  into a tracked file ([builds.md](builds.md)).
- Import runs say `showcase` or `file`, never the service or tool; an account's `meta` is filtered to neutral
  keys. A person's accounts file is held to the same: sources as `showcase` / `file`, the neutral meta keys only,
  no tool name, every text through `Neutral::text()` on the way out and again on the way in.
- Text that came from elsewhere (errors, a builds file's notes and names, an uploaded account file) passes
  through `Neutral::text()` on the way out: web addresses become "the source page", names of sites and tools
  "the source". `backend/tools/check-release.php` (run with every release's frontend build) refuses a frontend
  build that contains one of those names.
- Icons: the catalog stores the provider's icon address - only one on the provider's own host (https; http only
  for this computer), with no user part, no backslash and nothing but printable ASCII (`GenshinCatalog`); the API
  sends `/api/icons/{c|w|s}/{id}`, which fetches the image a catalog row names - never an address a request
  gives - once into `storage/icons/` and serves it from this site (PNG, JPEG, WebP, GIF, and AVIF -
  genshin.anotoki.eu's format -, by their first bytes: `Icons::type()`).
- The site's words: no string names another site or holds a web address, whether a migration seeded it or the
  editor reworded it in the browser (or imported it from a file, or the command line). This site's last check
  of the library's (`SiteTranslations::namesAnotherSite()`, its `extraCheck`) refuses one (422
  `names_another_site`, checked last, in any language, also in a language's names), and
  `tests/translation_catalogue.test.php` puts every stored string through the same checks
  (`Translations::storedRefusals()`). A word refused only because it contains a name is reworded; the list of
  names is not changed for it.

### Main flows

- **Import**: `POST /api/imports/showcase` or `/file` (account_id checked against the user's accounts) →
  `ImportService::fromEnka()` / `fromFile()` (format detected from the JSON) → the parser builds a
  `ParsedAccount` with catalog *references* (`external_id` / `name` / `key`) → `AccountImporter::import($parsed,
  $owner, $target)` refreshes a missing catalog list if allowed, resolves references (lookups only, never
  creating catalog rows), upserts characters, replaces gear, writes an `ImportRun`. Failures are recorded as
  failed `ImportRun`s of the user; a refusal is 422 `{message, code, params}`, which the wizard words from
  `code` and `params` (import-formats.md, "Errors and warnings are kinds").
- **Builds import** (the admin panel's Builds page, an editor; the site never fetches a build itself):
  `POST /api/admin/builds/{game}/import[?dry_run=1]` (the file's JSON as the body; nothing arrived - what PHP
  makes of a body past its `post_max_size` - or more than 8 MB: 422) → `BuildImport::import()`:
  `BuildFile::read()` checks the whole file for the game, strictly (a version 1 file is refused with its own
  message; [data-model.md](data-model.md), "Builds files") → its characters matched with the catalog by key -
  one the catalog lacks has the characters' list refreshed first, at most once per game and list per
  `catalog.import_refresh_minutes`, and is skipped and named if it is still not there; a file with no character
  the catalog knows is refused, as "importing" it would remove every build -, its weapons likewise (one the
  catalog still lacks stays, `known: false`) → compared with the game's stored builds by identity (character,
  role, qualifier without regard to case, `min_rank`): created; updated when its content hash, its place among
  the character's builds or its qualifier's spelling changed - it keeps its id, so a link `?build=ID` survives -;
  unchanged; removed (every build of the game the file lacks) - but for what editors did by hand, which an import
  keeps part by part and asks about per build only where the file changed the same part ([builds.md](builds.md),
  "Hand edits": `builds.file_build`, the questions and their plan, `build_removals`, `build_orders`). A dry run
  answers that and writes nothing. An
  import does it in one transaction under an advisory lock (`pg_try_advisory_xact_lock`, `BuildImport::LOCK`: a
  second import meanwhile is 409, nothing written; `SET LOCAL` statement and idle timeouts of 60 s), comparing
  again inside the lock, removing first and creating last (no identity is there twice, even for a moment), and
  recording itself in the same transaction: a `build_imports` row and the audit log's `builds.imported`.
  `checked_at` of every build the file has is when the file was made, `changed_at` of every build it created or
  changed too - a time later than now is now. One that has started finishes when the tab is closed
  (`ignore_user_abort`; at most `BuildImport::MAX_SECONDS`, 240).
- **Builds editor** (the admin panel, an editor; [builds.md](builds.md), "The editor"): `GET
  /api/admin/builds/{game}/characters/{key}` → `BuildEditor::load()`: a character's builds in the editor's shape
  (the file's, without the character and the texts no page shows), what the file said of each, the parts edited, the
  site's notes, the removed builds, a version; `PUT` the same → `BuildEditor::save()`: the whole character's builds
  as the editor leaves them, each read by `BuildFile::readEntry()` (the file's own checks, the problem at its path),
  its notes by `BuildNotes`, under the import's lock (409 `busy`) and against the version loaded (409 `changed`);
  removed builds of the file's remembered, an order of the editor's said (`build_orders`); nothing changed, nothing
  written; audited `builds.edited` in its transaction. The page is `/admin/builds/:game/:key`
  (`sites/admin/build-editor/`), reached from the Builds page's Edit builds card and from a character's builds page.
- **Builds export**: `GET /api/admin/builds/{game}/export` → `BuildImport::export()`: the game's builds by
  character key (compared as bytes) and then in each character's order, `BuildFile::entry()` per build - every
  text made neutral within the reader's caps, two qualifiers of a character's that neutral words make alike told
  apart ("… #2") -, a download named `builds-{game}-{date}.json`. What it writes always imports.
- **Catalog refresh**: `CatalogSync::refresh($game, $list, $force)`, one list (characters, weapons, sets) at a
  time, from the Builds page (`POST /api/admin/catalog/{game}/{list}`, one request per list, the page runs the
  three one after another; `?force=1` asks in full; audited `catalog.refreshed`), the upkeep after an answer
  (`CatalogUpkeep`: at most every five minutes a look for a list not confirmed within `catalog.refresh_days`, 3,
  and the first found refreshed after the answer - claimed for `catalog.retry_minutes`, 60, so a failing
  provider is not asked again sooner), an account import naming an in-game ID the catalog lacks and a builds
  import naming a character or weapon it lacks (each at most once per game and list per
  `catalog.import_refresh_minutes`, 60, for everybody together), and
  `php sync-catalog.php [game] [--list=characters|weapons|sets] [--force]` (without `--force` only a game whose
  catalog is stale, and by version tag). One refresh of a list at a time: a claim of two minutes
  (`catalog-refresh:{game}:{list}`), and another meanwhile is `busy` (409 on the page). The game's provider
  (`CatalogProviders`, by `config/app.php` `catalog.{game}.provider`; Genshin Impact's `Providers\GenshinCatalog`,
  at `catalog.genshin.api` = `https://genshin.anotoki.eu/api/catalog`: that site's public `characters`, `weapons`
  and `artifacts`, bare JSON lists with an ETag) is asked before any transaction, with the list's last ETag
  (`If-None-Match`) unless forced, and its answer is a `CatalogAnswer`: 304 `unchanged`; 200 `changed` (at most
  5 MB and 5000 entries, else `failed`); 401, 423 or 503 `closed` (that site's switches: sign-in required, a
  route locked, maintenance or an update); 404 `unavailable`; anything else, or no answer, `failed`. A changed
  list is written in one transaction, each row found again by its in-game ID (`external_id`), then by its key -
  a renamed entry keeps its row and everything linked to it, unless another row holds the new key already -, and
  no row is ever deleted. How
  GenshinCatalog's rows become the catalog's: the key from the name (`Key::from()`), the element and the weapon
  type lowercased (one the game does not have - "Adaptive" - none), rarity 1-5, region, version ("6.1") and
  version name ("Luna II") as they are, `released_at` the release date; the Travelers one entry per element (key
  `traveler{element}`, `external_id` `{lowest in-game ID}-{element}`, both twins' in `meta.ids` - the way imports
  have always named them); of two weapons with one key (a quest copy) the lower in-game ID's; a set `artifact`,
  with `meta.rarities` and `meta.bonuses` (2-piece, 4-piece); an icon only on the API's own host (see "Neutral
  names"). Where each list stands - its ETag, when the provider last confirmed it, when it last changed, the last
  failure and why - is a row of the cache table (`catalog-state:{game}:{list}`), which a restore leaves as it is;
  the Builds page shows each list's entries, when it was refreshed and its last problem. So while
  genshin.anotoki.eu is closed or failing this site keeps its catalog and says why.
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
- **Analysis**: `GET /api/accounts/{id}` → `CharacterAnalyzer::overview()` (each character's `build` - the one
  the player chose, else its best, `chosen` saying which -: its variation - role, qualifier, `min_rank` -, whether it
  applies, its score and grade, the top issue);
  `GET /api/accounts/{id}/characters/{key}?build=ID` → `CharacterAnalyzer::detail()` (every build of the
  character evaluated, the best, the one chosen and the one in focus, suggestions, set plans) + full builds via
  `BuildPresenter` (each team's members with what the catalog says of them, looked up as they are shown) + the
  teammates the account has (`owned_teammates`: `{key: constellation}` of the account's characters among the
  builds' teammates and stand-ins, the character itself left out - `Accounts::characterRanks()`; `{}` for none; the
  Traveler, one character in several elements (`Game::isTraveler()`), the account's in every element once it has it
  in one - its constellation in the others null, unknown), which the page checks and links to their own analysis.
  By the character's constellation (`account_characters.rank`): the best build is, of the
  builds it reaches (`min_rank` ≤ its constellation), the highest overall score, a tie to the earlier
  (`position`: the first build is the character's main one); when it reaches none, the build needing the lowest
  constellation, marked `applies: false` - the page shows a lock and "needs C4", and never calls it the best.
  `BuildEvaluator::evaluate()` judges a build the character does not reach all the same; a build's substats are
  its own, or - when it lists none - what its own main stats ask for (`ItemScorer::fallbackSubstats()`, never
  another build's); of its stat targets, per stat, the one from the highest `from_rank` the character has reached
  (`BuildEvaluator::targetsFor()`). The build the player chose (`PUT /api/accounts/{id}/characters/{key}/build`
  `{build_id}`, `Accounts::chooseBuild()`: one of the character's own builds, reached or not, or null; kept in
  `account_characters.chosen_build_id`) is the one the account page grades the character by and the character page
  opens on; the best stays the closest match. See [analysis.md](analysis.md).

### The upkeep after an answer

No queue worker, scheduler or long-running process (the host has none), and no request runs long. What must
happen now and then rides on ordinary traffic, after an answer has gone (`api/middleware/upkeep.php`; the work
waits in `App\Web\AfterResponse`: `api/index.php` holds it until Slim has sent the answer, calling
`fastcgi_finish_request()` where PHP has it):

- the scrub of the accounts the IAM deleted (the library's `PeopleUpkeep`: at most once an hour, only while the
  site is ready - see "People, and the admin tools");
- the day's backup (the library's backups: the first request of a UTC day that finds none made, while nothing
  waits to be applied);
- the day's tidying up (`App\Cache\Prune::dailyIfDue()`, only while the site is ready): the first answer of a
  UTC day claims it (`prune:{date}` in the cache table) and, after the answer, deletes what has expired in the
  cache table - the limits' counters, the showcase answers, the claims - and the downloaded files in
  `storage/http-cache` older than three days;
- a stale catalog list (`CatalogUpkeep`, only while the site is ready; see "Main flows").

`config/app.php` `upkeep` switches each off (`people`, `daily_backup`, `prune`, `catalog`); the tests switch
them off, but where they mean to try one. None ever fails the answer: a failure is a line in the log. The
editors' own work - an import of builds, a refresh of one catalog list - is one request each, bounded (a
refresh is one list; an import is written in seconds, under its own time limit).

### The site's words

The member pages read in English or Slovak (more languages can be added in the browser). The words are rows
of the database ([data-model.md](data-model.md), "The site's words"), because the host has no shell and an
upload overwrites the build: a string is a key the code names and a value per language an editor may reword.

Since version 0.4.0 the code around them is the anotoki family's own, in its shared library (anotoki-lib's
translations module, since its 0.3.0 - [decisions.md](decisions.md), 2026-10-11): the bundle the pages read, the admin
pages' grid and languages, the checks of a string, the command line, the frontend's `TranslationService`
and the admin Translations and Languages pages. One wire format for every anotoki site: the IAM's, snake_case
(this site's already). What stays this site's own: its keys and their English and Slovak (the migrations, and
the compiled English in `shared/i18n/en/*.json`), its released languages (`SiteTranslations::RELEASED`), its last
check (`names_another_site`), who may do what (its role checks on each route group), its error body
(`{message, code, …}`), its typed keys and pipes, its storage names and the wording tables. The library has
words of its own under `anotoki.` - its kit's, its shell's (the top bar, its menus, the theme switch, the
language switcher) and its status page's - which its migration set writes and its code asks for; this site's
006 moved its two copies of them (`language.button`, `language.notLoaded`) under the library's keys, and 010
the top bar's twelve (`topbar.menu`, `topbar.roleAdmin`, `theme.*`, `common.signOut` ... - when the family's
bar took the place of the site's own), each with every rewording.

- **Keys, not sentences.** A template words a key with the `translate` / `translatePlural` pipes; state holds
  keys, kinds and errors, and a template or a `computed()` words them, so a page follows a change of language
  without reloading. Keys put together at run time come only from the closed lists in
  `frontend/src/app/shared/i18n/dynamic-keys.ts`.
- **What the server says to a member is a kind with params**: recommendations (`BuildEvaluator::KINDS` →
  `recommendation-words.ts`), import errors and warnings (`ImportKinds` → `import-words.ts`), and a failed
  request's `code` (`error-text.ts`, which never shows the server's sentence to a member). The English beside
  each kind stays for the command line and for rows stored before kinds; such a row is shown in that English
  with `lang="en"`.
- **Game terms stay as the game writes them** in every language: stat, slot, talent, set, weapon and
  character names, "Lv.", "4pc", UID, grades, and the game's notation ("62.4%", "R1"). They are never keys;
  they reach a sentence as a `{placeholder}` or stand before a colon, so no Slovak word agrees with one
  (`sites/main/common/game-terms.ts`, `sites/main/common/build-terms.ts`, and what `/api/meta` sends).
- **The fallbacks**: the server merges English under every bundle; a key the database lacks shows as the key,
  on purpose. The compiled English (`shared/i18n/en.ts` and its parts `en/*.json`) is the list of keys (an
  unknown key is a compile error: `TranslationKey`, through this site's typed view of the library's service
  and its typed `translate` / `translatePlural` pipes) and the last resort (the library's `fallbackEnglish`):
  it is shown only while no bundle is in memory - before migration 003 is applied (every translation route
  answers 503 `translations_unavailable` then) or while a first visit waits for its words - and never fills a
  single missing string. A library key (`anotoki.*`) never shows as a key: the database's string, else the
  library's built-in words.
- **The language**: `?lang=` (kept for the tab), the menu's remembered choice, the IAM token's `locale`, the
  browser's languages (Czech readers get Slovak while no Czech is offered), else English (the library's
  `TranslationService`, set up for this site in `shared/i18n/translations.config.ts`, with the storage names this
  site always had). The last bundle is kept in the browser, so a returning reader starts in their language;
  a first visit whose answer is lost asks once more after half a second. `<html lang>` and the page titles
  (`title-strategy.ts`) follow it; dates and numbers use `Intl` (the family's dates, `@anotoki/lib/ui/dates`:
  `anotokiAgo`, `anotokiDate`, in the translations' locale - `provideKit()`).
- **The account's language, theme and dates** (the IAM's migration 015, read through the library's auth module; the owner's decision of
  2026-10-06 - "it should be in the iam only and apply for every project/application"): while somebody is
  signed in at an IAM that keeps them (`AuthService.preferences()` not null), the account's `locale` comes
  before the menu's remembered choice - only `?lang=` and a choice of this visit come first - and is followed
  when it changes (on another anotoki site, in another tab); the menu's choice is saved to the account
  (`savePreferences({ language })`, one save at a time, the latest last; a language the IAM does not offer -
  `invalid_request` - stays this site's own for the visit, silently; any other refusal keeps it for the visit
  and the bar's language switcher says so under itself, `anotoki.language.notSaved`). The theme is the
  account's on every page, the admin panel included
  (`shared/shell.ts`, below). Dates follow the account's date order and clock where it has chosen them (the
  family's dates, given the account's choices by `provideKit()`'s `dateChoices`): What's new's days (`anotokiDate:
  'day'`), and in the admin panel when a migration was applied (`appliedAt()`) and, on the Builds page, when a builds
  file was made (`momentText()`: `formatDateTime()`); relative times ("3 hours ago", `anotokiAgo` with `{ dateAfter:
  null }`) have neither and read the same for everybody. A visitor, and anybody signed in at an IAM before 015 (no
  `preferences`), keeps this device's choices exactly as before, and nothing is saved anywhere else.
- **English pages**: the admin panel - `/admin/...` (Builds, Translations, Languages, Site settings, People,
  Feedback, Error log, Audit log, Backups, Migrations) - keeps its English code and reads English with `<html lang="en">` whatever language
  is chosen; the server's admin-only sentences stay English and those pages show them as they come
  (`errorMessage()`).
- **The Translations and Languages pages** (editors; adding, renaming, switching and deleting a language:
  admins) are the anotoki family's (`@anotoki/lib/translations/admin`, a lazy entry point of their own), drawn
  with the library's kit in the admin panel's frame (`sites/admin/translations`, `sites/admin/languages`), so
  every anotoki site's read alike. Translations is a grid of every key with its description and a box per
  language - coverage per language, a search, "Missing in X", plural families as one block - saved together
  (`PUT /api/admin/translations`, only the changed boxes, checked whole and written in one transaction), plus
  export and import of one language as a `{key: text}` file. Languages adds, renames, orders, offers or hides
  and deletes languages (English never, Slovak - released with the site - never; a delete offers the
  language's strings as a file first); a new language starts hidden, so its strings can be written before
  readers are offered it. What neither page has saved is kept for the tab (the library's `LocalizationDrafts`,
  in `sessionStorage` under `build-analyzer-localization-drafts`, per person; `shared/localization-drafts.ts`
  reaches them from outside the pages without loading them: the bar's "Sign out without your latest
  changes?", and a page load's drafts armed before either page is opened), leaving inside the app asks first
  (the library's `anotokiUnsavedChangesGuard`, asking the page), and closing the tab asks the browser's question.
- **The tests hold the pieces together** (`tests/translation_catalogue.test.php`): the compiled English is,
  key for key, the seeded English of the site's own keys; every key the frontend uses (the library's
  `KeyScanner`, configured in `SiteTranslations::keyScanner()`, which reads the sources and `dynamic-keys.ts` as
  text) is in the database and every key of the site's own is used; the server's kinds are the declared tails;
  every stored string passes the checks a save makes; the gates count the site's own keys. The library's
  generic behaviour is tested in the library.

### REST API (`api/routes/`)

| Method & path | Who | Purpose |
| --- | --- | --- |
| `GET /api/meta` | anyone (also while sign-in is required) | slots, stat labels, talent names, elements, weapon types and the game's labels per game (one, Genshin Impact); `version`; `auth {issuer, client_id, login_required, sign_in_available}` (whether the IAM requires sign-in here, and whether it knows the site); `visitors {keep_days}` |
| `GET /api/icons/{c\|w\|s}/{id}` | anyone (also while sign-in is required) | a character / weapon / set icon, served from `storage/icons/` |
| `GET /api/accounts`, `GET/PATCH/DELETE /api/accounts/{id}` | signed in or visitor (own) | list / overview (+ characters with the build each is judged by: the one chosen, else the best; and whether each is starred, `favourite`) / rename / delete |
| `GET /api/accounts/{id}/characters/{key}?build=ID` | signed in or visitor (own) | full analysis of one character (key = catalog key, e.g. `hutao`), its builds in full, `best_build_id`, `chosen_build_id`, and `owned_teammates` (`{key: constellation}`: the account's characters among the builds' teammates; `{}` for none; a Traveler of another element than the account's `null`) |
| `PUT /api/accounts/{id}/characters/{key}/build` (`{build_id}`) | signed in or visitor (own) | the build the account page grades the character by: one of the character's own builds, reached or not, or null for the closest match; answers `{chosen_build_id}`; another build, or no `build_id`, 422 `invalid`; a character the account lacks 404 |
| `PUT /api/accounts/{id}/characters/{key}/favourite` (`{favourite}`) | signed in or visitor (own) | the character starred - one the player is building, listed first on the account page - or not (1.0.0); answers `{favourite}`; anything but true or false, or none, 422 `invalid`; a character the account lacks 404 |
| `GET /api/accounts/{id}/imports` | signed in or visitor (own) | import history |
| `POST /api/imports/showcase` `{game, uid, account_id?}` | signed in or visitor (own); nobody: makes a visitor (JSON only: else 415) | Character Showcase import (10 a minute per person; a visitor's per address, all visitors' 20 together) |
| `POST /api/imports/file` `{content \| file, game?, account_id?, file_name?}` | signed in or visitor (own); nobody: makes a visitor (JSON only: else 415) | GOOD import - the game is the file's, a `game` sent along is not read (20 a minute per person; a visitor's per address); a person or a visitor may upload `file` as multipart |
| `POST /api/visitor/move` (`X-Visitor-Key`) `{silent?}` | signed in, with the key | a visitor's accounts and imports become the person's: `{found, moved, kept, imports, held}` - after a silent sign-in, never into a person with data here (`held: true`, nothing moved) |
| `GET /api/me/export` | signed in (never a visitor) | the person's game accounts as a file to save (`build-analyzer-accounts-{date}.json`, compact JSON; data-model.md "Accounts files") |
| `POST /api/me/import[?dry_run=1]` (the file as the body) | signed in (never a visitor) | `{dry_run, added, replaced, kept, skipped, accounts: [{game, uid, nickname, outcome, skipped}]}`; a dry run writes nothing (5 a minute per person, dry runs included) |
| `GET /api/builds` | anyone (signed in only while sign-in is required) | per game `{game, characters, builds, checked_at, changed_at}`: how many characters have builds, how many builds there are, when the newest file carrying them was made and when one last changed - naming nobody (the dashboard) |
| `GET /api/builds/{game}` | likewise | the builds browser: each character with builds, the newest release first (an unknown date before them; then by name) - `{key, name, element, rarity, weapon_type, icon_url, released_at, region, version, version_name, builds, roles, changed_at}`, `roles` in the game's order (what the page filters by: name, element, weapon type, rarity, region, version) |
| `GET /api/builds/{game}/{key}` | likewise | `{character: {key, name, element, rarity, weapon_type, icon_url}, builds}`: a character's builds in their order, the first its main one (each `{id, role, qualifier, min_rank, position, weapons, sets, main_stats, substats, talent_priority, stat_targets, teams, notes, game_version, checked_at, changed_at}` - `notes` the site's own note on the build, `{en, sk?}` or null (no weapon's or set option's note, no target's text: the file's, which the site no longer shows) -, a team `{name, members, note}` (its `note` the site's own likewise), a member `{key, name, known, icon_url, rarity, element, has_builds, role, min_rank, own, alternatives}`, a stand-in `{key, name, known, icon_url, rarity, element, has_builds}`; 404 for a character the catalog lacks) |
| `GET /api/admin/builds` | editor | the Builds page, per game: `{game, characters, builds, checked_at, changed_at, imports: [{id, created_at, file_exported_at, summary, imported_by: {id, name}\|null}], catalog: [{list, entries, refreshed_at, problem}], editing: [{key, name, icon_url, builds, edited, made_here, removed, notes, ordered}]}` - the last 10 imports, newest first, with who made each (a deleted person's: nobody); the catalog's three lists, when the provider last confirmed each and its last problem; the characters with builds (or removed ones), with what the editors did to them |
| `GET /api/admin/builds/{game}/catalog` | editor | what the builds editor picks from: `{game, facts: {roles, max_rank, rank_prefix, refinement_prefix, slots: {slot: [stat]}, substats, stats, talents, set_kinds: {kind: [counts]}, team_size, labels: {stats, talents, slots, set_kinds}, limits}, characters: [{key, name, element, weapon_type, rarity, icon_url}], weapons: [{key, name, weapon_type, rarity, icon_url}], sets: [{key, name, kind, icon_url}]}` (English names: the admin panel's) |
| `GET /api/admin/builds/{game}/characters/{key}` | editor | a character's builds as the editor edits them: `{game, character, version, order: file\|editors, builds: [{id, origin: file\|made_here, build, file, file_position, edited, notes: {build, teams, waiting: [{key, members, note}]}, checked_at, changed_at, edited_at}], removed: [{id, build, file_position, notes, removed_at}]}`; 404 for a character the catalog lacks |
| `PUT /api/admin/builds/{game}/characters/{key}` (`{version, builds: [{id, removal, build, notes: {build, teams, waiting: [{key, note}]}}], order?: "file"}`, at most 1 MB) | editor | the character's builds saved as sent (builds.md, "The editor"), answered as the GET; 422 `invalid_body`, `invalid_build`, `invalid_note` or `names_another_site` with `build` (its index) and `path` ("weapons[0].refinement", "notes.teams[1].sk"), nothing written; 409 `busy` (an import or another save writing), 409 `changed` (the builds changed since the version loaded); 413 / 415 / 400 for a body too large, not JSON |
| `GET /api/admin/builds/{game}/export` | editor | the game's builds as a file to save (`builds-{game}-{date}.json`, format version 3: data-model.md "Builds files") |
| `POST /api/admin/builds/{game}/import[?dry_run=1]` (the file's JSON as the body, at most 8 MB) | editor | the game's builds become the file's (see "Main flows"): `{dry_run, game, exported_at, older_than_current, characters, builds, teams, created, updated, unchanged, removed, skipped: [{key, name, builds}], teammates_unknown: [{key, name}], teammates_unknown_total, edits_kept, made_here, kept_removed, file_taken, questions: [{id, kind, character, role, qualifier, min_rank, parts, taken, notes}], plan, merged, order_kept, notes_dormant, changes: [{change: created\|updated\|removed, character: {key, name}, role, qualifier, min_rank}], changes_listed}` - every change counted, the first 500 listed; `older_than_current` when the file was made before the one the builds there came from; the questions where the file and an editor's work disagree (builds.md, "Hand edits"), answered by `?file=<ids>\|all&plan=<hash>`; a dry run writes nothing. 422 for a file that does not fit or choices that name no question (nothing written), 409 while another import writes, 409 `plan_changed` when the questions are not the preview's any more |
| `POST /api/admin/catalog/{game}/{list}[?force=1]` (`characters`, `weapons`, `sets`) | editor | one list of the catalog refreshed from the game's provider, a conditional request unless forced: `{outcome: changed\|unchanged, written, list, entries, refreshed_at, problem}`; 409 while another refresh of the list runs, 502 when the provider is closed, has no such list or cannot be read (the reason as the message); 404 for another list |
| `GET /api/site-status` | anyone, always (the gate lets it through) | `{state: ready\|not_set_up\|update_pending\|unavailable\|maintenance, installed, maintenance_message?}` - `maintenance` (with the administrators' message per language) while the site settings close the site; nothing else (anotoki-lib's `MigrationsRoutes::status`) |
| `GET /api/site-settings` | anyone, always (no token read; a reserved path) | what every page is told of the site settings: `{maintenance, announcement: null\|{level, message: {en, sk, …}}, pages: [{path, visibility, blocked}]}` - never a row's API paths (anotoki-lib's `SiteSettingsRoutes::public`) |
| `GET/PUT /api/admin/site-settings`, `POST/PUT /api/admin/site-settings/pages`, `DELETE …/pages/{id}` | admin | the Site settings page: the page's whole (`parts`, each switch with when and by whom it last changed, the pages with their API paths, what is reserved, the limits) / the switches that changed (`{settings: {name: value}}`) / a page's row added (201), rows changed (`{pages: [{id, visibility?, blocked?, api_paths?}]}`), a row removed - each audited (`site_settings.changed`, `page_access.added`, `.changed`, `.removed`); 422 `invalid_setting`, `invalid_page`, `invalid_api_path` (a reserved one, either way round), 409 `page_exists` (anotoki-lib's `SiteSettingsRoutes::admin`) |
| `GET\|HEAD /robots.txt`, `GET\|HEAD /sitemap.xml` | anyone (behind the site gate) | what robots read: out of `/admin`, `/api`, `/auth`, `/accounts`, `/sign-in`, `/staff`, the sitemap named by the site's own address; the legal pages, About, Support - and while the IAM lets visitors in the dashboard, the import, the builds and each character's builds with its `lastmod` - never what Page access hides; `Cache-Control: public, max-age=3600` - the library's 404 `not_configured` never comes here: a checkout and a release each have an address (anotoki-lib's `RobotsRoutes`) |
| `GET /api/admin/migrations` | admin (from the token alone; open while an update waits) | `{sets: ["build_analyzer"], applied: [{set, name, applied_at}], pending: [{set, name, ready, blocked, duplicates}], missing: [{set, name, applied_at}]}` - the files applied, in order, with when (UTC, `…Z`); the files not applied yet, in the order they will run (`duplicates`: the other files of its set with its number - one waits until none is left, anotoki-lib 0.11.0); files recorded but not on disk (anotoki-lib's `MigrationsRoutes::admin`) |
| `GET /api/admin/migrations/file?set=&name=` | admin (likewise) | `{set, name, sql}` of one of the set's files; 404 `not_found` for anything else (no path tricks) |
| `POST /api/admin/migrations/apply` | admin (likewise) | applies every pending file in order, each in its own transaction with its record, stopping at the first that fails: `{applied: [{set, name}], failed: {set, name}\|null, error: string\|null, note: null}` (200 either way; the failed file rolled back whole; `error` is `statement N of M: <the database's message>` and the statement's first lines); 409 `busy` while another apply holds the advisory lock every apply takes (the page, `migration.php`, setup). No shell on the host; the same engine as `migration.php` and setup. Given five minutes and not cut short when the browser goes; the log says what was applied and where it stopped |
| `GET /api/translations/{code}` | anyone (no token read) | the bundle: `{language, languages (the offered ones), values, english?}`, the language's strings with English underneath (the library's words among them); ETag and 304 |
| `GET /api/admin/languages` | editor | the languages, hidden ones too: `{code, name, native_name, enabled, sort_order, seeded, strings}` |
| `POST /api/admin/languages`, `PUT/DELETE /api/admin/languages/{code}` | admin | add one / change name, native name, offered, order / delete one and its strings (never `en` or `sk`): `{strings}` |
| `GET/PUT /api/admin/translations` `{values: {key: {code: text}}}` | editor | the grid (every key, its description, its strings) / save the changed strings, all or none |
| `GET /api/admin/translations/{code}/export`, `PUT …/{code}/import` `{key: text}` | editor | one language's own strings as a file / back in, with a save's checks |
| `GET /api/admin/people?q=&page=` | admin | `{people: [{sub, name, first_seen_at, last_seen_at, anonymized_at, iam_url, accounts, imports, last_import}], columns, total, page, page_size, visitors: {count, accounts}, sync, ready, problems}` - the IAM people who used the site, 50 a page, the most recently seen first; visitors counted apart, never listed |
| `DELETE /api/admin/people/{sub}` | admin | delete a person's data (accounts with everything in them, import runs): `{deleted: {"Game accounts": n, "Imports": n}}`; the strings they saved and the record of the builds files they imported stay, by nobody; 404 `not_found` for no such person |
| `POST /api/admin/people/sync` | admin | the scrub of the IAM's deleted accounts, now |

The translation routes are anotoki-lib's (`TranslationsRoutes`), and so are the People page's (`PeopleRoutes`),
in the family's one wire format, behind this site's role checks and in its error body.

Errors are JSON `{message}` and, where a page words them, a `code` (validation adds `errors: {field:
[message]}`): 401 `Sign in to continue.` `sign_in_required` (no token, or one that does not verify; while the IAM requires sign-in, also a visitor key), 401
`visitor_unknown` (a visitor key nobody has, or none at all), 403
`editor_only` "Only an editor or an admin can do this." / `admin_only` "Only an admin can do this.", 404
`Not found.` (also for other users' accounts and unknown paths), 409 (another import of builds is being written;
another refresh of the same catalog list runs; `language_exists`, `fallback_language`, `seeded_language`), 422
(import problems `{code, params}`, a builds file that does not fit, validation, a string or language the checks
refuse - with the key and the language; the translation routes' `empty_body` for a write that arrived empty; a
visitor's limits,
`visitor_limit` `{limit: accounts | file_size, max}`; an accounts file not read, `{code, params}`: `no_file`,
`file_too_big` `{max_mb}`, `not_a_data_file`, `newer_version` `{version}`, `bad_entry` `{path}`, `too_many_accounts`
`{max}`, and an export too large for one file, `export_too_big` `{max_mb, max_accounts}`), the translation routes' 400
`invalid_json`, 413 `too_large` (over 1 MiB) and 415 `unsupported_media_type` (a write that is not
`application/json`, such as a form), 423 `page_locked` "That part of the site is not available." (an API path the
site settings' Page access refuses with its page, to whoever the page is not shown to), 429 (`too_many_imports`;
`too_many_visitors`, which says to sign in), 502
(the Builds page's refresh of a catalog list: the game data provider closed, without the list or unreadable - its
reason as the message), 503 `not_set_up`, `update_pending` and `maintenance` (the site gate's: `{code, message}`,
`not_set_up` with `installed` and - only while never installed - `setup`, `maintenance` with `maintenance_message`
and `Retry-After: 300`; see "The site gate"), 503
`translations_unavailable` (with `Retry-After: 60`) from the translation routes while the tables of migration
003 are not there (and from the bundle while English has no string), 503 `visitors_unavailable` from the visitor
paths while 004's columns are not (both behind the gate: a second line), and 503 `visitor_move_retry` (ask
again). The Migrations page's API answers its own: 404 `not_found` (a file that is none of the set's), 409
`busy`.

## Frontend (`frontend/`, Angular 21 on the anotoki family's kit)

- The folders are the anotoki family's, the genshin site's (the owner's decision of 2026-10-24; decisions.md):
  under `src/app/`, `api/` is the API layer, `shared/` what both halves use, `sites/main/` everybody's pages (with
  their frame, `app-layout/`, the legal pages' `legal/` and what only they share, `common/`) and `sites/admin/` the
  admin panel (its frame, `app.ts`, and a folder per page). Each half keeps its routes - `main.routes.ts`,
  `admin.routes.ts` - and `app.routes.ts` spreads them into one list, `/admin` first; neither is a `loadChildren`
  of its own, so the first load holds what it always held. The top bar is everybody's pages' alone
  (`sites/main/common/top-bar/`): the admin panel has the family's admin frame, with no bar.
- `api/api.ts` typed client (`API_BASE = '/api'`, same origin) + `errorMessage()`; `api/models.ts` mirrors the
  API responses (keep in step with the presenters); `shared/meta-store.ts` loads `/api/meta` at startup (labels,
  formatting, sign-in settings); `shared/site-status.ts` wires the family's site gate and Migrations page
  (`@anotoki/lib/migrations`; the page itself from `@anotoki/lib/migrations/page`) to this site - see "The site
  gate" above; `shared/format.ts` the games (`GAMES`, `GAME_LABELS`: Genshin Impact alone, `GameKey` `'genshin'`
  in `api/models.ts`), colors and time helpers; `shared/changelog.ts` loads `public/changelog.json` (the version shown in the footer, and
  "What's new" once per release per browser); `shared/shell.ts` (`provideShell()`: the family's shell,
  `@anotoki/lib/shell`, set up for this site - its `ThemeService`: light, dark or automatic (the device's),
  drawn on `<html data-theme>` and kept in localStorage `build-analyzer-theme` (nothing for automatic), where
  the kit's `theme-boot.js` - `index.html`, copied by `angular.json`'s assets - reads it before the first paint;
  signed in at an IAM that keeps the preferences, the account's - shown from the first frame, followed when it
  changes, and the bar's switch saves to it: shown at once, one save at a time with the latest choice last,
  never flicking back while its own save is on its way, and a refusal puts the account's theme back with a note
  under the switch, `anotoki.theme.notSaved`; and the bar's languages, `TranslationService.forShell()`);
  `shared/kit.ts` (`provideKit()`: the kit's words in the language on the page and its dates' locale - `forKit()` -,
  the account's date order and clock for the kit's dates - `dateChoices`, asked when a date is written: the sign-in
  reads the kit's words, a circle otherwise -, and the icons the site names beyond the kit's own, `shared/icons.ts`).
  The dates are the family's (`@anotoki/lib/ui/dates`, anotoki-lib 0.11.0): `anotokiAgo` and `anotokiDate` in the
  templates, `formatDateTime()` and `formatRelative()` in code; the search is the family's too (`@anotoki/lib/ui/search`:
  `filterBySearch()`, `matchesSearch()` - accents and case aside, each word anywhere).
- The site settings (anotoki-lib 0.11.0, `shared/site-settings.ts`: `provideSiteSettings()` - the token's roles and
  whether anybody is signed in, the announcement's mark under `build-analyzer-announcement`): read once at the start
  beside the site's status (`SiteSettings.load()` in `app.config.ts`, before the router's first navigation; with no
  credentials - `api.skip`), again after an administrator saves them. Everybody's pages' frame draws the announcement
  above the bar and the administrators' notice above the page (`<anotoki-announcement>`, `<anotoki-page-notice>` -
  since anotoki-lib 0.11.1 each fetches what it draws, `@anotoki/lib/site-settings/bar` and `/notice`, only when there
  is something to draw: neither is in the first load); the admin frame the notice alone (`adminFrameNotice`: the
  maintenance line). Page access is `pageAccessGuard` (a
  `canMatch`) on the six public pages (`sites/main/main.routes.ts`): a page closed to the reader falls through to the
  `**` and the dashboard - and where the dashboard is closed too, the last `**` draws "This page is not open right
  now" (`sites/main/not-open`); the bar leaves such a section out (`TopBar.links`). While the site is closed the gate
  draws the family's closed sign for everybody but the site's ADMIN (`shared/site-status.ts`: `staffRoute: '/staff'`,
  `openWhileClosed` the library's and `/sign-in`), its Sign in at `/staff` alone - a route of its own
  (`staffRouteGuard`: kept while the site is closed to the reader, the dashboard otherwise), back to `/`.
- Sign-in, through the anotoki IAM only - the anotoki family's auth module (`@anotoki/lib/auth`, set up in
  `app.config.ts`: `provideAnotokiAuth()` - its boot configuration is `/api/meta`'s `auth`, read once with the
  game metadata by `MetaStore`; this site's visitor key store; the language the IAM's pages open in; the
  failure route `/sign-in`; an editor sent from an ADMIN page to `/admin/builds`). `AnotokiAuth` (the IAM's
  client, the tokens in localStorage under `anotoki-auth:build-analyzer` as the IAM's SDK kept them, the
  callback, following the IAM, and the sign-in hint's one silent hop for a visitor signed in at the IAM) is seen
  by the pages through `shared/auth.ts` (`AuthService`, a facade under the names they always used:
  `available`, `loginRequired`, `user`, `signedIn`, `roles`, `isStaff` (EDITOR or ADMIN), `isAdmin`,
  `displayName`, `preferences` - the account's theme, date order and clock, null for a visitor or an IAM before
  its migration 015 -, `failure`, `accountUrl`; `login()`, `logout()`, `savePreferences()`). `shared/sign-in.ts` (`signInOrSayWhy()`: every Sign in button -
  and the failure's screen when the sign-in cannot start). The guards are the family's: `sitePagesGuard`
  (`shared/auth.guards.ts`) on every child of the AppLayout is `personOrVisitorGuard` - a visitor passes while
  the IAM lets visitors in; while it requires a sign-in somebody not signed in goes to the IAM and back - plus
  this site's one rule: a sign-in this page load came back without (or that could not start) is said once on
  `/sign-in`, to a visitor too; `personGuard` on `/admin` (anybody not signed in, a visitor too, goes to the
  IAM and back); `adminGuard` on Languages, Site settings, People, Migrations and the admin tools (an editor goes to
  `/admin/builds`). `/sign-in` is the library's `<anotoki-sign-in-failure>` in the site's frame (why, from the
  failure's code alone; Try again, Back; `noindex`), `/auth/callback` its `<anotoki-auth-callback>`;
  robots.txt - the library's, from the site's PHP since anotoki-lib 0.11.0 - keeps search engines off `/auth`. A sign-in leaves only once the writes on their
  way have answers (the library's `SavesInFlight`, 30 s at most): a visitor's first import brings the key the
  move needs.
- Who a request is, in the browser - decided when it is ISSUED, never from whether a token happens to be there
  when it leaves: `Api` marks each request with the family's `ISSUED_AS` - `caller` (whoever the page is shown
  to: the person signed in, else the visitor), always `person` for the admin panel (the Builds page's imports,
  exports and catalog refreshes among them), the own-data file and the move. The family's
  `anotokiAuthInterceptor`, on this site's `/api` only - never on `/api/meta`, `/api/translations/` or
  `/api/site-status` (`api.skip`: read with no credentials at all) - acts on that
  mark (a request `Api` did not mark - the library's own pages' - is decided as it is handed over): a person's
  request gets the bearer (one retry with a fresh token after a 401, for the same person) and never the visitor
  key, and with no token it is **never sent** - it fails there as a 401 `sign_in_required`, so a person's import
  never goes out as a visitor's and never makes one; a visitor's request, while the site does not require a
  sign-in, gets the key `VisitorKey.current()` reads at that moment, and the `X-Visitor-Key` of the answer that
  made a visitor is kept (`remember()`: the first key stays); a 401 `visitor_unknown` forgets the key that was
  sent (only while it is the one held) and tries once more; a 401 `sign_in_required` says the site requires a
  sign-in now (the key stays). The move (`Api.moveVisitor()`, a person's, marked `CARRIES_VISITOR_KEY`) is the
  one request with the token and the key. Every write is counted while it is on its way (`SavesInFlight`).
- `shared/visitor-key.ts` (`VisitorKey`, root): the key in localStorage `build-analyzer-visitor` through the
  never-throwing helpers of `shared/storage.ts` (in memory when storage refuses), kept in step across tabs by the
  `storage` event and read again at send time; `key`, `active` (not signed in, sign-in not required, a key
  held), `lost` (forgotten on `visitor_unknown`: the dashboard says so once), `moves` (a counter the pages that list accounts reload on),
  `moved` (what the last move did, for the shell's toast - `visitor.moved` / `visitor.kept`). Its effect on
  `auth.signedIn()`: signed in with a key held, `POST /api/visitor/move` once at a time, `{silent}` when the
  present sign-in was the hint's silent hop; a 200 (found or not) forgets the key - but `held` (a silent sign-in
  into an account with data here) keeps it, saying nothing -, a 422 too; a network error or a 5xx
  (`visitor_move_retry`, `visitors_unavailable`) keeps it
  and asks again after the answer's Retry-After (else 2, 10, 30 s - `MOVE_RETRY_MS`), then at the next page
  load; a 401 keeps it for the next sign-in. The app initializer injects it right after the start, so the
  effect exists before the sign-in settles.
- `shared/sign-in-kind.ts` (`SignInKind`, root): how the present sign-in began, `silent` or `clicked`, noted from
  the auth module's client's own init() (its callback's `silent`) - the client factory provided as
  `ANOTOKI_AUTH_CLIENT_FACTORY` in `app.config.ts` wraps the library's `createAuthClient` - and kept in
  localStorage `build-analyzer-sign-in` for the page loads that restore the session; none noted: clicked.
- `sites/main/common/query-filters.ts` (`bindQueryFilters()`, called from a list page's constructor): a list page's filters
  kept in the address, both ways - the builds browser's, as genshin.anotoki.eu keeps its lists'. Each filter is a
  signal and the value it holds while nothing is chosen (`''` for a text field or a select's "Any", `'all'` for a
  segmented control's "All"), which leaves its parameter out of the address; the address seeds the signals before
  the first render and wins whenever it changes (a link opened, Back), and the signals write it with
  `replaceUrl` and `queryParamsHandling: 'merge'` - a filter is not somewhere one went, so leaving the page is one
  Back, and another parameter of the address is not the filters' to drop. A filtered list is a place: coming back
  from a character finds it as it was left, and its address is worth sending to somebody; nothing is remembered
  in the browser.
- `shared/i18n/` (see "The site's words" above): `translation.ts` (`TranslationService`: anotoki-lib's service -
  the language, the bundle, `init()` from the app initializer, the English pages - under its own token, typed
  with this site's keys: `WordKey` = `TranslationKey` | the library's `LibraryKey`), `translations.config.ts`
  (`provideTranslations()`: the library set up for this site - its storage names `LANGUAGE_STORAGE`, the
  compiled English as the fallback, the account, `canAsk` = `/api/meta` answered, the admin pages' rights and
  routes), `en.ts` with the compiled English parts `en/{core,shell,builds,import,legal}.json` (one flat
  `{"key": "English"}` object each, a key in exactly one part), the typed pipes `translate` and `translatePlural`
  (dates are the family's pipes, `anotokiAgo` and `anotokiDate`), `dynamic-keys.ts`, `error-text.ts` (a failed request by its `code`),
  `recommendation-words.ts`, `import-words.ts`, `variation-words.ts` (a build's variation as a tab, a heading or a
  line says it: its role in the reader's language - `builds.role.*`, "Build" for a role a newer server adds -,
  its qualifier as the build has it, the constellation it needs in the game's notation: "Main DPS · Melt · C4+";
  `rankMark()`, "C2+", for a stat target from a constellation too), `title-strategy.ts` (`AppTitleStrategy`: a
  member route's `title` is a key; it tells the service where the page is, `noteAddress()`). The specs take the library's
  stand-in, `@anotoki/lib/translations/testing`. The kit's words (`provideAnotokiUi(... forKit())` in
  `app.config.ts`) and the status page's (`siteStatusWords()`, `shared/site-status.ts`) are the database's too.
  The game's own words: `sites/main/common/game-terms.ts`, `sites/main/common/build-terms.ts`.
- The admin panel (`/admin/...`, English with `lang="en"`; `/admin` itself goes to Builds; the old `/sources`
  and `/admin/sources` - the Sources page's addresses, before the builds came in files - go to the Builds page,
  the old `/translations` and `/languages` to theirs): `sites/admin/app.ts` (`AdminApp`, `app-admin-root`: for
  staff the anotoki family's admin frame - `<anotoki-admin-frame>`, `@anotoki/lib/admin/frame`, anotoki-lib 0.10.0,
  the owner's request of 2026-10-24 that every admin panel look like the genshin site's - and the page; for
  somebody whose session ended with an admin page open "You are signed out", with a Sign in button; for somebody
  without a role "This part is for editors and admins" - those two a page of their own, the family's page frame
  with no bar and no column, the footer under it). The frame has no top bar: on a computer a column on the left -
  the panel's name as its bar said it, "Analyzer · admin panel" (`brand.short`, `brand.admin`; each side held
  together by no-break spaces, so on two lines it breaks after the dot), a link to Builds; who is signed in (the
  name and the address); "Back to Build Analyzer" (`topbar.backToApp`), to `/`; Sign out (the site's one,
  `shared/sign-out.ts` - the top bar's too); the theme (`<anotoki-theme-toggle adminFrameTheme />`: the shell's
  ThemeService, the account's theme); then the panel's pages, the ones its bar had - Builds; Localization:
  Translations, and Languages for the ADMIN; System, the ADMIN's, in the family's one shape (anotoki-lib 0.11.0): Site
  settings, People, Feedback (with the count of new messages, the family's `FeedbackUnread`, asked once the ADMIN is
  in the panel and the site runs for them), Error log, Audit log, Backups and Migrations - Migrations alone while an
  update waits (`UPDATE_GROUPS`) -,
  each with its icon (`shared/icons.ts`); and the page beside it, in the measure the panel's pages always had,
  the footer under it. On a phone (up to 860 px, the frame's) a row with the panel's name and Menu, everything of
  the column in the kit's drawer. The frame's own words - Skip to content, Menu, Sign out, Theme, Admin panel -
  are the kit's, English in the panel. The frontend only hides: the server refuses
  (403 `editor_only` / `admin_only`); and the admin panel's requests are a person's alone, also those the
  library's pages make themselves. `sites/admin/builds` and `sites/admin/build-editor` (a character's builds editor,
  `/admin/builds/:game/:key`: its route's `anotokiUnsavedChangesGuard` asks the page, and `shared/build-editor-changes.ts`
  tells the site's Sign out it holds changes - one question with the drafts below, in the panel's English) are the
  site's own (below, "The pages");
  `sites/admin/{translations,languages,migrations}` hold the anotoki family's pages in the panel's `.page` (each
  from its own lazy entry point); the People page and the admin tools are routed straight from theirs
  (`@anotoki/lib/people/page`, `@anotoki/lib/admin/errors`, `/audit`, `/feedback`, `/backups`, set up in
  `app.config.ts`: `provideAnotokiPeople()`, `provideAnotokiAdmin()`), the panel's frame given them by
  `styles.scss`. Translations and Languages answer the route's `anotokiUnsavedChangesGuard` by asking the
  library's page. `shared/localization-drafts.ts` reaches the library's `LocalizationDrafts` (what those pages
  have not saved, for the tab and the IAM person who typed it, and the one `beforeunload` question) only when
  the tab holds some; the sign-out's question about them is the kit's `ConfirmService`.
- Feedback (`shared/feedback.ts`: `provideFeedback()`, the family's form set up for this site - the language on the
  page, the four places `FEEDBACK_SECTIONS` with the server's names, the person's address written in): the
  family's footer opens it in the kit's dialog, named by its button, loaded with the first press - as the Contact
  page does. Both only while a message can be sent (`feedbackOffered()`): the form says its message is anybody's
  (`issuedAs('anybody')`, anotoki-lib 0.7.2) - the person's with their token while somebody is signed in, the
  visitor's while the page is shown to one (`AuthService.visiting`), nobody's otherwise - and the server takes one
  from anybody only while the IAM lets visitors in, from a person signed in only while it requires a sign-in; so
  never to somebody signed out while it requires a sign-in (the server would refuse it, 401 `sign_in_required`:
  "Sign in to send a message"), nor while the site is not ready (503).
- The errors the pages meet (`app.config.ts`: `provideBrowserGlobalErrorListeners()` and the family's
  `provideAnotokiErrorReport()`, `@anotoki/lib/error-report`): the app's `ErrorHandler` tells the console, as
  Angular's own does, and sends each error once - scrubbed in the page, at most five a page load, never an HTTP
  failure - to `POST /api/client-errors`, through the app's `HttpClient`: the auth interceptor adds the person's
  token (the server counts a person by their account), the site's status interceptor hears an update waiting.
- The frame: `app.ts` is the router inside the site gate (`<anotoki-site-gate>`, the outlet its template: the
  gate's own page while the site is not ready, and no page made behind it - see "The site gate") plus what
  belongs to no page - the theme on `<html>`, What's new to somebody who has used the site before (signed in,
  or holding a visitor key) while the site is there to use (its dialog is the shell's, outside the gate, so
  `app.ts` itself holds it back while `SiteStatus.blocked()`), the move's toast, and the kit's one question
  host and toast host (`<anotoki-confirm-host>`, `<anotoki-toast-host>` in `app.html`). Two frames draw the
  rest, both the family's: `sites/main/app-layout/` (every page outside `/admin`: `<anotoki-shell>` - the bar
  `<app-top-bar>`, the page, the footer) and `sites/admin/app.ts` (the admin panel, `lang="en"`:
  `<anotoki-admin-frame>` - its column, the page, the footer; above). The footer is the family's (`<anotoki-site-footer>`,
  `@anotoki/lib/footer` - since anotoki-lib 0.6.3 the library's copy of this site's own): "v0.4.0 · What's new ·
  Feedback" (a screen reader hears "Version 0.4.0", the library's `anotoki.footer.version`) - the version the newest
  entry of `public/changelog.json` (`shared/changelog.ts`), What's new this
  site's own dialog (`(whatsNew)`), Feedback while a message can be sent - then About · Support · Terms · Privacy ·
  Contact and the fan clause (`fan="hoyoverse"`), in the library's words (`anotoki.footer.*`). What's new opens by
  itself with the releases after the one this browser saw last (`build-analyzer-version-seen`), compared by their numbers
  (`compareVersions()`: MAJOR.MINOR.PATCH, a missing part 0 - so "0.3", kept from before the versions had
  three numbers, is 0.3.0).
- The legal pages (anotoki-lib 0.6's `@anotoki/lib/legal` and `/legal/pages`; the owner's decisions of
  2026-10-13): Terms, Privacy and Contact on `/terms`, `/privacy`, `/contact`, each a lazy route of its own whose
  parent is `sites/main/legal/legal-layout/` - everybody's pages' frame (`<app-app-layout>`, whose router outlet draws the
  page) giving the pages this site's facts, so the facts and their code load with the pages, out of the first
  load. No guard: anybody may read them, also while the IAM requires signing in here. `shared/legal.ts`
  (`provideLegal()`): the page's language, the database's words - the library's, the fan clause too, which names
  Genshin Impact, the one game here since 0.5.0 (the site's own `legal.fanClause`, which named both games, went
  with migration 015). `sites/main/legal/legal-facts.ts` (`siteLegalFacts()`): what
  the site keeps, what for and how long, its cookies and stored items by name, who else handles data (the hosting
  company, Cloudflare, the public showcase service a UID import asks - described, never named), the anotoki
  account's pages at the IAM's address `/api/meta` names - each fact with the code it rests on in its comment;
  the words are the site's `legal.*` (migration 011), every address the code's, never a string.
- The About and Support pages (anotoki-lib 0.8.0's `@anotoki/lib/legal/about` and `/legal/support`, the same on
  every anotoki site): on `/about` and `/support`, routed as the legal pages are, in the same frame, with no guard;
  their words are the library's (`anotoki.about.*`, `anotoki.support.*`, its `anotoki_translations/013`), their
  tabs' titles its keys `anotoki.about.title` and `anotoki.support.title`, every address they link the library's
  code.
- The top bar (`sites/main/common/top-bar/`) is the anotoki family's own, `<anotoki-top-bar>` from
  `@anotoki/lib/shell` (since 0.4.0; before, a copy of it built from Angular Material's pieces), on everybody's
  pages alone - since anotoki-lib 0.10.0 the admin panel has the family's admin frame instead (above) -, told what
  is this site's: the brand (the anotoki mark, "anotoki" and `brand.area`; on a phone the family's short form,
  `brand.short`; the app's whole name in the link's accessible name, `topbar.home`), the sections (`APP_LINKS`:
  Dashboard with `/accounts/` under it, Import, Builds with `/builds/` under it - the same for nobody, a visitor and
  a person), who is signed in (name, address, the role "Administrator" / "Editor") with their menu items ("Your
  anotoki account"; "Admin panel" for staff), Sign in (`signInOrSayWhy()`) and Sign out (the site's one,
  `shared/sign-out.ts`, which first asks, the kit's `ConfirmService`, about strings typed on the Translations and
  Languages pages and not saved). The library draws the row in the family's order and owns its pieces and their
  rules: the language switcher (only with more than one language; signed in, a choice is the account's too - its
  languages and choice `TranslationService.forShell()`), the theme switch (light, dark, automatic; signed in,
  the account's - the admin frame's switch is the same one), its menus (focus into the menu on open, arrows / Home
  / End, Escape and an outside click close it, Tab and Shift+Tab close it with the focus on its button - never on
  `<body>`) and the fold. Up to `PHONE_BAR_MAX` = 880 px the sections and the person's menu (or Sign in) fold into
  one menu button; the person's name shows from 1081 px and gives way first. The width is this site's, measured in
  the family's bar (`verify-ui`'s `run.mjs --measure`): the row needs 793 px in English and 803 in Slovak signed
  out - 880 leaves room for a scrollbar and for a language added later whose words run longer. Since 0.5.0 the bar
  carries no widget of the site's (the sync a tab drove went with the syncs). Every member word is a key - the
  site's (`brand.*`, `topbar.*`) and the library's (`anotoki.topbar.*`, `anotoki.theme.*`, `anotoki.language.*`,
  which 006 and 010 gave the site's own wording).
- `sites/main/common/`, what everybody's pages share: `build-detail` (renders a build, optionally annotated with an
  evaluation: its weapons and sets in order of rank, main stats, substats - "not listed for this build" with what
  the analysis goes by when it lists none -, talents, stat targets, a target from a constellation marked "C2+", its
  teams, its notes in the kit's disclosure, when the file that last brought it was made; a set option's pictures two
  at most, in a column of one width, none for a piece any of several sets fills), `build-teams` (a build's teams -
  each under its name, members side by side on a phone too, 64 px portraits, names broken between their words, their
  roles, a constellation a team asks, the stand-ins' line built from `buildDetail.teamAlternatives` with every text
  in an element of its own; a teammate with builds a link to them; each team's note titled with its name,
  `buildDetail.teamNotes`; given the account's `owned_teammates`, a check on the members and stand-ins it has -
  heard "Owned" -, a member also "needs C2", linked to its own analysis where the account has it as it is),
  `item-card`, `grade-badge`, `score-bar`, `visitor-notice/` (for somebody not signed in: kept for this browser on
  the server for `visitors.keep_days` days after the last visit, signing in keeps it - with a Sign in button),
  `visitor-hero/` (nobody's ways in: import, each game's builds, sign in), `data-card/` ("Your data", signed in
  only: Export downloads `GET /api/me/export` under the server's name; Import refuses a file over 8 MB before
  sending, sends its text unchanged as a dry run - the preview: added, replaced by a newer copy, kept, entries left
  out, each account's outcome - and Confirm sends it for real; the file's problems are worded from
  `data.error.<code>` with their params, anything else as a failed request). In `shared/`, as the admin panel's
  pages use them too: `char-avatar` (`app-avatar`: 24, 32, 48, 64 or 88 px - `xs` to `xl`), `changelog-dialog`,
  `stat-pipes` (`statLabel`, `statValue`, `slotLabel`; how long ago is the family's `anotokiAgo`) and `download.ts` (`downloadName()`,
  `saveFile()`), which saves a file the API sent, for the card and the admin pages.
- The pages, a folder each in their half: in `sites/main/`, every page outside `/admin`, open to everybody.
  `dashboard` - three states: nobody (no token, no key: `visitor-hero`, no request for anybody's accounts), a
  visitor (the accounts under the visitor notice), signed in (the accounts and the data card) - and in each the
  builds part (`GET /api/builds`: for how many characters each game has builds and when they were brought up to
  date; for staff a link to the admin panel's Builds page); it loads the accounts again when the state changes and
  after a move. `import` (wizard + `import-guides.ts`, whose texts are keys; four steps - the game is Genshin
  Impact, no step asks for it; the notice for somebody not signed in; nobody's wizard asks for no accounts),
  `account` (each character graded by its build - the one the player chose, "Selected build: ...", else the closest
  match, "Best build: ..." -, as its variation reads; its import history wraps a row under 600 px, so
  the last column is never cut) and `character` (a tab per build, named by its variation, its score the kit's badge,
  the build the player chose marked with a person and a green badge, the closest build with a check, a build the
  character's constellation does not reach locked and said so, "needs C4"; above the build in hand **Use this build**,
  or "Selected" and **Choose automatically** on the one chosen, a toast after either; the notice for a visitor; on a
  401 for somebody not signed in, `visitor-hero` instead of an error), `builds` (the
  builds browser on `/builds/genshin`, no game switch: each character a link card with its builds' roles, the newest
  release first; above them the filters - the name; the element, the weapon type and the rarity, each the kit's
  segmented control, All first and then what the characters here have, an element's colour mark before its name; the
  region and the version the character came with, each the kit's select, the versions newest first with their names,
  "6.1 (Luna II)"; Reset; "Showing N of M", read out as it changes - kept in the address,
  `sites/main/common/query-filters.ts`; per character: its builds in their order; the admin panel's Builds page a
  link for staff, its name alone for anybody else); in `sites/admin/`, `builds` (staff: per game the counts, when
  the newest file was made and when a build last changed, Export, Import - `build-file.ts` checks a chosen file
  before anything is sent, then the server's dry run is the preview: the counts, the characters skipped, the changes
  in the kit's disclosure and a warning when the file is older than the one the builds came from, then Import or
  Cancel -, the recent imports with who made each, and the catalog card: each list's entries and when it was
  refreshed, Refresh catalog running the three lists one after another with the kit's progress bar, a list's last
  failure in a warning), `translations` (staff), `languages` and the People page, `/admin/people` (admin: everybody
  who signed in, what they keep, Delete their data after a confirmation; visitors only counted), `migrations`
  (admin: the family's `<anotoki-migrations-page>` in the panel's `.page` - Pending in the order they will run,
  Applied with their dates in the account's order and clock and each file's SQL, Apply pending after a confirmation,
  and after it "Up to date", "There was nothing to apply" or "Stopped at <file>" with what was applied first and the
  database's error; once nothing is left the site opens again in the tab and the strings are read again); and
  `/sign-in`, the library's. Lazy-loaded from `sites/main/main.routes.ts` and `sites/admin/admin.routes.ts` (both
  spread into `app.routes.ts`); route params arrive as signal inputs (`withComponentInputBinding`).
- Conventions: standalone components, always three files (`.ts`, `.html`, `.scss`), signals (`signal`,
  `computed`, `input`), `inject()`, built-in control flow (`@if`, `@for`, `@let`), zoneless change detection.
  The look is the anotoki family's kit, as on every anotoki site (no Angular Material, no CDK since 0.4.0 -
  [decisions.md](decisions.md), 2026-10-11): `src/styles.scss` takes `@anotoki/lib/styles` (its tokens, Manrope,
  its base, utilities and tables) with this site's palette over the family's tokens, light and dark - one
  library palette, a site keeping its look by its tokens and never by re-styling a kit piece; the pieces come
  each from its own entry point (`@anotoki/lib/ui/button`, `/ui/dialog`, `/ui/tabs` ...), never the
  `@anotoki/lib/ui` barrel; a colour is a `--anotoki-*` token, or one of the site's own meanings (`--grade-*`,
  `--sev-*`, `--rarity-*`, `--el-*` and `--el-mark-*`; the grades, severities and marks light and dark apart). The
  kit's newer pieces (anotoki-lib 0.6.0) stand where the site drew its own: the import wizard's row of steps is
  the kit's stepper, the import history, a build's notes and the wizard's trouble list and paste box its
  disclosure (and the changes an import of builds would make), the score bars and the catalog refresh's progress
  on the Builds page its progress bar, a character (the account page, the builds) its link card, a build's tab
  carries its score as the kit's badge, the account's element filter and the builds browser's element, weapon
  type and rarity filters are its segmented control (each element's mark before its name), the builds browser's
  region and version its select, and the icons the site drew are the family's. What the kit has no piece for
  stays the site's, drawn from its tokens: `.tile` (a game account with its actions, a piece of gear, an empty
  slot), `.section-head`, the wizard's choice cards and file drop zone, and the grade badges. Manrope comes with
  the kit and is bundled; nothing is loaded from another site.
- The dev server (http://localhost:4600) proxies `/api` and `/setup.php` to `http://localhost:8400`
  (`proxy.conf.json`). `ng build` writes `dist/frontend/browser/`, which `router.php` serves in development and
  a release carries in `backend/vendor/`.
