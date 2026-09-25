# Operations and environment

## Running things

| Task | PowerShell | Bash (Git Bash / agents) |
| --- | --- | --- |
| First setup | `.\dev.ps1 setup` | see README "Manual setup" |
| Everything for development | `.\dev.ps1 start` (API :8000, queue worker, scheduler, Angular :4200) | start the pieces below in background shells |
| API only | – | `scripts/artisan.sh serve --host=127.0.0.1 --port=8000` |
| Queue worker ("Sync now" button) | – | `scripts/artisan.sh queue:work --timeout=3300` |
| Scheduler | – | `scripts/artisan.sh schedule:work` |
| Angular dev server | – | `cd frontend && npx ng serve` |
| Sync builds | `.\dev.ps1 sync [-Force]` | `scripts/artisan.sh builds:sync [source] [--force] [--character=x] [--check-names]` |
| Check parsers without writing (offline) | – | `HOYO_HTTP_CACHE_HOURS=96 scripts/artisan.sh builds:sync --dry-run [-v]` |
| Catalog | – | `scripts/artisan.sh catalog:sync [genshin\|hsr] [--force]` |
| Import from CLI | – | `scripts/artisan.sh accounts:import genshin 700123456` or `… hsr path/to/file.json --account=3` |
| Tests | `.\dev.ps1 test` | `scripts/artisan.sh test` and `cd frontend && npx ng test --watch=false` |
| PHP code style | – | `cd backend && ../scripts/php.sh vendor/bin/pint` (`--test` to check) |
| Frontend build | `.\dev.ps1 build` | `cd frontend && npx ng build` (dev) / `npm run build:backend` |
| DB inspection | – | `scripts/artisan.sh tinker --execute='…'` |
| Daily sync without keeping anything running | `.\dev.ps1 install-task` / `remove-task` | – |

`scripts/php.sh` picks PHP 8.4+ from `$HOYO_PHP`, then `dev.local.ps1` (`$env:HOYO_PHP = '…'`), then PATH, and
refuses older versions. `scripts/artisan.sh` runs artisan in `backend/` from any directory, but the script
path itself is relative: AI tool shells reset their working directory, so start commands with
`cd <repo root> &&`. Long tinker code is easiest from a scratch file:
`scripts/artisan.sh tinker --execute="$(cat /tmp/check.php)"` (no `<?php` tag). Print data with
`json_encode(…, JSON_PRETTY_PRINT)` rather than `dump()`.

Schedule (`routes/console.php`): `catalog:sync` 04:00, `builds:sync` at `HOYO_SYNC_DAILY_AT` (04:30), HTTP cache
pruning daily. Times are in `HOYO_SYNC_TIMEZONE` (default UTC; this machine's `.env` sets `Europe/Bratislava`,
matching the Windows task, which uses local time). `scripts/artisan.sh schedule:list` shows them in UTC. The
app and database stay in UTC. The queue uses the database driver with `DB_QUEUE_RETRY_AFTER=3600` (a Prydwen
sync takes ~20 min).

Is the daily sync actually running? Missed runs leave no trace in the database. Check that either the Windows
task exists (`Get-ScheduledTask -TaskName 'Hoyo Analyzer daily build sync'`) or a scheduler is running
(`Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -match 'schedule:work'`).

## Machine quirks (this Windows machine)

- `php` on PATH is **PHP 7.4**; the project needs 8.4+ (a local PHP 8.5 install whose path is set in the
  gitignored `dev.local.ps1`). Always go through `scripts/php.sh` / `scripts/artisan.sh`.
- That PHP had `pdo_sqlite`, `sqlite3`, `zip`, `intl`, `sodium` enabled for this project (backup
  `php.ini.bak-before-hoyo-analyzer`). `pdo_pgsql` is enabled too. There is no CA bundle in php.ini; the app
  uses `composer/ca-bundle`, so don't "fix" SSL errors by disabling verification.
- Composer lives at `tools/composer.phar` (gitignored): `cd backend && ../scripts/php.sh ../tools/composer.phar …`.
- npm 10.9.2 crashes on this project (`Cannot read properties of null (reading 'edgesOut')`); use
  `npx -y npm@11 install|ci`. npm 11 skips some install scripts (esbuild, lmdb…); the build works anyway.
- Node 22.15 → Angular 21 (Angular 22 needs Node ≥ 22.22.3).
- Python is not installed (`python` is the Windows Store stub): use node or PHP for scripting.
- Git Bash: heredocs containing apostrophes can make the whole command fail to parse → write files with the
  editor tools instead; `sed -i` converts CRLF files to LF (check with `file`).
- Background `ng serve` leaves an orphan `node` process on port 4200 after stopping; free the port with
  `Get-NetTCPConnection -LocalPort 4200` → `Stop-Process`.

## Troubleshooting

| Symptom | Where to look |
| --- | --- |
| Source status `failed` / `partial` | Sources page run log or `sync_runs.log`; use the `debug-build-sync` skill |
| Builds getting old, no failed runs | nothing schedules the sync: `.\dev.ps1 install-task` or keep `.\dev.ps1 start` running |
| Parser change: did it break anything? | `HOYO_HTTP_CACHE_HOURS=96 scripts/artisan.sh builds:sync --dry-run` → expect only intended `changed` |
| `! unknown weapon/set` lines, "unmatched" in import summaries | [name-matching.md](name-matching.md) → add an alias |
| New character missing builds | released? `builds:sync --check-names`; site may not have it yet |
| Enka import errors | message is from `EnkaClient` (UID, maintenance, 429); showcase details hidden → wizard step 3 |
| "Cannot reach the backend" in the UI | API not running on 127.0.0.1:8000 (Angular proxies `/api`) |
| cURL error 60 | CA bundle not applied → `AppServiceProvider::boot` |
| SQLite "database is locked" | long transactions + parallel writers; WAL and 5 s busy timeout are configured |
| Headless screenshots blank | timing only; see the `verify-ui` skill |

## Switching to PostgreSQL

Set `DB_CONNECTION=pgsql` and the `DB_*` values in `backend/.env`, run `scripts/artisan.sh migrate` and
`catalog:sync`, then re-import accounts and `builds:sync --force` (cached pages make it fast). Never commit
credentials; `.env` is gitignored.
