# Hoyo Analyzer

Import your **Genshin Impact** and **Honkai: Star Rail** accounts, collect recommended builds from community
sites once a day, and see per character what to change: set bonuses, main stats, substats, weapon / light cone,
stat targets and better pieces you already own.

- **Backend:** PHP 8.4+ / Laravel 13, SQLite (PostgreSQL-ready), in `backend/`
- **Frontend:** Angular 21 + Angular Material, in `frontend/`

## Features

- **Guided import wizard** (game → method → step-by-step preparation → import → result). Data is read
  automatically, either:
  - by **UID** through [Enka.Network](https://enka.network): the characters in your in-game Character Showcase, or
  - from a **full inventory export** written by a tool on your PC:
    [Irminsul](https://github.com/konkers/irminsul) (Genshin, GOOD format) or
    [Reliquary Archiver](https://github.com/IceDynamix/reliquary-archiver) / HSR-Scanner (Star Rail).
- **Daily build sync** from genshin-builds.com, Prydwen and genshin.gg, with every build variant a site lists
  (e.g. Raiden "DPS & Energy Support" and "Hyperbloom").
- **Analysis per character and per build**: a 0–100 score and grade, prioritized recommendations, your pieces
  graded against the build, stat targets checked against your in-game totals, where the sources agree, and
  (with a full inventory) the best replacement you already own for each slot.

## Quick start (Windows)

Requirements: PHP 8.4+ with `pdo_sqlite`, `curl`, `mbstring`, `openssl`, `fileinfo` (and `zip` for faster
Composer installs), Node.js 22.12+ with npm, and internet access.

```powershell
.\dev.ps1 setup   # Composer + npm packages, .env, SQLite database, game catalog
.\dev.ps1 sync    # first build download (~30 minutes: Prydwen asks for 10 s between pages)
.\dev.ps1 start   # API :8000, queue worker, scheduler and the app at http://localhost:4200
```

If `php` on your PATH is older than 8.4, create `dev.local.ps1` (ignored by git) containing
`$env:HOYO_PHP = 'C:\path\to\php.exe'`. Other commands: `.\dev.ps1 test`, `.\dev.ps1 build` (serves the app
from Laravel at http://127.0.0.1:8000/app/), `.\dev.ps1 install-task` (daily sync as a Windows scheduled task).

### Manual setup (any OS)

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan catalog:sync               # characters, weapons, sets, icons (from yatta.moe)
php artisan serve                      # API on http://127.0.0.1:8000
php artisan queue:work --timeout=3300  # runs "Sync now" from the Sources page
php artisan schedule:work              # daily sync (or cron: php artisan schedule:run every minute)

cd ../frontend
npm ci                                 # npm 10.9.x has a resolver bug here; `npx npm@11 ci` works
npx ng serve                           # http://localhost:4200, proxies /api to the backend
```

## Importing your data

| Method | Genshin Impact | Honkai: Star Rail | You get |
| --- | --- | --- | --- |
| UID (Enka.Network) | up to 12 showcase characters | showcase + support characters | equipped gear, exact roll counts, in-game totals (Genshin) |
| Exporter file | GOOD file from Irminsul (also Inventory Kamera, GOODScanner) | Reliquary Archiver or HSR-Scanner export | every character, weapon and artifact / relic |

Both can be combined on one account: import by UID for in-game totals, then upload a file for the full
inventory. The wizard shows the exact in-game steps and tool instructions. From the command line:

```bash
php artisan accounts:import genshin 700123456                       # UID via Enka.Network
php artisan accounts:import hsr path/to/archive_output.json --account=3
```

## Build sources

| Source | Games | How it is read | Why |
| --- | --- | --- | --- |
| [genshin-builds.com](https://genshin-builds.com/en/characters) | Genshin | JSON props embedded in the page (Astro islands) | every released character, several builds each, refinements, ordered substats and talents; robots.txt welcomes bots |
| [Prydwen](https://www.prydwen.gg) | Genshin, HSR | Next.js page payload | curated builds with ties (`=` / `>`), Energy Recharge tables, HSR endgame stat targets and trace priority, update dates. Genshin characters without a curated build get a **"Most used"** build from Prydwen's usage statistics. Its 10 s crawl delay is respected. |
| [genshin.gg](https://genshin.gg) | Genshin, HSR | one JS bundle holding all characters | a cheap third opinion (one build per character) |

Not used:
- **game8** writes builds as HTML tables by hand, and the layout differs between pages. It also blocks non-browser clients.
- **KeqingMains** guides are free text, and many are several game versions old.
- **genshin-builds.com for Star Rail** mirrors game8's data.

To add a source, implement `App\Services\Builds\BuildSource` and list the class in `config/hoyo.php`.

## Daily sync

- `php artisan builds:sync` scrapes every enabled source. A source synced successfully in the last 20 hours
  (`HOYO_SYNC_STALE_HOURS`) is skipped, so extra runs never hit a site twice a day. Options: `--force`,
  `--character=hu_tao` (repeatable), `--limit=5`, `--game=hsr`, `--check-names` (lists each site's characters
  and reports names that do not match the game catalog), and `--dry-run` (parses and compares with the stored
  builds without writing; add `HOYO_HTTP_CACHE_HOURS=96` to replay cached pages offline).
- The Laravel scheduler runs `catalog:sync` at 04:00 and `builds:sync` at 04:30 (`HOYO_SYNC_DAILY_AT`), in
  `HOYO_SYNC_TIMEZONE` (default UTC).
  Without keeping anything running, `.\dev.ps1 install-task` registers a Windows task that also catches up after
  the PC was off.
- Requests identify themselves, wait between requests to the same site, retry 429/5xx responses and cache pages for 12 hours.
- The **Sources** page shows the status, build counts and logs of every run, and can start a sync (queued).

## How the analysis works

Every owned character is compared with every build of every source. Each part is scored from 0 to 100:

| Part | Scoring |
| --- | --- |
| Weapon / light cone | #1 on the list = 100, −8 per rank, unlisted = 40; better options you own are named |
| Set bonuses | first satisfied option (4pc, 2+2, "any two" 2pc groups, 2pc stat groups); HSR: relics 60 %, ornaments 40 % |
| Main stats | per variable slot: first choice 100, alternatives 85 / 75, anything else 0 |
| Substats | rolls (value ÷ max roll) weighted by the build's priority order (1.0 / 0.8 / 0.6 / 0.45 / 0.3; flat stats about 0.35–0.4 of their % version), compared with an ideal piece (all upgrades in the best stat). 70 % of ideal already counts as full marks. |
| Stat targets | in-game totals (Genshin via Enka) against ER requirements, CRIT goals, HSR endgame stats |

The overall score weights the parts 20 / 25 / 20 / 25 / 10 (parts without data are skipped).
Grades are S ≥ 90, A ≥ 80, B ≥ 65 and C ≥ 50. The best-matching build is starred.

With a full inventory, the app suggests the best replacement you own for each slot. A suggestion never makes your set bonus worse.

## Configuration

`backend/.env`: the usual Laravel settings plus `HOYO_SYNC_DAILY_AT`, `HOYO_SYNC_STALE_HOURS`,
`HOYO_HTTP_DELAY_MS`, `HOYO_HTTP_CACHE_HOURS`, `HOYO_USER_AGENT`. Name aliases (nicknames, abbreviations,
site typos) live in `config/hoyo.php`.

## Switching to PostgreSQL

Enable `pdo_pgsql` in `php.ini`. Then set the following in `backend/.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hoyo_analyzer
DB_USERNAME=hoyo
DB_PASSWORD=secret
```

Then run `php artisan migrate && php artisan catalog:sync`. The schema only uses portable column types; build
details and substats are JSON columns. To move existing data, import the accounts again and run `builds:sync`.
Both are quick with cached pages.

## Project layout

```text
backend/
  app/Enums/Game.php                slots, main stat options, labels per game
  app/Support/                      stat keys and label parsing, talents, name keys, main stat values
  app/Services/Catalog/             game catalog (yatta.moe) and name resolution (aliases, fuzzy matching)
  app/Services/Import/              Enka client + parsers, GOOD and HSR scanner parsers, AccountImporter
  app/Services/Builds/              BuildSync, the scrapers (Sources/) and page-data parsers (Parsing/)
  app/Services/Analysis/            BuildEvaluator (scores and recommendations), ItemScorer, SetMatcher, CharacterAnalyzer
  app/Http/Controllers/Api/         REST API used by the frontend (routes/api.php)
  app/Console/Commands/             catalog:sync, builds:sync, accounts:import
frontend/src/app/
  core/                             API client, models, metadata store
  shared/                           build detail, item card, grade badge, ...
  pages/                            dashboard, import wizard, account, character analysis, builds, sources
docs/                               knowledge base (start at docs/README.md)
.claude/skills/, .claude/agents/    project skills and agents for AI-assisted work
scripts/                            php.sh / artisan.sh helpers (bash)
```

## Tests

```powershell
.\dev.ps1 test    # php artisan test (PHPUnit) + ng test (Vitest)
```

## Working on the project with AI

- [CLAUDE.md](CLAUDE.md) is loaded automatically by Claude Code and routes each kind of task to the right
  documentation, skill and agent. [AGENTS.md](AGENTS.md) points other AI tools to it.
- [docs/](docs/README.md) is the knowledge base: architecture, data model, stats and slots, name
  matching, build sources, import formats, analysis, operations and decisions.
- Project skills (`.claude/skills/`) cover these tasks:
  - `add-build-source`: add or rewrite a scraper.
  - `debug-build-sync`: fix failed syncs and unknown names.
  - `add-import-format`: add or fix an importer.
  - `tune-analysis`: change scoring and recommendations.
  - `verify-ui`: take headless Edge screenshots of the app.
- Project agents (`.claude/agents/`):
  - `build-source-researcher`: finds how a site stores its builds.
  - `sync-doctor`: diagnoses sync problems.
  - `analysis-auditor`: explains or audits scores.
  - `hoyo-reviewer`: reviews a finished change.
- Bash helpers `scripts/php.sh` and `scripts/artisan.sh` always use PHP 8.4+ (from `HOYO_PHP` in `dev.local.ps1`).

## Notes

- Builds come from third-party websites, and the scrapers read their current page structure. When a site
  changes its layout, its sync reports errors on the Sources page, and that parser needs an update.
- This is a personal tool. Enka.Network, yatta.moe and the build sites are community projects, so keep the
  default request delays.
- Not implemented yet:
  - HoYoLAB import. It would return every character without a tool, but needs your login cookies.
  - User accounts. The app is single-user and meant to run locally.
