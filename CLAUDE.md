# Build analyzer

Imports Genshin Impact accounts (the Character Showcase by UID; GOOD files), takes the recommended builds from one
file per game an editor imports on the admin panel (put together elsewhere - docs/builds.md), and shows per
character what to improve. Backend: PHP 8.4+ / Slim 4 / PostgreSQL in `backend/` (no Laravel, no Illuminate
components). Frontend: Angular 21 on the anotoki family's UI kit (`@anotoki/lib`; no Angular Material) in
`frontend/`. Hosted at builder.anotoki.eu. Sign-in is
through the anotoki IAM only - on the owner's computer too - and every anotoki account may use the site
(each person has their own accounts; every account holds MEMBER, an ordinary person here); the builds browser and
the builds' dates are public - unless the IAM's "Sign-in required" switch for the application is on, which
the server reads from the IAM (the anotoki library's `SignInRule`) and relays in `/api/meta`
(`auth.login_required`): then nobody and no visitor key gets anything but meta, the strings, the icons, the site settings' public answer, robots.txt / sitemap.xml and the site's status (`/api/site-status`, which reads no credentials). Somebody
signed in at the IAM is signed in here without a click (the IAM's sign-in hint). Roles here:
EDITOR imports the builds, changes them by hand and writes the site's notes on them (0.7.0), refreshes the catalog and
rewords the site's strings; ADMIN does that and also applies
database updates, adds and deletes languages, deletes a person's data, throws the site settings (closing the site,
an announcement, who may see which page) and has the admin tools (the error log, the audit log, feedback, backups).
Deployable on FTP-only shared
hosting: no shell, cron, queue worker or long-running process. The member pages read in English or Slovak:
the site's words live in the database (migration 003, kept since 0.4 by the anotoki library's translations
module) and editors reword them in the browser (`/admin/translations`, `/admin/languages`: the library's
pages); the admin panel (`/admin/...`) stays English.

**Version 0.3 - the family port** (the anotoki sites made one way): IAM-only sign-in with the EDITOR / ADMIN
split; **visitor accounts** (somebody not signed in imports and uses their own accounts under a visitor row
with a negative id, opened by an `X-Visitor-Key` the server made; moved into the person's data at sign-in);
each signed-in person's **own accounts as a file** (`GET /api/me/export`, `POST /api/me/import`: the newer
copy of an account wins, an import never deletes one); every page but the admin panel open to everybody
(nobody / a visitor / signed in), the admin panel under `/admin/` (editors: Sources, Translations; admins also
Languages, People and Migrations); and **the family's top bar** (`sites/main/common/top-bar/`) with the version and What's new in the
footer. Migration **004** (the visitors' schema and every string of it, the bar's included) is complete: on a
host it is applied on the Migrations page (Apply pending) by an ADMIN at once after the upload, and **then** the IAM's migration
007 (docs/operations.md, "Deploying 0.3"). The browser check (`verify-ui`) runs all of it on a throwaway
database with the stand-in IAM.

