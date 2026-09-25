# Architecture

Hoyo Analyzer imports Genshin Impact / Honkai: Star Rail accounts, scrapes recommended builds once a day and
compares every owned character with every build.

```text
yatta.moe ──catalog:sync──▶ game_characters / game_weapons / game_sets   (names, IDs, rarity, element, icons)
                                   ▲ name/ID resolution (Catalog)
Enka.Network / GOOD / HSR files ──ImportService──▶ accounts, account_characters, account_weapons, account_items
genshin-builds / Prydwen / genshin.gg ──BuildSync──▶ builds (+ build_sources, sync_runs)
                                   ▼
                     CharacterAnalyzer ──▶ REST API (/api) ──▶ Angular app
```

## Backend (`backend/`, Laravel 13, PHP 8.4+)

| Area | Key classes | Notes |
| --- | --- | --- |
| Domain basics | `Enums/Game`, `Support/Stats`, `Support/Talents`, `Support/Key`, `Support/MainStatValues` | slots, stat keys, label parsing; see [stats-and-slots.md](stats-and-slots.md) |
| Outgoing HTTP | `Services/Http/Fetcher` (singleton) | per-host delay, retries on 429/5xx, optional disk cache, browser UA option |
| Catalog | `Services/Catalog/Catalog` (scoped), `CatalogSync`, `SetBonusGroups` | resolve any source's names/IDs to catalog rows; see [name-matching.md](name-matching.md) |
| Imports | `Services/Import/ImportService` → parsers → `ParsedAccount` → `AccountImporter` | see [import-formats.md](import-formats.md) |
| Builds | `Services/Builds/BuildSync`, `BuildSourceRegistry`, `Sources/*`, `Parsing/*` | see [build-sources.md](build-sources.md) |
| Analysis | `Services/Analysis/CharacterAnalyzer`, `BuildEvaluator`, `ItemScorer`, `SetMatcher`, `AccountContext` | see [analysis.md](analysis.md) |
| API | `Http/Controllers/Api/*`, `Http/Presenters/BuildPresenter` | routes in `routes/api.php` |
| Console | `catalog:sync`, `builds:sync`, `accounts:import` | schedule in `routes/console.php` |
| Queue | `Jobs/SyncBuildSource` | "Sync now" from the UI; needs `queue:work` |

`AppServiceProvider` registers `Fetcher` as a singleton (throttling spans the process), `Catalog` as scoped
(in-memory caches reset between queued jobs) and sets Guzzle's CA bundle from `composer/ca-bundle`
(Windows PHP ships without one).

### Main flows

- **Import**: `ImportController` → `ImportService::fromEnka()` / `fromFile()` (format detected from the JSON)
  → parser builds a `ParsedAccount` with catalog *references* (`external_id` / `name` / `key`) →
  `AccountImporter::import()` resolves references, upserts characters, replaces gear, writes an `ImportRun`.
  Failures are recorded as failed `ImportRun`s; `ImportException` messages are shown to users verbatim (422).
- **Build sync**: `SyncBuilds` command or `SyncBuildSource` job → `BuildSync::sync(source)` →
  `listCharacters()` → `fetchBuilds()` per released character → names resolved (characters created if
  unknown; weapons/sets only looked up) → upsert `builds` by (source, character, variant) with a content hash.
- **Analysis**: `AccountController::show` → `CharacterAnalyzer::overview()` (best build per character);
  `AccountController::character` → `CharacterAnalyzer::detail()` (all evaluations, consensus, suggestions,
  set plans) + full builds via `BuildPresenter`.

### REST API (`routes/api.php`)

| Method & path | Purpose |
| --- | --- |
| `GET /api/meta` | slots, stat labels, talent names per game |
| `GET /api/accounts`, `GET/PATCH/DELETE /api/accounts/{id}` | list / overview (+ characters with best build) / rename / delete |
| `GET /api/accounts/{id}/characters/{key}?build=ID` | full analysis of one character (key = catalog key, e.g. `hutao`) |
| `GET /api/accounts/{id}/imports` | import history |
| `POST /api/imports/enka` `{game, uid, account_id?}` | showcase import (throttled 10/min) |
| `POST /api/imports/file` `{content \| file, game?, account_id?, file_name?}` | GOOD / HSR scanner import |
| `GET /api/builds/{game}`, `GET /api/builds/{game}/{key}` | builds browser |
| `GET /api/sources`, `PATCH /api/sources/{key}`, `POST /api/sources/{key}/sync` | source status, enable/disable, queue a sync |
| `GET /api/sync-runs`, `GET /api/sync-runs/{id}` | sync history and logs |

No authentication: the app is single-user and binds to 127.0.0.1. Add auth before exposing it anywhere.

## Frontend (`frontend/`, Angular 21 + Angular Material 21)

- `core/api.ts` typed client + `errorMessage()`; `core/models.ts` mirrors the API responses (keep in sync
  with the PHP arrays); `core/meta-store.ts` loads `/api/meta` at startup (labels, formatting);
  `core/format.ts` colors and time helpers.
- `shared/`: `build-detail` (renders a build, optionally annotated with an evaluation), `item-card`,
  `grade-badge`, `score-bar`, `char-avatar` (`app-avatar`), `stat-pipes` (`statLabel`, `statValue`,
  `slotLabel`, `timeAgo`).
- `pages/`: `dashboard`, `import` (wizard + `import-guides.ts` content), `account`, `character`, `builds`
  (list + per character), `sources`. All lazy-loaded from `app.routes.ts`; route params arrive as signal
  inputs (`withComponentInputBinding`).
- Conventions: standalone components, signals (`signal`, `computed`, `input`), `inject()`, built-in control
  flow (`@if`, `@for`, `@let`), zoneless change detection, Material 3 theme with CSS tokens in
  `src/styles.scss` (`--app-*`, `--grade-*`, `--el-*`, light/dark via `light-dark()`).
- Dev server proxies `/api` to `http://127.0.0.1:8000` (`proxy.conf.json`). `npm run build:backend` builds
  into `backend/public/app`, served by Laravel at `/app/`.
