# Decisions

Short records of choices that shape the code. Add a new entry (newest first) when you make a similar call.
Older entries describe the Laravel version as it was; where a setting has since moved, the entry says so.

**2026-09-29 — The backend without Laravel; the app named "build analyzer" (owner's decision).** The backend
is rewritten in the shape of the other anotoki sites (the genshin site, the IAM): Slim 4 for routing and PSR-7
messages and nothing else from a framework, a front controller (`api/index.php`) with route files and
middleware, a small parameterised PDO layer, plain model and repository classes, and SQL migrations applied by
the same engine as theirs (`migration.php`, the Sources page's "Apply", `setup.php`), with `DO $$` verification
gates in the migrations that change data. PostgreSQL only: SQLite was the Laravel version's local default,
and one database engine everywhere means the migrations are the schema. Configuration is three required PHP
files (`config/{database,auth,fetch}.local.php`) written by `setup.php`, a web installer that every copy runs,
the owner's own included - no `.env`, no environment variables, no committed defaults: an installation nobody
set up answers `503 not_set_up` and the app says so. On this computer `setup.php` needs no token (a nonce
instead), can be run again, and offers sign-in "switched off"; on a host it proves FTP access with a token,
always turns sign-in on, locks itself and deletes itself. A database made by the Laravel version is adopted
in place by `001_adopt_laravel_database.sql` (with every row, its framework tables kept aside as `laravel_*`),
and ends up identical to a new one - tested against that version's three schemas. The command-line tools are
plain scripts (`sync-builds.php`, `sync-catalog.php`, `import-account.php`), the tests a small runner of their
own (`php tests/run.php`: throwaway databases, fixtures, a fake network), ports 8400 (API) and 4600 (app).
Content hashes and name keys are computed exactly as before, so stored builds do not look changed.

At the same time the app got its neutral name, "build analyzer" ("Build Analyzer" as a title), everywhere -
code, configuration, docs, tooling - and the UI stopped naming anything that is not ours: build sources are
"Source 1", "Source 2" … (numbered by their order in `config/app.php`, API key `{game}-{n}`), the showcase
service and the export tools are described but never named or linked, texts that came from elsewhere are
cleaned on the way out (`Support/Neutral`), icons are served from `/api/icons/…` instead of the provider's
addresses, and the fonts are bundled instead of loaded from a font service. `scripts/build-dist.php` refuses a
frontend build that names one of those sites. The version and its changelog (`frontend/public/changelog.json`)
show in the app, as on the genshin site.

**2026-09-28 — Hardening from the review of the sign-in / hosting change.** Auth mode "none" trusted any request
from 127.0.0.1, but every other site open in the owner's browser connects from there too, and Laravel's default
CORS allowed all origins on `api/*`: any page could read and change the owner's data. Now the API sends no CORS
headers (the app is same-origin and the dev server proxies), and in "none" mode the guard also refuses a
cross-site `Sec-Fetch-Site`, an `Origin` or a `Host` that is not this computer (the last also stops DNS
rebinding). Other fixes: a step is never shorter than 12 s (`SyncRunner::MIN_STEP_SECONDS`), because the fetcher
keeps 8 s for a request and a shorter step would loop without ever fetching; imports (web requests outside a
step) wait at most 10 s per request with one retry after at most 2 s; the owners migration refused to roll back,
before changing anything, while two users held the same UID; the installer writes its token and config 0644
where PHP runs as another user than the FTP account, since 0600 would lock the owner out; the migrations button
reports a failing migration instead of a bare 500; tests refuse stray HTTP requests; members no longer see who
started a sync.

**2026-09-27 — Deployable on FTP-only shared hosting (owner's decision).** The host has Apache, PHP 8.4 and
PostgreSQL but no shell, cron, queue worker or long-running process. So: the built Angular app is served at the
site root and the API at `/api` from one origin. `scripts/build-dist.php` assembles exactly what to upload
(`vendor/` included, no configuration, tests or `node_modules`). A web installer replaces a shell for the first
install, protected like the genshin site's `setup.php`: a random token that only FTP can read, then a form; it
builds the database before writing the configuration, writes it with owner-only permissions, then a lock, then
deletes itself, and refuses to go on when a probe file in `storage/` can be downloaded. Later schema changes go
through an ADMIN-only "Apply" button (`POST /api/admin/migrations/apply`). Cache and locks live in the database,
which needs no worker. (The Laravel-specific parts - a `public/` document root, `.env`, `deploy/install.php` -
went with the rewrite of 2026-09-29.)

**2026-09-27 — Syncs run in steps driven by the admin's browser (owner's decision: "the analyzer does not
need a queue worker or scheduler. It will be run manually by admin clicking on some button").** The queue job,
the schedule and `runInBackground` are gone. Starting a sync only creates `sync_runs` rows; each
`POST /api/sync/step` takes a global step lock, picks the run that is due, and works on it for at most the step
budget (20 s, now `step_seconds` in `config/fetch.local.php`) - well inside shared-host request limits - saving
after every character. The fetcher keeps each site's last request time in the cache, so crawl delays (Prydwen's
10 s) also hold between requests, and a wait that does not fit into the step becomes a `FetchDeferred`: the run
records `next_step_at` and the browser waits `wait_ms` before the next step, so a step fetches one or two
Prydwen pages. Retries after 429/5xx also move to later steps instead of sleeping. Closing the tab pauses a run
(nothing else advances it); Resume continues from the stored state. The catalog refresh became a run too (one
list per unit), so it follows the same rules, and a stale catalog is refreshed before the build runs of its
game. The command-line tools loop over the same steps, so the owner's local copy and the host behave the same;
the Windows daily task was dropped with the scheduler.

**2026-09-27 — Every person has their own accounts; imports never add to the shared catalog.** `accounts`
and `import_runs` carry `user_id` (existing rows became the owner's, `local_user_id`), `(user_id, game, uid)`
is unique so two people can import the same UID, an account id in a path only finds the signed-in user's
accounts (others' are a 404, like missing ones) and `account_id` in imports is checked against them. The
catalog, builds, sources and sync runs stay shared. An uploaded file is untrusted input for that shared catalog:
before, an unknown name in a file became a new catalog row for everyone - a junk "Emblem X" set would make
"Emblem" ambiguous for every user and for the build sync, and a file could create thousands of rows. Now
imports only look names up; unknown characters, weapons and sets are skipped with a warning (status
"partial") until an admin refreshes the catalog. This applies to admins' imports too: the risk is the same
whoever uploads. An import may still refresh one catalog list when the data names an in-game ID the catalog
lacks (a character newer than the last sync), because that data comes from the catalog provider rather than
from the file; at most once per game and list per 60 minutes (now `catalog.import_refresh_minutes`) across all
users, and before the import's write transaction. Only the build sync still creates characters (new releases
appear on build sites first). The showcase import throttle (10 a minute) is per user.

**2026-09-27 — Sign-in through the anotoki IAM.** All anotoki sites sign people in at iam.anotoki.eu (OpenID
Connect, code flow with PKCE); this app is its client `build-analyzer` (access policy `members`, roles ADMIN
and MEMBER). The frontend uses the IAM's vendored browser SDK (`core/iam/`) behind an `AuthService` with
signals, a functional interceptor (bearer on this site's `/api` only, one retry with `refresh()` after a 401)
and route guards; the backend verifies each access token locally with the IAM's vendored PHP verifier (v1.0.1:
RS256 only, `typ at+jwt`, issuer, audience, expiry, JWKS cached on disk). Users are created just in time with
the IAM user id as `users.id` and `preferred_username` as the name; roles come from each token and are never
stored. The mode is installation configuration (now `mode` in `config/auth.local.php`): anything but "none"
asks for sign-in (a server is never open by default, and a typo counts as "iam"). With "none" - the owner's own
copy - every request acts as `local_user_id` with every role and the UI shows no sign-in controls; as a second
safety net that mode answers only 127.0.0.1 / ::1 (tightened on 2026-09-28 to the app's own pages), since a copy
started with sign-in off must not serve the network. (Behind a reverse proxy on the same machine every client
looks local, which is why "none" is for the owner's computer only.) `GET /api/meta` tells the frontend the mode,
the issuer and the client id before anything is drawn.

**2026-09-25 — A dry run for the build sync.** A trial run of the `sync-doctor` agent showed that read-only
diagnosis needed a way to replay cached pages against stored builds without writing, so the sync command
gained `--dry-run` (same normalization and hash as real syncs; today `php sync-builds.php --dry-run
--cache-hours=96`). A sync timezone setting added at the same time went with the schedule on 2026-09-27.

**2026-09-25 — Project AI setup.** `CLAUDE.md` + `docs/` knowledge base + project skills and agents in `.claude/`.
The bash helper `scripts/php.sh` makes every session use PHP 8.4+ without hard-coded paths.

**2026-09-24 — Inventory suggestions never make set bonuses worse.** Per-slot swaps keep the target option's
fraction (and, when no recommended set is worn, the current 2pc/4pc bonuses); switching sets is shown as a
set plan instead of misleading single-piece hints.

**2026-09-24 — Build sources: genshin-builds.com + Prydwen (Genshin), Prydwen + genshin.gg (HSR, and GI as a third
opinion).** Chosen for structured embedded data and freshness. game8 (hand-written tables, bot blocking),
KeqingMains (prose, stale) and genshin-builds HSR (game8 copy) rejected. Prydwen "analytics-only" Genshin
pages become "Most used" builds so every character is covered.

**2026-09-24 — Catalog from yatta.moe, not Enka's store.** yatta has current lists for both games with IDs
that match Enka and scanner exports; Enka's legacy name files stopped at 6.2. Enka's `store/gi/avatars.json`
is used only for talent skill order.

**2026-09-24 — Canonical keys = normalized English names.** One key space for GOOD keys, display names and
slugs; aliases + unique fuzzy matching cover nicknames and typos. A shorter catalog key never matches a
longer name (new alt characters like "Himeko • Nova").

**2026-09-24 — Build details as JSON columns.** Builds are always read whole and compared in PHP; JSON keeps
the schema small.

**2026-09-24 — Import methods: the showcase (UID) + exporter files; the official web account deferred.** No
credentials needed. An import through the game's official web account needs the player's session cookies and
a changing request signature; revisit on request.

**2026-09-24 — Angular 21 + Angular Material, PHP backend.** The user's stack preference (Angular, PHP).
Angular 21 because the installed Node (22.15) is too old for Angular 22. PHP 8.4+ required for the native
`Dom\HTMLDocument` parser. (The backend started on Laravel 13; see 2026-09-29.)

**2026-09-24 — Politeness first.** Per-host delays (Prydwen 10 s per robots.txt), 12 h page cache, a stale-hours
guard so manual runs never re-hit a site the same day, and runs limited to a few characters never mark a
source as synced.
