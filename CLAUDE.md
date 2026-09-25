# Hoyo Analyzer

Imports Genshin Impact / Honkai: Star Rail accounts (Enka.Network by UID, GOOD and Reliquary Archiver /
HSR-Scanner files), scrapes recommended builds daily (genshin-builds.com, Prydwen, genshin.gg) and shows per
character what to improve. Backend: Laravel 13 / PHP 8.4+ in `backend/` (SQLite, PostgreSQL-ready).
Frontend: Angular 21 + Angular Material in `frontend/`. Single-user, runs locally.

## How to work in this repo

1. **Read the knowledge base page for the area you touch** before changing it — `docs/README.md` is the index:
   architecture, data model, stats & slots, name matching, build sources, import formats, analysis,
   operations (commands, machine quirks, troubleshooting), decisions.
2. **Use the project skills** for these tasks (invoke them with the Skill tool):

   | Task | Skill |
   | --- | --- |
   | add or rewrite a build website scraper | `add-build-source` |
   | sync failed/partial, "! unknown weapon/set", wrong or missing builds, site redesign | `debug-build-sync` |
   | new import format / importer fix / HoYoLAB import | `add-import-format` |
   | scores, grades, recommendations, inventory suggestions | `tune-analysis` |
   | any UI change or "look at the app" | `verify-ui` |

3. **Delegate to the project agents** when it saves context or runs in parallel:
   `build-source-researcher` (how a site stores its builds), `sync-doctor` (diagnose sync problems, proposes
   fixes), `analysis-auditor` (explain / audit scores, read-only), `hoyo-reviewer` (review a finished change).
4. **Keep docs current**: when behavior described in `docs/` or the README changes, update it in the same change;
   record notable choices in `docs/decisions.md`.

## Environment (Windows machine; details in docs/operations.md)

- `php` on PATH is PHP 7.4 — too old. Run PHP only via `scripts/php.sh …` and artisan via
  `scripts/artisan.sh …` (bash) or `.\dev.ps1 …` (PowerShell). They read `HOYO_PHP` from the gitignored
  `dev.local.ps1`.
- Composer: `cd backend && ../scripts/php.sh ../tools/composer.phar …`.
- npm 10.9.2 crashes on install here → `npx -y npm@11 install|ci`. Node 22.15 (hence Angular 21).
- No Python (`python` is the Store stub); script with node or PHP.
- Tool shells reset their working directory: start commands with `cd <repo root> &&`. Print tinker data with
  `json_encode(…, JSON_PRETTY_PRINT)`; put long tinker code in a scratch file (`--execute="$(cat file)"`).
- Database times are UTC; the schedule uses `HOYO_SYNC_TIMEZONE` (local `.env`: Europe/Bratislava).
- Git Bash: heredocs with apostrophes can fail to parse — create files with the editor tools.
- Git repository on `main` with a GitHub remote (`origin`); commit or push only when the user asks.

## Commands

```bash
scripts/artisan.sh test                                   # backend tests (PHPUnit, ~70 tests)
(cd backend && ../scripts/php.sh vendor/bin/pint)         # PHP style (--test to check only)
(cd frontend && npx ng build && npx ng test --watch=false)
scripts/artisan.sh builds:sync [source] [--character=slug] [--force] [--check-names]
HOYO_HTTP_CACHE_HOURS=96 scripts/artisan.sh builds:sync --dry-run   # replay cached pages vs stored builds, writes nothing
scripts/artisan.sh catalog:sync [genshin|hsr] [--force]
scripts/artisan.sh accounts:import <genshin|hsr> <uid|file> [--account=ID]
scripts/artisan.sh tinker --execute='…'                   # inspect data
.\dev.ps1 start                                           # API :8000 + queue + scheduler + Angular :4200
```

## Code map

- Stats & slots: `backend/app/Support/Stats.php`, `Enums/Game.php`, `Support/Talents.php` (canonical keys: `critRate_`, `atk_`, `spd`, `pyro_dmg_`…)
- Catalog / names: `Services/Catalog/Catalog.php` (+ aliases in `config/hoyo.php`), `CatalogSync.php` (yatta.moe)
- Imports: `Services/Import/ImportService.php` → parsers → `ParsedAccount` → `AccountImporter.php`
- Scrapers: `Services/Builds/Sources/*` (+ `Parsing/AstroIslands`, `NextFlight`, `StatTargets`), `BuildSync.php`
- Analysis: `Services/Analysis/BuildEvaluator.php`, `ItemScorer.php`, `SetMatcher.php`, `CharacterAnalyzer.php`
- API: `routes/api.php`, `Http/Controllers/Api/*`; frontend mirror in `frontend/src/app/core/models.ts`
- Frontend pages: `frontend/src/app/pages/*`; shared UI: `frontend/src/app/shared/*`; wizard texts: `pages/import/import-guides.ts`

## Rules that matter

- Everything compares canonical keys; resolve names through `Catalog`, never by ad-hoc string matching.
- Scrapers go through `Fetcher`, respect robots.txt / crawl delays (Prydwen 10 s) and are developed against
  cached or saved pages, not by repeatedly hitting live sites. Samples go outside the repo.
- Tests use small synthetic fixtures and never touch the network (`Http::fake`, fake `Fetcher` / `EnkaStore`).
- User-facing error messages (`ImportException`, recommendations) are short, actionable English.
- Never commit or print secrets: `backend/.env`, `dev.local.ps1`, database credentials, player cookies.
  Don't edit the user's `php.ini` or global tools without asking.
- Done means: backend tests + Pint pass, `ng build` + `ng test` pass, UI checked with `verify-ui` when it
  changed, docs updated.