**Version 0.4.0 (unreleased) - signing in, people and the admin tools through anotoki-lib 0.5.0** (the owner's
round of 2026-10-11, docs/decisions.md; 0.4.1 first): the library's **auth module** signs people in (the IAM's SDKs, the token
check, the IAM's switch, the browser's client, interceptor, guards, callback and the sign-in failure's screen on
`/sign-in` are the library's; a visitor signed in at the IAM is signed in here with one silent hop - the IAM's
sign-in hint; robots.txt keeps search engines off `/auth` - the library's since 0.11.0, from the site's PHP), its **people module** keeps the People page and
scrubs the accounts the IAM deleted (the data stays, nobody's), and its **admin tools** - the error log, the audit
log, feedback (the visitors' form at the foot of every page; no mail: the bar counts what is new) and backups -
are the ADMIN's alone (no file explorer here). Migrations **007** (`users.anonymized_at`), **008** (what the
Laravel version left dropped - its login tables `laravel_users`, `laravel_password_reset_tokens`,
`laravel_sessions` and its queue's `laravel_jobs`, `laravel_job_batches`, `laravel_failed_jobs`; only
`laravel_migrations` stays: no email, session address or old job payload on any site, the owner's decision)
and **009** (the sign-in screen's words under the
library's keys, the feedback form's), between the library's sets `anotoki_admin` (first) and
`anotoki_translations` / `anotoki_people` (after). Backups have no maximum age (the owner's decision). With
**anotoki-lib 0.5.0** (2026-10-12): a restore does again what was deleted since its backup (the People page's delete runs in
the library's transaction, on the connection it is given), backups are sealed - only one made here is restored
here -, the errors the pages meet go to the error log (`POST /api/client-errors`), and the admin tools read where
a request comes from by the site's rule (Cloudflare's `CF-Connecting-IP`, the library's `ClientAddress`). And
**the site's own pages on the family's kit** (the owner's round of 2026-10-11): every page, piece and dialog is
the kit's or the site's own drawn from its tokens, the top bar and the page's frame the family's
(`@anotoki/lib/shell`), Angular Material and the CDK gone (the first load 550 kB, was 726 kB); migration **010**
moves the top bar's words under the library's keys (`anotoki.topbar.*`, `anotoki.theme.*`) with every rewording.
And **the origin secret** (2026-10-13, the library's PHP 0.5.2; the owner's decision, docs/decisions.md):
Cloudflare's ranges are every Cloudflare customer's, so a Transform Rule of the owner's zone adds `X-Anotoki-Origin`
with a secret to every request; given the same value in the optional `config/cloudflare.local.php`
(`Config::originSecret()`), the site believes `CF-Connecting-IP` only on a request that carries it - the site's
counters and the admin tools alike -, any other request from Cloudflare counting as its edge; without the file, as
before; with one that cannot be read cleanly, or that names no usable secret (blank, no array, another key, not a
string: the owner's "do as you think is the best" of 2026-10-18), a secret no request carries (it fails closed, the
site keeps running) - only deleting the file switches the secret off.
And with **anotoki-lib 0.6.2** (2026-10-18, both halves; docs/decisions.md): **Terms, Privacy and Contact** - the
family's pages (`@anotoki/lib/legal/pages`) on `/terms`, `/privacy`, `/contact`, anybody's, with this site's facts
(`sites/main/legal/legal-facts.ts`, migration **011**: what it keeps, what for and how long, its browser storage, who else
handles data, the fan clause naming both games) - and Terms · Privacy · Contact with the fan clause at the foot of
every page; **every settings file read cleanly or not at all** (`Config::readLocalFile()`: never compiled unless
it starts with `<?php`, nothing it prints ever sent); the grade letters and a recommendation's mark at 4.5:1 in
both themes.
And with **anotoki-lib 0.6.3** (2026-10-18, both halves; docs/decisions.md): **the family's footer** - the owner chose
this site's for every anotoki site ("The order, texts and the top border"), and the library's `<anotoki-site-footer>`
is a copy of it, in both layouts in its place with nothing visible changed; migration **013** moves its three words
under the library's keys - and **Feedback only where a message can be sent** (the rule below).

**Version 0.5.0 - builds without sources** (the owner's round of 2026-10-20, docs/decisions.md): **the site never
reads a build site** - an editor imports one builds file per game on the admin panel's **Builds page**
(`/admin/builds`: Export, Import with a dry run first, the recent imports, the catalog), put together by the owner's
untracked local tools (`local/`, git-ignored; docs/builds.md says what they deliver); the file is the whole truth for
its game (since 0.7.0 but for what editors did by hand), and a build found again by its identity keeps its id (`App\Services\Builds\BuildImport`, `BuildFile` -
format version 2). **A build is a variation** of a character: a role (`main_dps`, `sub_dps`, `support`), a
`qualifier` where a character has two of one role, and the constellation it needs (`min_rank`); a stat target can
start from a constellation (`from_rank`), and the analysis follows the player's. **Star Rail is gone**, the
multi-game shape kept (`App\Enums\Game`: one case, the per-game facts its methods). **The catalog comes from the
owner's genshin.anotoki.eu** (its public catalog lists, `Providers\GenshinCatalog`): a conditional request per list,
kept fresh by the upkeep after an answer, this site keeping its catalog while that one is closed. **The builds browser
has filters** (element, weapon type, rarity, region, version, name - kept in the address). And **the repository names
no build site**: their names live in the untracked `backend/config/neutral.local.php`, and
`backend/tools/check-names.php` reads the tracked tree - and, in the owner's hooks, every commit - for them.
Migrations **014**-**019** (Star Rail's rows and words out; the sources, the syncs and the old builds out, the
variations and the imports' record in, with their words; the catalog's region, version and version name; the
filters' words): on a host applied at once after the upload, then Refresh catalog and Import (docs/operations.md,
"Deploying 0.5.0").

**Version 0.6.0 - a build's teams** (the owner's request of 2026-10-22, docs/decisions.md): every build
lists **the teams it is played in**, the best first, in the builds file - **format version 3** (version 2 refused
with its own sentence): a team `{name, members, note}`, a member `{key, name, role, min_rank, alternatives}`, the
build's own character among 2-4 members exactly once and with no stand-in (`BuildFile::read()`, `teamsEntry()`;
`Game::teamSize()`). Stored in `builds.teams` (migration **020**, NULL for none); a build without teams hashes as
before, so the first version 3 import updates only the builds that gained teams. What the catalog says of a teammate
(known, picture, rarity, element) and whether it has builds are looked up when a build is shown
(`BuildPresenter`), never stored; a teammate the catalog lacks skips nothing (`teammates_unknown` in the import's
answer). The page (`sites/main/common/build-teams`) links a teammate to its builds; on an account's character page the
teammates the account has wear a check (heard "Owned"; a member below the team's constellation "needs C2") and link
to the account's own analysis of them (`owned_teammates`, `Accounts::characterRanks()`; the Traveler the account's in
every element once it has it in one - `Game::isTraveler()`, its constellation there null). Migration **021**: the
teams' three words. On a host applied at once after the upload, then the version 3 file imported - before the next
builds refresh: the local tools write version 3 only (docs/operations.md, "Deploying 0.6.0"). **0.6.1** (the owner's
look at it, 2026-10-22): a team side by side on a phone too, 64 px portraits (and in the builds list - the avatar's
`lg`; its 88 px heroes `xl`), a check for "Owned", the Traveler in every element, a set option's pictures in a column
of one width; and the local tools write no notes of the sites' (`export.notes`) and keep every team.

**Version 0.7.0 - the builds editor, task 9** (the owner's round of 2026-10-22, docs/decisions.md; the
plan in seven steps, a pause after each for the owner's word): editors change builds by hand in the admin panel - a
form for every part, drawn with the kit -, make builds of their own, remove others and write **the site's own notes**
(English, Slovak optional) on a build and its teams; **every import keeps what they did** (docs/builds.md, "Hand
edits"): what the file said of a build stays beside it (`builds.file_build`), an import compares part by part
(`BuildParts`) and asks per build only where the file and an edit changed the same part (the edit standing unless the
file is chosen; the answers sent with the preview's plan, 409 `plan_changed` when the questions changed), builds made
here are kept, removed ones never come back unasked (`build_removals`), an editor's order stays (said, `build_orders` - never
guessed), notes are never
touched (`builds.site_notes`, `BuildNotes`), and an export imported again changes nothing. Members read the notes in
Slovak where an editor wrote it, else in English (`shared/i18n/site-note.ts`); the file's own texts (a weapon's, a set
option's, a team's note, a target's text, the build's notes) are sent by no answer. Migrations **022** (the storage;
every build's `file_build` its columns as they were) and **023** (the staff's Edit builds on a character's builds page,
the Privacy page's audit-log sentence with hand edits; the legal pages' date moved). All seven steps done
(2026-10-23): the storage and the import (step 1), the editor's API (step 2: `BuildEditor`, docs/builds.md "The
editor"), the editor's page with its Variation and Notes cards and the notes on the member pages (step 3:
`/admin/builds/:game/:key`), the forms of the weapons and the artifact sets (step 4), of the main stats, substats,
talents and stat targets (step 5) and of the teams with their notes (step 6), and the release - the version, What's
new, docs/operations.md "Deploying 0.7.0" (step 7); deployed 2026-10-23. **0.7.1** (the owner's look at it,
2026-10-23): Edit builds a table of every character; a build's role, qualifier and constellation changeable - a build
of the file's renamed becomes the editor's own, the file's set aside (`renameBuild()`); a team's members side by side,
a column each, the note under them.

**anotoki-lib 0.11.0 - the site settings, robots.txt and sitemap.xml, the family's dates and search, one admin
menu** (the owner's requests of 2026-10-25, docs/decisions.md; both halves): **the site settings** are the ADMIN's
(`/admin/site-settings`, the library's page; `App\Web\SitePages`, the library's tables `anotoki_admin/003`): **Getting
in** closes the site for maintenance - the site gate answers everybody 503 `maintenance` but the site's ADMIN, known by
the token alone (`siteGate()`'s `exempt`), and the reserved API paths; the pages draw the family's closed sign; an
administrator signed out signs in at `/staff` -, **the announcement** draws a bar above everybody's pages (hidden per
browser until its words change, `build-analyzer-announcement`), and **Page access** keeps a public page - the
dashboard, the import, an account and a character of it, the builds browser, a character's builds - for people signed
in, editors or administrators, or switches it off (the router's `pageAccessGuard`, a canMatch: a closed page is no page,
the dashboard - or `sites/main/not-open/` where the dashboard is closed too -; the server's `PageAccessGate` in every
route group right after its own check: 423 `page_locked` on the API paths its row names). The reserved API paths
(`SitePages::RESERVED_API_PATHS`) are the library's own and `/api/meta` (the start of every page: the sign-in's
configuration) and `/api/icons` (an `<img>` carries no token); who the reader is comes from the token, no route.
**robots.txt and sitemap.xml** are the library's `Robots` from the site's PHP (`api/routes/robots.php`, the release's
`routes`, `router.php`): robots kept out of `/admin`, `/api`, `/auth`, `/accounts`, `/sign-in`, `/staff`; the
sitemap at the site's own address (`Config::siteAddress()`: `config/site.local.php`, else a release's
`https://builder.anotoki.eu`, a checkout's `http://localhost:4600` - never a request's Host) lists the legal pages,
About, Support and, while visitors are welcome, the public pages and every character's builds page - never what Page
access hides. **The dates are the kit's** (`@anotoki/lib/ui/dates`; `local-date.ts`, `date-patterns.ts` and
`timeAgo()` gone) and **searching too** (`@anotoki/lib/ui/search`'s `filterBySearch()` on the Builds page's table).
**The admin menu** has the family's one shape: Builds; Localization - Translations, Languages; System - Site settings,
People, Feedback, Error log, Audit log, Backups, Migrations (and while an update waits, Migrations alone). Migration
**025** (the Privacy page's part about the settings, the page that is not open, the audit log's sentence), with the
library's `anotoki_admin/003` and `anotoki_translations/014`, `015`: one Apply (docs/operations.md, "Deploying
anotoki-lib 0.11.0").

**The shared library, anotoki-lib** (`irelevant25/anotoki-lib` on GitHub: Composer `anotoki/lib` ^0.11.0 through a
`vcs` repository - 0.11.0 now -, npm `@anotoki/lib` 0.11.0 from its release tarball - never a local path, never 0.4.0; DEV.md "The
shared library" says how to update both halves together) holds the auth, people and admin tools modules (above),
**the legal pages** (since 0.6, the owner's decisions of 2026-10-13: Terms, Privacy, Contact and the footer's links;
since 0.8.0 the About and Support pages, the same on every anotoki site),
**the footer** (since 0.6.3: the version, What's new, Feedback, About · Support · Terms · Privacy · Contact, the fan clause),
**the admin panel's frame** (since 0.10.0, the owner's request of 2026-10-24: `<anotoki-admin-frame>`, the genshin site's
frame for every anotoki admin panel - its pages in a column on the left, the page on the right, no top bar),
**the site settings and robots** (since 0.11.0: `Anotoki\Lib\SiteSettings`, `Robots`, `@anotoki/lib/site-settings`,
`/admin/site-settings`; this site's part `App\Web\SitePages` and `shared/site-settings.ts`),
**the translations module** (since 0.4, the owner's
decision of 2026-10-11, docs/decisions.md: the strings' routes, checks, command line, service, pipes and the
admin Translations and Languages pages, drawn by its kit - this site keeps its keys, their English and Slovak,
its released languages and its neutral-names check; its words are the namespace `anotoki.`) and the migrations
module, the family's one behaviour while a site
is not ready (the owner's decision of 2026-10-07, docs/decisions.md): its engine runs this site's files
(`App\Db\Migrations`, then the library's own set `anotoki_translations`), and **the site gate** answers every API path 503 while the site is not set up
(`not_set_up`, the setup page only while never installed: `storage/installed.lock`) or a migration waits
(`update_pending`) - but `/api/meta`, the strings of a language, `/api/site-status` and the Migrations page's
API - or the site's ADMIN has closed it for maintenance (`maintenance`, since 0.11.0: the reserved API paths open,
the ADMIN through). The frontend shows the gate's page instead of the site: "being updated" to everybody, "A database update
is waiting" and the Migrations page to the ADMIN; the closed sign while closed. So **every upload with a migration closes the site until an
ADMIN applies it** - apply at once.

## How to work in this repo

1. **Read the knowledge base page for the area you touch** before changing it — `docs/README.md` is the index:
   architecture (incl. the site gate, sign-in, neutral names and the site's words), data model and migrations, stats & slots,
   name matching, builds (variations, the builds file, the import), import formats, analysis, operations (configuration, commands, sign-in modes,
   deploying, machine quirks, troubleshooting), decisions. Setup, running, testing and deploying for people:
   `DEV.md`.
2. **Use the project skills** for these tasks (invoke them with the Skill tool, or read `.claude/skills/*/SKILL.md`):

   | Task | Skill |
   | --- | --- |
   | new import format / importer fix | `add-import-format` |
   | scores, grades, recommendations, inventory suggestions | `tune-analysis` |
   | any UI change or "look at the app" (the browser check: `node .claude/skills/verify-ui/scripts/run.mjs`) | `verify-ui` |

3. **Delegate to the project agents** when it saves context or runs in parallel:
   `analysis-auditor` (explain / audit scores, read-only), `build-analyzer-reviewer` (review a finished change).
   Reading the build sites is the owner's local tools' work, in the untracked `local/` folder (its README.md and
   CLAUDE.md, its own skills and agents).
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
- npm 10.9.2 crashes on install here → `npx -y npm@11 install|ci`. Node 22.15 (hence Angular 21). `npm start` in
  `frontend/` runs `bun ci` first (Bun 1.4, `bun.lock`): a change of the dependencies updates both lock files.
- No Python (`python` is the Store stub); script with node or PHP.
- Tool shells reset their working directory: start commands with `cd <repo root> &&`. Put longer PHP snippets
  into a scratch file (outside the repo) that requires `backend/vendor/autoload.php` and uses `App\Cli::app()`.
- Configuration is only `backend/config/{database,auth}.local.php` (required) and `fetch.local.php`
  (optional: defaults in `Config::FETCH_DEFAULTS`; setup writes it with them only when it is missing) -
  gitignored, written by `backend/setup.php`; no `.env`. By hand only, never written or read by setup:
  `client_address.local.php` (the proxies), `cloudflare.local.php` (the origin secret, the same file in every
  anotoki site: `['origin_secret' => ...]`, `Config::originSecret()` - a string with something in it; none only
  without the file) and `neutral.local.php` (the build sites' names, `['names' => [...], 'tree_words' => [...]]`:
  `Neutral` adds the names to its own, `tools/check-names.php` reads the tracked tree for both; uploaded to the host
  once, never in the repository) and `site.local.php` (`['address' => 'https://...']`: the site's address for
  robots.txt and the sitemap, `Config::siteAddress()` - only where it is not the release's `https://builder.anotoki.eu`;
  one naming no usable origin is passed over, the address of where the code runs holding).
  Every one is read cleanly or not at all (`Config::readLocalFile()`, the family's rule; docs/operations.md,
  "Configuration"): never compiled unless its first five bytes are `<?php` (a BOM or a blank line before
  `declare(strict_types=1)` is a fatal error nothing catches), whatever it prints caught and never sent, one PHP
  cannot read no file - said in PHP's error log once a day by its fault, never its text. Passed over, never looser
  than no file: the database's or the IAM's leaves the site not set up, `fetch.local.php`'s defaults stand in,
  `client_address.local.php` trusts no proxy, and `cloudflare.local.php` fails closed - a secret no request carries,
  every request from Cloudflare's ranges its edge (the site keeps running) -, as does one read cleanly that names no
  usable secret: blank, no array, another key, not a string. Setup reads its files the same way. One environment
  variable, for the browser check alone: `BUILD_ANALYZER_TEST_CONFIG` names a settings folder that stands in for
  `config/` and `storage/` - honoured only for a `build_analyzer_test_` database (anything else is "not set up").
  Without the two the API answers 503 `not_set_up` (the site gate's: with the setup page only while
  `storage/installed.lock` is not there). Setup asks for the database and the IAM only (defaults
  `anotoki_builder`, port 5432). Sign-in is the IAM's, always: a `mode` or `local_user_id` left in an older
  `auth.local.php` is ignored and opens nothing.
- Database times are UTC (`TIMESTAMPTZ`). Nothing is scheduled.
- Git Bash: heredocs with apostrophes or backslashes can come out wrong — create files with the editor tools.
- Git repository on `main` with a GitHub remote (`origin`); commit or push only when the user asks.

## Commands

```bash
cd backend && php tests/run.php                      # backend tests (throwaway databases, 25 files, ~379 tests)
(cd frontend && npx ng build && npx ng test --watch=false)   # Vitest: every spec beside its code in src/app
bun run format:check                                 # Prettier on every file of both halves, at the root (bun install there first)
node .claude/skills/verify-ui/scripts/run.mjs [--no-build] [--keep]   # the browser check (8590 + 8597, a throwaway database; PHP=<8.4+> where php is older)
php backend/tools/check-names.php [--staged | --message=<file>]       # the tracked tree names no build site (the owner's hooks run it)
cd backend && composer start                         # API: php -S localhost:8400 router.php (+ setup.php, + the built app)
cd frontend && npx ng serve                          # http://localhost:4600, proxies /api, /setup.php, /robots.txt, /sitemap.xml
cd backend && php migration.php [--status|--dry-run] # apply pending migrations
cd backend && php sync-catalog.php [genshin] [--list=characters|weapons|sets] [--force]   # the catalog from genshin.anotoki.eu
cd backend && php import-account.php genshin <uid|file> --user=IAM_ID [--account=ID]
cd backend && php translations.php [--status | --export=sk [file] | --import=sk file]   # the site's strings
cd backend && php backup.php [--list | --create ["why"] | --inspect=<id> | --extract=<id> <folder> | --restore=<id> --confirm=<last 6>]
cd frontend && bun run anotoki-deploy build   # ../dist/frontend + ../dist/backend: the release (deploy-builder skill)
```

## Code map (backend paths under `backend/`)

- Entry: `api/index.php` → `api/app.php` (`buildAnalyzerApi()`), `api/routes/*.php`, `api/middleware/*.php`; container `src/App.php`, config `src/Config.php`
- Database: `src/Db/Db.php` (PDO helpers, `ensureDatabase()`), `src/Db/Migrations.php` (this site's files on anotoki-lib's `Migrator`), `migrations/*.sql`; rows `src/Models/*`, queries `src/Repositories/*`
- Stats & slots: `src/Support/Stats.php`, `src/Enums/Game.php`, `src/Support/Talents.php` (canonical keys: `critRate_`, `atk_`, `spd`, `pyro_dmg_`…)
- Sign-in (anotoki-lib's auth module, `vendor/anotoki/lib` 0.11.0 / `@anotoki/lib` 0.11.0): `src/Auth/Iam.php` (the library's `IamClient` from `config/auth.local.php`), `src/App.php` (`AccessTokens`, `SignInRule` - the IAM's switch, kept in `storage/cache/iam/`), `api/middleware/auth.php` (`requireSignIn`, `requireOwner`, `requireRole`, `requireTokenRole` - the role from the token alone, for the routes that answer while an update waits -, `optionalSignIn`, `makeVisitor`, `currentUser[OrNull]`: each the library's `Identify` and its checks in order - `src/Web/Pipeline.php` -, in this site's words, then Page access - `pageAccess()`, the library's `PageAccessGate`: 423 `page_locked` -; a bad token 401 `invalid_token`), `src/Auth/Role.php` (ADMIN, EDITOR, STAFF), `src/Auth/RequestCaller.php` (who asked, for the error log), `src/Models/User.php` (`fromPerson()`), `/api/meta`'s `auth` (`AuthRoutes::configBody()`); the tests' IAM `Anotoki\Lib\Auth\Testing\TestIam` (`T::$iam`: its key, its tokens - `iamToken()` -, its answers as files - `iamRequiresSignIn()`, `T::$iam->anonymized()`), the stand-in `vendor/anotoki/lib/backend/testing/stand-in-iam.php` (`tests/stand_in_iam.test.php`, the browser check); frontend `app.config.ts` (`provideAnotokiAuth()`: the boot answer `/api/meta`'s `auth` via `MetaStore`, `visitors: VisitorKey`, `failureRoute: '/sign-in'`, `forbiddenRoute: '/admin/builds'`, `api.skip`; the hint is on: a visitor signed in at the IAM is signed in with one silent hop), `shared/auth.ts` (`AuthService`: the library's `AnotokiAuth` under the pages' names - `available`, `signedIn`, `loginRequired`, `isStaff`, `isAdmin`, `displayName`, `preferences` + `savePreferences()`, `failure`, `accountUrl`, `login()`, `logout()`), `api/api.ts` (the family's `ISSUED_AS`: `caller`, or `person` for what a visitor may never do), `shared/auth.guards.ts` (`sitePagesGuard`: the family's `personOrVisitorGuard`, and a visitor's failed sign-in said once on `/sign-in`; `/admin` takes `personGuard`, its ADMIN pages `adminGuard`), `shared/sign-in.ts` (`signInOrSayWhy()`), the routes `/sign-in` (the library's `SignInFailureComponent`) and `/auth/callback` (`AuthCallbackComponent`), robots.txt (the library's, from `api/routes/robots.php`: `Disallow: /auth`), `sites/admin/app.ts`
- Migrations and the site gate (anotoki-lib: `vendor/anotoki/lib` 0.11.0, `@anotoki/lib` 0.11.0 - `@anotoki/lib/migrations` and `@anotoki/lib/migrations/page`): `api/middleware/site_gate.php` (`siteState()`: the Config's problems, `storage/installed.lock`, the container's `Anotoki\Lib\Migrations\Migrator`, the site settings - closed for maintenance; `siteGate()`: the site's ADMIN through while closed, by the token alone - `AccessTokens::personOf()`; `siteOpenPaths()`: `/api/meta`, the strings of a language (`TranslationsRoutes::openPath()`), the Migrations page's three routes, the error log's and the backups' but a restore - each a route exactly, never a prefix), `api/app.php` (the gate inside the JSON errors; `GET /api/site-status` {state, installed}), `api/routes/admin.php` (`MigrationsRoutes::admin`: `GET /api/admin/migrations` {sets, applied, pending, missing}, `GET …/file?set=&name=`, `POST …/apply` {applied, failed, error, note} - 409 `busy` while another apply holds the advisory lock - behind `requireTokenRole(ADMIN)`, the log lines in `afterApply`), `src/Db/Migrations.php` (the library's `anotoki_admin` first, the set `build_analyzer`, then the library's `anotoki_translations` and `anotoki_people` - each `Schema::migrationSet()`, its files recorded as `anotoki_translations/001_...` in the one `migrations` table -, `beforeApply` = the library's backup before an update (the API's and `migration.php`'s Migrator), `prepare` = the Laravel table moved aside, `beforeFile` = the `build_analyzer.*` settings, `LOCK`; the container's Migrator is replaceable, so a test can give it another folder - `library: false` for a folder of the test's own); tests `tests/site_gate.test.php`, `migrations.test.php`, `before_apply.test.php`; frontend `shared/site-status.ts` (`provideSiteStatus()`: ADMIN from the token, sign-in, the language on the page, the account's dates, `i18n.reload()` once up to date), `siteStatusInterceptor` + `SiteStatus.check()` in `app.config.ts`, `<anotoki-site-gate><ng-template anotokiSitePages><router-outlet /></ng-template></anotoki-site-gate>` in `app.html` (`SitePagesDirective`: the pages made only while the gate shows them - nothing behind its page runs; an unmarked template draws nothing), `sites/admin/migrations/` (the family's `<anotoki-migrations-page>`, from its own entry point `@anotoki/lib/migrations/page` so it comes with that lazy route, in the admin panel's `.page` - every file named with its set), the `--anotoki-*` colours in `styles.scss` (every page is drawn with the library's kit - see "The look" below)
- Site settings and robots (ADMIN; anotoki-lib 0.11.0's `Anotoki\Lib\SiteSettings`, `Robots`): `src/Web/SitePages.php` (`settings()`: all three parts, `RESERVED_API_PATHS` - `/api/meta`, `/api/icons` -, `RESERVED_PAGES` - `/sign-in` -, `GOVERNED`: the six public pages with the API paths each reads; `robots()`: `ROBOTS_DISALLOW`, `ALWAYS_LISTED`, `publicEntries()` - none while the IAM requires a sign-in -, no kept copy), `src/Config.php` (`siteAddress()`, `site.local.php`), `api/routes/site_settings.php` (`SiteSettingsRoutes::public` - `GET /api/site-settings` - and `::admin` behind `requireRole(ADMIN)`, audited `site_settings.changed`, `page_access.*`), `api/routes/robots.php` (`/robots.txt`, `/sitemap.xml`; the release's `routes` in `composer.json`, `src/DevRouter.php`'s `ROBOTS_PATHS`), `api/middleware/auth.php` (`pageAccess()` in all five group checks, after each one's own), `api/middleware/site_gate.php` (`siteGate()`), `src/Repositories/Builds.php` (`charactersWithBuilds()`: the sitemap's characters with the day they changed), `src/People/SitePeople.php` (the People page's delete leaves a setting changed by nobody), `src/Admin/AdminTools.php` (`EXPECTED_ANSWERS`: `maintenance`), migration 025; tests `tests/site_settings.test.php`; frontend `shared/site-settings.ts` (`provideSiteSettings()`: roles and sign-in from `AuthService`, `ANNOUNCEMENT_KEY`; `STAFF_ROUTE`, `OPEN_WHILE_CLOSED` for `shared/site-status.ts`), `app.config.ts` (`SiteSettings.load()` before the first page; `/api/site-settings` read with no credentials), `sites/main/app-layout/` (`<anotoki-announcement>`, `<anotoki-page-notice>`), `sites/main/main.routes.ts` (`pageAccessGuard` on the six pages, `/staff` - `staffRouteGuard` -, the two `**`), `sites/main/not-open/`, `sites/main/common/top-bar/top-bar.ts` (`mayNavigate()`), `sites/admin/admin.routes.ts` (`/admin/site-settings`: `@anotoki/lib/admin/site-settings`), `sites/admin/app.html` (`<anotoki-page-notice adminFrameNotice />`), `sites/main/legal/legal-facts.ts` (the settings' part, the announcement's storage)
- People (ADMIN; anotoki-lib's people module): `src/People/SitePeople.php` (the library's `People` on this site's `users`: the mapping - `name`, `created_at`, `last_seen_at`, `updated_at`, `anonymized_at` (migration 007) -, the columns accounts / imports / last import, the scrub's hooks - feedback's and the audit log's copies blanked, all on the scrub's connection -, the delete `deleteHook()` → `deleteData()`: inside the library's transaction (`People::delete()`, which forgets the person's feedback and writes the entry in it), ALL of it on the `$pdo` it is given - never the site's own connection, which a restore's locks would hold - and no transaction of its own; a restore runs it again for every delete since its backup), `api/routes/people.php` (`PeopleRoutes::admin()`: list, `DELETE /api/admin/people/{id}` - only its question, audited `person.data_deleted` in the delete's own transaction -, `POST …/sync`), the scrub after an answer at most hourly (`api/middleware/upkeep.php`, the library's `PeopleUpkeep`; `config/app.php` `upkeep.people`), `src/Repositories/Users.php` (`visitorCounts()`, `deletePerson()` - in the caller's transaction); `migrations/008_drop_laravel_leftovers.sql` (the Laravel version's login and queue tables gone; `laravel_migrations` stays); tests `tests/people.test.php`; frontend the family's page `@anotoki/lib/people/page` on `/admin/people`, set up in `app.config.ts` (`provideAnotokiPeople()`)
- Admin tools (ADMIN alone; anotoki-lib's `ErrorLog`, `Audit`, `Feedback`, `Backups`): `src/Admin/AdminTools.php` (`AdminContext`: the token's person, the proof a sign-in of the last ten minutes, `trustedProxies` - `client_address`'s, their `CF-Connecting-IP` alone (the library's default header, never a setting), the library's rule as `App\Web\ClientAddress` reads - and `originSecret` (`Config::originSecret()`: with it, the header only on a request carrying it),, `names` - `AdminTools::names()`: a backup's maker, the audit log's and feedback's people -, this site's error body; the error log `storage/logs/errors-*.jsonl` - every 5xx, 404, 405, 413, 415, 429, but an answer whose code says a state, not a failure (`AdminTools::EXPECTED_ANSWERS`: an update or a table waiting, the site closed for maintenance; a 503 that is a failure, such as feedback's `storage_unavailable`, is written) -, the audit log, feedback - `FEEDBACK_SECTIONS` -, backups `storage/backups/` - COPY, daily, before an update and a restore, by hand; sealed: only one made here is restored here - one made while `storage/anotoki-lib.key` can be neither read nor made is unsealed (the error log says so) and restored offline only; a restore does again what the People page and the Feedback page deleted since - what people deleted themselves comes back -, then the scrub, `People::restoreHooks()`; no maximum age; the command line runs what it leaves for after the answer when it ends, `App\Cli::app()`), `api/routes/admin_tools.php` (`/api/admin/errors`, `/audit`, `/feedback`, `/backups` behind `requireRole` / `requireTokenRole`; the pages' own errors `POST /api/client-errors` - `ErrorLogRoutes::client()`, public, behind `optionalSignIn()`), `api/routes/feedback.php` (`POST /api/feedback`, `optionalSignIn()`), `api/middleware/errors.php` (an exception into the error log), `api/middleware/upkeep.php` (the day's backup; `config/app.php` `upkeep.daily_backup`), `backup.php`; tests `tests/admin_tools.test.php`; frontend the family's pages `@anotoki/lib/admin/{errors,audit,feedback,backups}` on `/admin/...` (`provideAnotokiAdmin()` in `app.config.ts`; their frame from `styles.scss`), the count of new feedback beside Feedback in the admin panel's column (`FeedbackUnread`), the visitors' form `@anotoki/lib/feedback/form`, which the family's footer and the Contact page open only while a message can be sent (`shared/feedback.ts`: `provideFeedback()`, `feedbackOffered()`), the browser's errors reported by `provideAnotokiErrorReport()` (`@anotoki/lib/error-report`, the app's `ErrorHandler`, through the app's `HttpClient`). No file explorer here
- Visitors: `src/Auth/Visitors.php` (find / make / unmake / sweep / move; the only code that writes a visitor row, through `Users`), `requireOwner($app, mayMakeVisitor)` + `jsonFromNobody()` (nobody's import is JSON or 415) + `makeVisitor($app)` in `api/middleware/auth.php`, `api/routes/visitor.php` (`POST /api/visitor/move` `{silent}`: after the IAM's hint's silent hop never into an account with data here - `held`, the owner's decision of 2026-10-11), `src/Web/ClientAddress.php` (which address the limits count, by the library's rule `Anotoki\Lib\Support\ClientAddress` - the admin tools read by it too, over the same list: on a connection from Cloudflare's ranges - committed in `config/app.php`, the owner's decision of 2026-10-11, the library's `ClientAddress::CLOUDFLARE` - its `CF-Connecting-IP`, one address or none believed, and no other header, never a setting (`X-Real-IP`, `True-Client-IP`, `X-Forwarded-For` name nobody) - with the origin secret (`config/cloudflare.local.php`, the owner's decision of 2026-10-13: anybody's Cloudflare Worker can connect from those ranges) only on a request carrying it in `X-Anotoki-Origin`, any other from Cloudflare counted as its edge; the counters keep an HMAC of it, `countedKey()`, never the address; `config/client_address.local.php` overrides the ranges and nothing else), `src/Web/AfterResponse.php` (the clean-up after the answer), limits in `config/app.php` (`visitors`, `client_address`), `migrations/004_visitors_and_family_bar.sql`, `tests/visitors.test.php`; frontend `shared/visitor-key.ts` (`VisitorKey`: the key in localStorage, read at send time, the first one kept, forgotten on `visitor_unknown`, kept but not sent while sign-in is required, the move on sign-in - kept when `held`), `shared/sign-in-kind.ts` (`SignInKind`: whether the present sign-in was the hint's silent hop, from the auth module's client's callback - `ANOTOKI_AUTH_CLIENT_FACTORY` in `app.config.ts`), `shared/storage.ts`, `sites/main/common/visitor-notice/`, `sites/main/common/visitor-hero/`
- A person's own accounts as a file (signed in only; never a visitor's): `api/routes/me.php` (`GET /api/me/export`, `POST /api/me/import[?dry_run=1]`), `src/Services/Transfer/AccountsFile.php` (format, strict reading, writing only what it reads), `AccountsTransfer.php` (export; import: match by game + UID or game + `created_at` in UTC to the second, the newer `last_import_at` wins, never deletes, unknown catalog keys skipped and counted, the person's `users` row `FOR UPDATE` via `Users::lockForWrite()`, which `AccountImporter` takes too), limits `config/app.php` `me`, `tests/data_transfer.test.php`; frontend `sites/main/common/data-card/` (export, import: the dry run's preview, then Confirm), `shared/download.ts`
- Catalog / names: `src/Services/Catalog/Catalog.php` (lookups only - an import never adds a row; players' nicknames in `config/app.php`'s aliases), `CatalogSync.php` (`refresh()`: one list from its game's provider by a conditional request, written in one transaction - a row found again by its in-game ID, then its key, none deleted -, where each list stands kept in the cache table, one refresh of a list at a time), `CatalogProvider` / `CatalogAnswer` / `CatalogProviders` (`config/app.php` `catalog.{game}.provider`), `Providers/GenshinCatalog.php` (genshin.anotoki.eu's `/api/catalog/{characters,weapons,artifacts}`: the mapping, the Travelers one entry per element, icons on its host alone), `CatalogUpkeep.php` (a stale list refreshed after an answer; `upkeep.catalog`), `src/Presenters/Icons.php` (AVIF too), migration 018, `sync-catalog.php`; tests `tests/catalog_refresh.test.php`
- Imports: `src/Services/Import/ImportService.php` → parsers → `ParsedAccount` → `AccountImporter.php` (always for one user)
- Builds (variations imported from a file; docs/builds.md): `src/Services/Builds/BuildFile.php` (format version 3, strict reading, a build's identity, its teams - `teamsEntry()` writes them), `BuildImport.php` (the plan, the apply under an advisory lock - `BuildImportBusy` -, a dry run, the lists refreshed for what the catalog lacks, the teammates it lacks named; since 0.7 what editors did kept: a build's parts compared one by one with what the file said of it - `builds.file_build` - and with what is stored, a question per build only where both changed a part, the answers with the preview's plan - `?file=<ids>|all&plan=`, `BuildImportPlanChanged` 409 `plan_changed` -, builds made here kept, removed ones - `build_removals` - never back unasked, an editor's order kept, the site's notes never touched), `BuildParts.php` (a build's parts as an import compares them), `BuildNotes.php` (the site's notes, a team's by its members: read, shown, checked - English required, Slovak optional, no other site named), `BuildRows.php` (a build as the table stores it, what the file said beside it: the import's and the editor's writes), `BuildEditor.php` (the editor: `catalog()` - the facts and the catalog to pick from -, `overview()` - the Builds page's list -, `load()` and `save()` - a character's builds whole, each read by `BuildFile::readEntry()` with its problem at its path, under the import's lock, against the version loaded: `BuildEditException` 422, `BuildEditConflict` 409 `changed`), `src/Models/Build.php`, `src/Repositories/Builds.php` (`keysWithBuilds()`), `src/Presenters/BuildPresenter.php` (a team's members with what the catalog says of them, looked up as shown; the site's notes `{en, sk?}` on the build and each team, the file's unseen texts never sent), `api/routes/admin_builds.php` (STAFF: `/api/admin/builds`, `…/{game}/export`, `…/{game}/import[?dry_run=1]`, `…/{game}/catalog`, `…/{game}/characters/{key}` GET / PUT, `/api/admin/catalog/{game}/{list}`), `api/routes/builds.php` (public: `/api/builds`, `/api/builds/{game}`, `/api/builds/{game}/{key}`), `api/routes/accounts.php` (a character's `owned_teammates`, `Accounts::characterRanks()`), migrations 016-017, 020-021 (the teams), 022 (hand edits: `file_build`, `site_notes`, `edited_at`, `build_removals`, `build_orders`) and 023 (the editor's words), `src/Cache/Prune.php` (the day's tidying up after an answer; `upkeep.prune`); tests `tests/builds_import.test.php`, `tests/builds_editor.test.php`; frontend `sites/admin/builds/` (+ `build-file.ts`; the Edit builds card), `sites/admin/build-editor/` (the builds editor on `/admin/builds/:game/:key`: `build-editor-page`, `editor-draft.ts` - the page's state, the save, the checks -, `editor-catalogs.ts`, `editor-announcer.ts`, and its pieces `move-buttons/`, `note-fields/`, `part-card/` and the parts' forms `weapons-form/`, `sets-form/`, `ranked-list/` - main stats, substats, talents -, `targets-form/`, `teams-form/` - each team's note with it, the notes waiting for a team), `shared/build-editor-changes.ts` (its unsaved changes, for the site's one Sign out, `shared/sign-out.ts`), `shared/i18n/site-note.ts` (a note in the reader's language), `shared/i18n/variation-words.ts`, `sites/main/builds/` (the browser and its filters, `sites/main/common/query-filters.ts`), `sites/main/character/` (a tab per variation), `sites/main/common/build-teams` (a build's teams, the teammates the account has marked)
- Fetching: `src/Http/Fetcher.php` (each host's wait kept in the cache table; in a web request one retry at most) - the catalog's provider and the showcase service
- Analysis: `src/Services/Analysis/BuildEvaluator.php` (a build applies from its `min_rank`; `targetsFor()` by `from_rank`), `ItemScorer.php`, `SetMatcher.php`, `CharacterAnalyzer.php` (`best()`: among the builds that apply)
- Output: `src/Presenters/*` (mirrored in `frontend/src/app/api/models.ts`), `src/Support/Neutral.php`
- What the server says as a kind (the page words it): recommendations `BuildEvaluator::KINDS`; import errors and warnings `src/Services/Import/ImportKinds.php` (`ERRORS`, `WARNINGS`; `ImportException` `kind` / `params`, `ParsedAccount::warn()`)
- The site's words, backend (anotoki/lib's `Anotoki\Lib\Translations` on this site's tables): `migrations/003_languages_and_translations.sql` (tables, English + Slovak, every string), `006_strings_through_the_library.sql` (the language button's two strings moved to the library's keys, every rewording kept), `010_top_bar_words_through_the_library.sql` (the top bar's twelve likewise, when the family's bar took the site's place; two words only Material's stepper said retired), `013_footer_words_through_the_library.sql` (the footer's three likewise, `anotoki.footer.*`, when the family's footer took the site's place; What's new's copied, `whatsNew.title` staying the dialog's title), the library's set `vendor/anotoki/lib/backend/migrations/translations` (its words, `anotoki.*`), `src/Services/Translations/SiteTranslations.php` (what is the site's: `RELEASED` en + sk, the `extraCheck` `namesAnotherSite()`, `keyScanner()`; the container's `Translations`), `api/routes/translations.php` (`TranslationsRoutes::bundle` / `strings` / `languages` behind this site's checks, errors in its `{message, code}`), `translations.php` (→ `src/Commands/Translations.php`: the library's `TranslationsCommand`, the same name and flags)
- The site's words, frontend `shared/i18n/` (on `@anotoki/lib/translations`): `translation.ts` (the typed view of the library's `TranslationService` - the same token, the keys checked at compile time), `translations.config.ts` (`provideTranslations()`: the storage names as before, the compiled English as the fallback, the account, the admin pages' rights and routes), `en.ts` + `en/*.json` (the keys and the compiled English), pipes `translate` / `translatePlural` (typed) - dates are the kit's (`@anotoki/lib/ui/dates`: `anotokiDate`, `anotokiAgo`, `formatDateTime()`, in the page's locale with the account's date order and clock, `shared/kit.ts`'s `dateChoices`) -, `dynamic-keys.ts` (the run-time families), `error-text.ts`, `recommendation-words.ts`, `import-words.ts`, `title-strategy.ts` (`isAdminArea` from the library); specs use `@anotoki/lib/translations/testing`; game terms `sites/main/common/game-terms.ts`, `sites/main/common/build-terms.ts`; the backend test that holds it all together `tests/translation_catalogue.test.php`
- Translations and Languages pages (editors; the Languages page and adding / deleting a language: admins): the library's `<anotoki-translations-page>` / `<anotoki-languages-page>` (`@anotoki/lib/translations/admin`, a lazy chunk) in `sites/admin/translations/`, `sites/admin/languages/` (the admin panel's `.page`, `canLeave()` handed on), the library's `anotokiUnsavedChangesGuard` on their routes, `shared/localization-drafts.ts` (the sign-out question about drafts, loading the admin entry only when the tab holds drafts)
- Setup / deploy: `setup.php` + `src/Setup/Installer.php` (its files read as every settings file is: `passedOver($local)` names a broken one on this computer, none on a real host), the release by anotoki-deploy (`composer.json`'s `extra["anotoki-deploy"]`, `tools/check-release.mjs`; the `deploy-builder` skill), `tools/php.sh`
- The settings files: `src/Config.php` (`load()`, `readLocalFile()` - the first five bytes, then the print guard -, `originSecret()`, the once-a-day lines in PHP's error log), tests `tests/settings_files.test.php` (every way a file is broken, the attacks it stops: a password in a broken database file in no answer, a broken origin secret's file - or one naming no usable secret - failing closed, a broken proxies' file trusting none, PHP's own fatal error without the guard, setup naming a broken file on this computer only), `tests/visitors.test.php`
- The legal pages (anotoki-lib 0.11.0's `@anotoki/lib/legal`, `/legal/pages`; the About and Support pages `/legal/about`, `/legal/support`): `shared/legal.ts` (`provideLegal()` in `app.config.ts`: the page's language, the database's words, `LEGAL_PAGE_PATHS`), `sites/main/legal/legal-facts.ts` (`siteLegalFacts()`: every fact derived from the code, each named in its comment; `SITE_LEGAL_UPDATED`; `HOSTING`), `sites/main/legal/legal-layout/` (everybody's pages' frame with the facts, out of the first load), the routes `/about`, `/support`, `/terms`, `/privacy`, `/contact` in `sites/main/main.routes.ts` (spread into `app.routes.ts`; each a path of its own with the frame as its parent, no guard, before the `**`; `app.routes.spec.ts`; About and Support titled by the library's keys `anotoki.about.title`, `anotoki.support.title` - `shared/i18n/title-strategy.ts`), the words `shared/i18n/en/legal.json` + `migrations/011_legal_pages.sql` (`legal.*`, `title.terms|privacy|contact`; the footer's fan clause `footer.fanMade`, the library's `anotoki.footer.fanHoyoverse` since 013); the Contact page's form only while a message can be sent - its frame knows it frames Contact (`legalPagesConfig()`: `feedback: false` otherwise, the Privacy page saying what a message holds to everybody); specs `sites/main/legal/legal-facts.spec.ts` (the pages drawn for real, every link the code's), the backend's address check in `tests/translation_catalogue.test.php`
- The frames (the anotoki family's own): `sites/main/app-layout/` (everybody's pages: the announcement above `<anotoki-shell>`, `@anotoki/lib/shell`, with the bar, the administrators' notice, the page and the footer) and `sites/admin/app.ts` (the admin panel, `AdminApp`, `app-admin-root`, `lang="en"`: anotoki-lib 0.10.0's `<anotoki-admin-frame>`, `@anotoki/lib/admin/frame` - no top bar; on a computer a column on the left with the panel's name "Analyzer · admin panel" (`brand.short`, `brand.admin`, each side held together by no-break spaces), a link to `/admin`, who is signed in, "Back to Build Analyzer" (`topbar.backToApp`) to `/`, Sign out, the theme (`<anotoki-theme-toggle adminFrameTheme />`), the maintenance line while the site is closed (`<anotoki-page-notice adminFrameNotice />`) and the pages - `PANEL_GROUPS`, the family's one shape (anotoki-lib 0.11.0): Builds; Localization: Translations, Languages; System: Site settings, People, Feedback with the count of new feedback, Error log, Audit log, Backups, Migrations -, an editor's two and the ADMIN's ten - while an update waits the ADMIN's Migrations alone, `UPDATE_GROUPS` -, each with an icon of `shared/icons.ts`; on a phone, up to the library's 860 px, a row with the name and Menu and the column in a drawer; for somebody signed out or without a role a page of its own, the family's page frame with no bar: "You are signed out" / "This part is for editors and admins"); `shared/sign-out.ts` (`SignOut`: the site's one Sign out, the bar's and the admin frame's - the question about the builds editor's changes and the strings not saved); `sites/main/common/top-bar/` (the family's `<anotoki-top-bar>`, everybody's pages' alone, told this site's brand, its sections `APP_LINKS`, the person and their menu items, Sign in / Sign out; where it folds, measured with `run.mjs --measure`: `PHONE_BAR_MAX`; its menus, keys, theme switch and language switcher are the library's; the keys `brand.*`, `topbar.*` and the library's `anotoki.topbar.*`, `anotoki.theme.*` - migration 010), the family's footer in both frames (`<anotoki-site-footer>`, `@anotoki/lib/footer`, anotoki-lib 0.6.3 - the library's copy of this site's own, which went: the version, `changelog.current()`; What's new, this site's dialog on `(whatsNew)`; Feedback, the library's form in the kit's dialog, only while a message can be sent - `feedbackOffered()`; then About · Support · Terms · Privacy · Contact and the fan clause, `fan="hoyoverse"`; its words the library's `anotoki.footer.*`, given the site's own by migration 013), `shared/shell.ts` (`provideShell()`: the shell's `ThemeService` - the account's theme while signed in, saved from the theme switch (the bar's, the admin frame's); else this device's, `build-analyzer-theme`, read before the first paint by the kit's `theme-boot.js` in `index.html` - and the bar's languages); `app.ts` is the router inside the site gate, and what belongs to no page (`app.html` also holds the kit's `<anotoki-confirm-host>` and `<anotoki-toast-host>`); the browser check `.claude/skills/verify-ui/scripts/run.mjs` + `flow.mjs`, its database `tests/tools/browser-db.php`
- The look (the family's kit, anotoki-lib 0.11.0; no Angular Material, no CDK; dates `@anotoki/lib/ui/dates`, searching `@anotoki/lib/ui/search`): `src/styles.scss` (`@anotoki/lib/styles` - its tokens, Manrope, base, utilities, tables - with this site's palette over the family's `--anotoki-*` tokens, light and dark; the site's own meanings `--grade-*`, `--sev-*` (light and dark apart, `$site-colours`: each 4.5:1 on every ground of its theme, a grade's letter on its own tint too), `--rarity-*`, `--el-*`, an element's mark in a control `--el-mark-*` (3:1 on the control in both themes); its own pieces of look `.tile`, `.section-head`), `shared/kit.ts` (`provideKit()`: the kit's words through `forKit()` and the site's extra icons, `shared/icons.ts` - the family's own drawings, `@anotoki/lib/ui/icons`); the kit's pieces each from its own entry point (`@anotoki/lib/ui/button`, `/ui/dialog`, `/ui/tabs` …), never the `@anotoki/lib/ui` barrel. The kit's newer pieces (0.6.0) stand where the site drew its own: the import guide's row of steps is the kit's stepper (`ui/stepper`, named by `importWizard.steps`), its trouble list and paste box, a build's notes and the account's import history are the kit's disclosure (`ui/disclosure`; the history's table made only while open), the score bars and the catalog's refresh on the Builds page the kit's progress bar (`sites/main/common/score-bar`, `ui/progress`: the score as the page writes it, "n/a" heard too), a character on the account page and in the builds a link card (`ui/link-card`), a build's tab shows its score as the kit's badge (`character.tabScore`, the closest build marked), and the account's element filter - like the builds browser's filters of element, weapon type and rarity - is the kit's segmented control in a labelled group, each element's mark before its name (`ui/segmented`, `ui/labelled-group`) - migration `012_stepper_and_tab_words.sql`. What the kit has no piece for stays the site's: the grade badge, a tile with actions inside it or a piece of gear (`.tile`), the import guide's choice cards and file drop zone
- **The family's folders** (the genshin site's; the owner's decision of 2026-10-24, docs/decisions.md): `frontend/src/app/` holds `api/` (the API layer), `shared/` (what both halves use), `sites/main/` (everybody's pages - `main.routes.ts`, their frame `app-layout/`, the legal pages' frame and facts in `legal/`, what only they share in `common/`) and `sites/admin/` (the admin panel - `admin.routes.ts`, its frame `app.ts` - the family's admin frame -, a folder per page); `app.routes.ts` spreads `/admin`'s routes and then the main ones, no `loadChildren`. What only one half uses is in that half
- Frontend pages: `frontend/src/app/sites/main/*` and `frontend/src/app/sites/admin/*`; shared UI: `frontend/src/app/shared/*`, and everybody's pages' own `frontend/src/app/sites/main/common/*`; wizard guides: `sites/main/import/import-guides.ts` (keys; the English in `shared/i18n/en/import.json`); changelog: `frontend/public/changelog.json` (+ `backend/src/Version.php` and `frontend/package.json`'s `version`, all three the same MAJOR.MINOR.PATCH - `tests/auth.test.php` holds them together; What's new says only what the people using the site notice; `shared/changelog.ts` compares versions by their numbers, a missing part 0)

## Rules that matter

- Everything compares canonical keys; resolve names through `Catalog`, never by ad-hoc string matching.
- Game accounts are per user: every account / import query goes through `Accounts::owned()` / `findOwned()` or
  takes the owner; never look an account up by id or UID alone. The catalog and the builds are shared,
  and imports never create catalog rows.
- Who a request is comes only from the verified IAM token - never from a body, a parameter, an address or a
  header of the site's own - with one deliberate exception, read only by `requireOwner()` and only while the
  IAM does not require sign-in here: a visitor key the server made (`X-Visitor-Key`, its SHA-256 a visitor row's), which reaches that visitor's own accounts and
  imports and nothing else (no role, no admin panel, no own-data export / import). A request that sends an
  `Authorization: Bearer` header is that person or it is refused (401 `invalid_token`, public routes too): it
  is never answered as if it had sent none, and never falls back to the key (another scheme, `Basic`, is no
  credentials). The IAM's sign-in hint cookie opens nothing here: only the page reads it, to sign in. While the IAM requires sign-in, nobody and a visitor key get 401 `sign_in_required` from
  `optionalSignIn()` and `requireOwner()` - the key refused before it is looked up; only `/api/meta`, the
  strings, the icons, `GET /api/site-settings`, `/robots.txt`, `/sitemap.xml` (the legal pages alone then) and
  `/api/site-status` (no credentials read: a 401 there would read as "not available") stay open, and the move
  still takes a key with a token. A new route with no sign-in
  check must decide what it does then. The key is never logged, never in a
  URL or an answer's body (only the `X-Visitor-Key` response header of the import that made the visitor);
  only `App\Auth\Visitors` makes, finds, sweeps or moves a visitor row, and tests never assume a visitor's id. Role checks sit
  on route groups, so a route added later cannot go without one: `requireRole($app, ...Role::STAFF)` for the
  editors' routes (the builds' export and import, the catalog's refresh, the strings grid, the languages list),
  `requireRole($app, Role::ADMIN)` for the admins' own (adding / renaming /
  switching / deleting a language, People, the audit log, feedback's admin side, the site settings) - and for migrations, the error
  log and the backups `requireTokenRole($app, Role::ADMIN)`, the same answers from the token alone (no row of
  users read or made: they answer while an update waits; a backup's download is its single-use ticket's alone);
  own data `requireOwner($app)`; what only a signed-in person has
  (the own accounts file `/api/me/*`, the move) `requireSignIn($app)`; public routes `optionalSignIn($app)`.
  403 `editor_only` / `admin_only`. MEMBER (every account holds it) is an ordinary signed-in person, as is
  one with no role: no check needs it, none refuses it.
- The UI names no other website, service or tool (labels, links, credits, errors, tooltips, titles, the
  changelog): no build site appears at all (the builds arrive as a file, naming none), the API never sends a site's
  name or address, and text from elsewhere goes through `Neutral::text()` (its own list, and the build sites' names
  from the untracked names file). Game names are fine. The one exception is the legal pages, which name who else
  handles the data, as the law asks - the hosting company WebSupport s.r.o. (`HOSTING`, `sites/main/legal/legal-facts.ts`),
  Cloudflare and Umami (the library's words) - and still no build site, tool or the showcase service (described
  there, never named); so are the family's About and Support pages (anotoki-lib 0.8.0, the owner's words, the same on
  every site), which name and link the anotoki sites and the owner's Ko-fi page - the library's words and addresses,
  nothing of the site's. That covers every string in the database, seeded or edited,
  and a language's two names: `SiteTranslations::namesAnotherSite()` (the library's last check) refuses one that
  names another site or holds a web address (422 `names_another_site`).
- The repository is public and names no site the builds come from - not in a tracked file, a commit message, a
  branch or a PR (the owner's decision of 2026-10-20, docs/decisions.md). Their names are in the owner's untracked
  `backend/config/neutral.local.php` (`names`, which `Neutral` adds to its own, and `tree_words`), and
  `php backend/tools/check-names.php` reads the tracked tree (and what `git add -A` would add) for them - the
  owner's untracked `.git/hooks/pre-commit` and `commit-msg` run it on what is staged and on the message (`--staged`,
  `--message=<file>`). The tools that read the build sites live in the untracked `local/` folder (root
  `.gitignore`); nothing is copied from it into a tracked file.
- The site never reads a build site: the builds arrive as a file (docs/builds.md). The owner's local tools read the
  sites politely - robots.txt obeyed, each site's own wait, every page kept, samples never in the repository.
- No request may run long or sleep long: a builds import is one transaction under its lock (a file of 8 MB at most), a
  catalog refresh one list per request, the upkeep's work done after the answer. No queue jobs, schedules or
  background processes.
- Tests use small synthetic fixtures and never touch the network (`Tests\Support\FakeHttpClient` fails on any
  request it was not told about - the catalog's provider is faked at its addresses); API tests sign their own tokens (`iamToken()`,
  `Client::asIam()`; `tests/stand_in_iam.test.php` signs in through the stand-in IAM itself), and each test
  file uses its own throwaway database.
- Queries are parameterised; table and column names come from literals in the code, never from input.
- Schema changes are new files in `backend/migrations/`; never edit one that has been applied. Data-changing
  migrations end with a `DO $$` verification gate; a gate's running total of keys (`expected_keys`) counts this
  site's own only (`name NOT LIKE 'anotoki.%'`) - the library's set adds its words when it likes. A gate that
  compares lists compares them as sets, or sorts both sides `COLLATE "C"`: the type `name` (`pg_class.relname`)
  sorts byte by byte whatever the database's collation, and the live one's, en_US.utf8, skips the underscore -
  008's first gate refused its Apply there (`tests/migrations.test.php` applies it on such a collation). A waiting
  migration closes the site (the site gate): a route
  the gate leaves open (`siteOpenPaths()`) must answer on a database without that migration's tables - the
  Migrations page's ADMIN check reads the token alone (`requireTokenRole`); each open path is one route
  exactly, never a prefix (neither the gate nor the router resolves `..`); open nothing else without the
  owner's word.
- The site settings (anotoki-lib 0.11.0): while the site is closed for maintenance only the site's ADMIN passes the
  gate, known by a verified token alone (`siteGate()`'s `exempt`: RS256 and `typ` pinned, this IAM, this client - a
  forged, expired or other site's token is nobody), and the reserved API paths answer anybody; a reserved path
  (`SitePages::RESERVED_API_PATHS`) answers whatever a row says - adding one is the owner's decision, never a
  refactor's. Page access runs in every route group after its own check (401 / 403 first), so a row never opens what
  the group's check refuses; the browser's guard only hides (the server is the rule). The sitemap never lists what a
  row hides, and robots.txt and the sitemap name the site by its configured address, never a request's Host.
- The anotoki family's shared code comes from anotoki-lib (Composer `anotoki/lib`, npm `@anotoki/lib`), installed
  from GitHub only; a change it needs is made there, released, and taken here by updating both halves together.
- Angular components always have three files: `.ts`, `.html`, `.scss`.
- The UI is the family's kit (the owner's rule of 2026-10-07: one library palette): a piece the kit has is
  taken from its own entry point, never copied and never re-styled; a colour is a `--anotoki-*` token (the
  site's palette is set over them in `styles.scss`, light and dark) or one of the site's own meanings; text
  reaches 4.5:1 and a control's edge or focus ring 3:1 in both themes. What the kit lacks stays the site's,
  drawn from the tokens - and is worth asking the library for. No Angular Material or CDK. The kit's utilities
  are global here, with no prefix (`stack`, `cluster`, `facts`, `page`, `muted`, `small`, `status-dot`, ... -
  `@anotoki/lib/styles`, `_utilities.scss`): a class of the site's own never takes one of those names for
  something else (a `facts` of its own once stacked the character page's traits).
- Signing in, people and the admin tools are the anotoki library's (`Anotoki\Lib\Auth`, `People`, `ErrorLog`,
  `Audit`, `Feedback`, `Backups`, `Support\ClientAddress`; `@anotoki/lib/auth`, `/people`, `/admin`, `/feedback`,
  `/error-report`): never a copy here; a change they need goes to the library. The IAM's SDKs are not vendored
  here any more. A hook the library calls (the People page's delete, the scrub's) works on the `$pdo` it is
  given and begins no transaction of its own: a restore runs them on its own connection, its locks held.
- Feedback is offered only while a message can be sent (`feedbackOffered()`, `shared/feedback.ts`; the survey's
  finding for every site, 2026-10-18): since anotoki-lib 0.7.2 the library's form says its message is anybody's
  (`issuedAs('anybody')`) - the person's with their token while somebody is signed in, the visitor's while the page
  is shown to one (`AuthService.visiting`), nobody's otherwise - and the server (`POST /api/feedback`,
  `optionalSignIn()`) takes a message from anybody only while the IAM lets visitors in here, from a person signed in
  only while it requires a sign-in. So the footer's Feedback and the Contact page's form are there for a person
  signed in or a visitor, and only while the site is ready (an update waiting answers 503) - never for somebody
  signed out while sign-in is required (a legal page, the sign-in failure's screen, the admin panel's "You are signed
  out"), whose message the server would refuse (401 `sign_in_required`: the form says "Sign in to send a message").
  The Privacy page says what a message holds to everybody: only the Contact page's frame leaves the form out.
- What a member reads is a key, worded by the page in the reader's language: never a sentence written into a
  template or a component, and never one kept in state (state holds keys, kinds and errors; a template or a
  `computed()` words them). Recommendations, import errors and import warnings are **kinds with params** that
  the page words (`recommendation.<kind>`, `import.error.<code>`, `import.warning.<code>`); their English
  `message` stays short and actionable, kept for the command line and for rows stored before kinds - a row
  without a known kind shows that English with `lang="en"`. A new string is its key and English in
  `shared/i18n/en/*.json` plus a new migration with the key, its description, its English and its Slovak (the
  catalogue test holds the two to each other). English is the fallback; a key the database lacks shows as
  the key, on purpose. The namespace `anotoki.` is the library's: its words come with its own migrations (its
  set runs after this site's), and this site never adds a key there; an owner's rewording of one is kept, as
  any other. To word one of them its own way, a migration of this site writes it first (`INSERT … ON CONFLICT
  DO NOTHING`, then `UPDATE … WHERE value = '<the library's words>'`), so no rewording is written over.
- Slovak here is informal ("ty"). Game terms and game values are never translated: stat names, slot, talent,
  set, weapon and character names, "Lv.", "4pc", UID, grades, and the game's notation ("62.4%", "R1"). They
  reach a sentence as a `{placeholder}` or stand before a colon; no Slovak word has to agree with one.
- English pages: the admin panel, `/admin/...` (Builds, Translations, Languages, Site settings, People, Feedback,
  Error log, Audit log, Backups, Migrations), keeps its English
  code and reads English with `<html lang="en">` whatever language is chosen; the server's admin-area sentences
  stay English. Every other page is open to everybody and reads in the reader's language.
- The account's theme, dates and language (the owner's decision of 2026-10-06, the IAM's migration 015): while
  `AuthService.preferences()` is not null, the theme on every page (the admin panel's too), the date order and
  clock, and the language before the device's remembered choice are the account's, and the bar's theme switch
  and language menu save a choice to it - one save at a time, the latest last. A visitor's, and those of
  anybody at an IAM before 015, stay the device's, and nothing is saved then. A date or time written for a
  person goes through the kit's dates (`@anotoki/lib/ui/dates`: the `anotokiDate` and `anotokiAgo` pipes,
  `formatDateTime()` - the account's choices from `shared/kit.ts`), never a format of its own.
- Never commit or print secrets: `backend/config/*.local.php`, database credentials, tokens,
  player cookies. Don't edit the user's `php.ini` or global tools without asking. A settings file is read through
  `Config::readLocalFile()` alone, never a plain `require` (the family's rule: nothing it prints is ever sent, and
  one that does not start with `<?php` is never compiled); one passed over is never read looser than no file.
- The legal pages' facts are the code's (`sites/main/legal/legal-facts.ts`, each fact named with the code it rests on): a
  change to what the site keeps, what for or how long, its cookies and stored items, or who else handles data
  changes their `legal.*` strings (by a migration) and `SITE_LEGAL_UPDATED` in the same change. Every address the
  pages link to is the code's - the IAM's pages from `/api/meta`'s `auth`, the hosting company's `HOSTING` -, never
  a string an administrator can reword.
- Done means: backend tests pass (`php tests/run.php`), `ng build` + `ng test` pass, `bun run format:check` passes at the root, `php backend/tools/check-names.php` finds nothing, UI checked with `verify-ui` (its run.mjs passes, and the screenshots are looked at)
  when it changed (a member page in English and in Slovak at 390 px), docs updated.
