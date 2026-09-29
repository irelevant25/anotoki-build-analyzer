# Build Analyzer

Import your **Genshin Impact** and **Honkai: Star Rail** accounts, collect recommended builds from community
build sites, and see per character what to change: set bonuses, main stats, substats, weapon / light cone,
stat targets and better pieces you already own. Hosted at builds.anotoki.eu (sign-in through the anotoki IAM,
each person with their own accounts), or on your own computer without sign-in.

- **Backend:** PHP 8.4+, Slim 4, PostgreSQL, in `backend/`
- **Frontend:** Angular 21 + Angular Material, in `frontend/`

Setting it up, running, testing, building and deploying: **[DEVELOPING.md](DEVELOPING.md)**.

## Features

- **Guided import wizard** (game → method → step-by-step preparation → import → result). Data is read either:
  - by **UID**: the characters in your in-game Character Showcase, through a public showcase service, or
  - from a **full inventory export** written by a tool on your PC: a GOOD file (Genshin) or a relic
    scanner's JSON export (Star Rail).
- **Build sync** from three community build sites, with every build variant a site lists (e.g. Raiden "DPS &
  Energy Support" and "Hyperbloom"), started by an admin on the Sources page and run in short steps from the
  browser: no queue worker, scheduler or long-running process. The app shows the sources as "Source 1",
  "Source 2" …; it names no other site anywhere in its UI.
- **Sign-in through the anotoki IAM** (iam.anotoki.eu): members import accounts and see their analysis, admins
  also run the syncs. Everyone's game accounts are their own.
- **Analysis per character and per build**: a 0–100 score and grade, prioritized recommendations, your pieces
  graded against the build, stat targets checked against your in-game totals, where the sources agree, and
  (with a full inventory) the best replacement you already own for each slot.
- **What's new**: the version in the top bar opens the changelog (`frontend/public/changelog.json`).

## Quick start (Windows)

Requirements: PHP 8.4+ (`pdo_pgsql`, `openssl`, `mbstring`, `curl`, `dom`), PostgreSQL, Node.js 22.12+.

```powershell
.\dev.ps1 setup   # Composer + npm packages
.\dev.ps1 start   # API on :8400 and the app at http://localhost:4600
```

The app then leads you to `setup.php`: the database, sign-in (the anotoki IAM, or switched off for a copy of
your own) and the owner's user id. Then **Sources → Refresh catalog → Sync all**, and import an account.
Other commands: `.\dev.ps1 test`, `.\dev.ps1 sync`, `.\dev.ps1 migrate`, `.\dev.ps1 build`, `.\dev.ps1 dist`
(what to upload to a host). Other systems: the same steps by hand, in [DEVELOPING.md](DEVELOPING.md).

## Importing your data

| Method | Genshin Impact | Honkai: Star Rail | You get |
| --- | --- | --- | --- |
| UID (Character Showcase) | up to 12 showcase characters | showcase + support characters | equipped gear, exact roll counts, in-game totals (Genshin) |
| Exporter file | GOOD file | relic scanner export (JSON) | every character, weapon and artifact / relic |

Both can be combined on one account: import by UID for in-game totals, then upload a file for the full
inventory. The wizard shows the in-game steps. Accounts belong to the person who imported them (two people can
import the same UID, each into their own account). From the command line (the owner is `local_user_id` of
`config/auth.local.php` unless `--user` names another IAM user id):

```bash
cd backend
php import-account.php genshin 700123456                       # the Character Showcase of a UID
php import-account.php hsr path/to/export.json --account=3
php import-account.php genshin 700123456 --user=5              # for IAM user 5
```

A name the game catalog does not know yet (a brand-new character or set) is skipped and listed in the import
result; after an admin refreshes the catalog, import again. The formats and the services behind them are
described in [docs/import-formats.md](docs/import-formats.md).

## Syncing builds

- An admin syncs on the **Sources** page: **Sync all** (every enabled source not synced successfully in the last
  20 hours, so extra clicks never hit a site twice a day), **Sync now** on one source, and **Refresh catalog**.
  A stale catalog is refreshed first automatically.
- A sync runs in short steps while the page is open: each step is one request that fetches a page or two,
  respects the site's crawl delay and saves its progress. Closing the tab pauses it; Resume continues where it
  stopped. Nothing is scheduled.
- `php sync-builds.php` (in `backend/`) does the same from a shell. Options: `--force`, `--character=hu_tao`
  (repeatable), `--limit=5`, `--game=hsr`, `--check-names` (lists each site's characters and reports names that
  do not match the game catalog), and `--dry-run` (parses and compares with the stored builds without writing;
  add `--cache-hours=96` to replay pages fetched in the last days).
- Requests identify themselves, wait between requests to the same site, retry 429/5xx responses and cache pages
  for 12 hours. The sites and how each is read: [docs/build-sources.md](docs/build-sources.md).

## How the analysis works

Every owned character is compared with every build of every source. Each part is scored from 0 to 100:

| Part | Scoring |
| --- | --- |
| Weapon / light cone | #1 on the list = 100, −8 per rank, unlisted = 40; better options you own are named |
| Set bonuses | first satisfied option (4pc, 2+2, "any two" 2pc groups, 2pc stat groups); HSR: relics 60 %, ornaments 40 % |
| Main stats | per variable slot: first choice 100, alternatives 85 / 75, anything else 0 |
| Substats | rolls (value ÷ max roll) weighted by the build's priority order (1.0 / 0.8 / 0.6 / 0.45 / 0.3; flat stats about 0.35–0.4 of their % version), compared with an ideal piece (all upgrades in the best stat). 70 % of ideal already counts as full marks. |
| Stat targets | in-game totals (Genshin showcase imports) against ER requirements, CRIT goals, HSR endgame stats |

The overall score weights the parts 20 / 25 / 20 / 25 / 10 (parts without data are skipped). Grades are
S ≥ 90, A ≥ 80, B ≥ 65 and C ≥ 50. The best-matching build is starred. With a full inventory, the app suggests
the best replacement you own for each slot; a suggestion never makes your set bonus worse. Details:
[docs/analysis.md](docs/analysis.md).

## Sign-in and roles

Hosted, people sign in at the **anotoki IAM** (iam.anotoki.eu, OpenID Connect); this app is its client
`build-analyzer`. Only accounts with a role here can use it: **MEMBER** imports accounts and sees their
analysis, **ADMIN** also syncs builds and the catalog, switches sources on and off and applies database
updates. Roles are granted in the IAM's admin console. The backend checks every access token itself (signature
against the IAM's published keys, issuer, audience, expiry). On your own computer sign-in can be switched off;
the app then answers only its own pages on that computer. See [docs/operations.md](docs/operations.md).

## Deploying

The host needs only Apache, PHP 8.4 and PostgreSQL, with files uploaded by FTP: no shell, cron or worker.
`.\dev.ps1 dist` assembles `dist/` (the whole web root, with `vendor/` and the built frontend, and no
configuration or tests); `setup.php` writes the host's configuration and builds the database; later database
updates are applied from the Sources page. The steps and the upload map: [DEVELOPING.md](DEVELOPING.md)
("Deploying").

## Project layout

```text
backend/
  api/                  the front controller (index.php), app.php, middleware/, routes/
  src/                  App (container), Config, Db/ (PDO + migration engine), Auth/, Http/ (fetcher),
                        Cache/, Models/, Repositories/, Presenters/, Commands/, Setup/, Support/ (stats,
                        talents, keys, neutral text; Iam/ = the IAM's token verifier, vendored)
  src/Services/         Catalog/ (game catalog, name resolution), Import/ (showcase + file parsers,
                        AccountImporter), Builds/ (BuildSync, the scrapers, page-data parsers), Sync/
                        (SyncRunner: runs worked through in steps), Analysis/ (scores, recommendations)
  config/               app.php (committed) and *.local.php (per installation, written by setup.php)
  migrations/           the schema, as SQL files applied in order
  tests/                php tests/run.php: throwaway PostgreSQL databases, fixtures, a fake network
  setup.php, router.php, migration.php, sync-builds.php, sync-catalog.php, import-account.php
frontend/src/app/
  core/                 API client, models, metadata store, changelog, sign-in (iam/ = the IAM's browser SDK), sync driver
  shared/               build detail, item card, grade badge, changelog dialog, ...
  pages/                dashboard, import wizard, account, character analysis, builds, sources, sign-in, not set up
frontend/public/        changelog.json, favicon
docs/                   knowledge base (start at docs/README.md)
.claude/                project skills and agents for AI-assisted work
scripts/                build-dist.php (the upload), php.sh (PHP 8.4+ for bash)
```

## Working on the project with AI

- [CLAUDE.md](CLAUDE.md) is loaded automatically by Claude Code and routes each kind of task to the right
  documentation, skill and agent. [AGENTS.md](AGENTS.md) points other AI tools to it.
- [docs/](docs/README.md) is the knowledge base: architecture, data model, stats and slots, name matching,
  build sources, import formats, analysis, operations and decisions.
- Project skills (`.claude/skills/`): `add-build-source`, `debug-build-sync`, `add-import-format`,
  `tune-analysis`, `verify-ui`. Project agents (`.claude/agents/`): `build-source-researcher`, `sync-doctor`,
  `analysis-auditor`, `build-analyzer-reviewer`.

## Notes

- Builds come from other websites, and the scrapers read their current page structure. When a site changes its
  layout, its sync reports errors on the Sources page, and that parser needs an update.
- This is a small community tool, and the sites and services it reads are community projects: keep the default
  request delays.
- Not implemented yet: an import through the game's official web account. It would return every character
  without a tool, but needs your login cookies.
