# Build analyzer: backend

The API, the importers, the build scrapers and the analysis: PHP 8.4+, Slim 4, PostgreSQL. See
[DEV.md](../DEV.md) for setting up, running, testing and deploying, and
[docs/architecture.md](../docs/architecture.md) for how it fits together.

```bash
composer install                       # the packages (Slim, slim/psr7, composer/ca-bundle, voku/portable-ascii)
composer start                         # the API at /api, setup.php, and the built frontend (php -S 127.0.0.1:8400 router.php)
php migration.php [--status|--dry-run] # apply the pending migrations
php sync-builds.php --help             # read builds (the same stepped runs as the Sources page); --help lists the options
php sync-catalog.php [genshin|hsr]     # refresh the game catalog
php import-account.php <game> <uid|file> [--account=ID] [--user=IAM_ID]
php tests/run.php                      # every test file, each on its own throwaway database
```

| Path | What |
| --- | --- |
| `api/` | the front controller (`index.php`, reached through `api/.htaccess`), `app.php` (routes + middleware), `middleware/`, `routes/` |
| `src/` | the application (`App\` namespace, PSR-4) |
| `config/app.php` | settings that are the same everywhere (sources, catalog, showcase service, aliases) |
| `config/*.local.php` | this installation's database, sign-in and outgoing-request settings - written by `setup.php`, gitignored |
| `migrations/` | the schema, as SQL files applied in order (`001` adopts a database of the Laravel version) |
| `storage/` | what the running app writes: `cache/` (the IAM's keys), `http-cache/` (fetched pages), `icons/`, `logs/app.log` |
| `tests/` | the tests (`*.test.php`), their harness (`lib.php`, `run.php`, `support/`) and fixtures |
| `setup.php` | the installer: needed once on every installation, this computer included |
| `router.php` | makes PHP's built-in server behave like the host's Apache (development only) |
