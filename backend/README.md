# Build analyzer: backend

The API, the importers, the build scrapers and the analysis: PHP 8.4+, Slim 4, PostgreSQL. See
[DEV.md](../DEV.md) for setting up, running, testing and deploying, and
[docs/architecture.md](../docs/architecture.md) for how it fits together.

```bash
composer install                       # the packages (Slim, slim/psr7, composer/ca-bundle, voku/portable-ascii, anotoki/lib from GitHub)
composer start                         # the API at /api, setup.php, and the built frontend (php -S localhost:8400 router.php)
php migration.php [--status|--dry-run] # apply the pending migrations
php sync-builds.php --help             # read builds (the same stepped runs as the Sources page); --help lists the options
php sync-catalog.php [genshin|hsr]     # refresh the game catalog
php import-account.php <game> <uid|file> --user=IAM_ID [--account=ID]
php translations.php --status          # the site's strings (--export=sk [file], --import=sk file)
php tests/run.php                      # every test file, each on its own throwaway database
php tools/build.php [--skip-frontend]  # the upload for the FTP-only host, into ../dist/
```

| Path | What |
| --- | --- |
| `api/` | the front controller (`index.php`, reached through `api/.htaccess`), `app.php` (routes + middleware), `middleware/` (`site_gate.php`: the anotoki family's site gate - 503 `not_set_up` / `update_pending` but its open paths; `auth.php`: who a request is - the IAM token, or for one's own data this browser's visitor key; nobody and no key while the IAM requires sign-in - and the role checks), `routes/` |
| `src/` | the application (`App\` namespace, PSR-4); `Db/Migrations.php` puts this site's migrations on the engine of the family's library (`vendor/anotoki/lib`: Composer `anotoki/lib`, installed from GitHub; DEV.md "The shared library" says how to update it) |
| `config/app.php` | settings that are the same everywhere (sources, catalog, showcase service, aliases) |
| `config/*.local.php` | this installation's database and sign-in (required) and outgoing-request settings (optional) - written by `setup.php`, gitignored |
| `migrations/` | the schema and the site's strings, as SQL files applied in order (`001` adopts a database of the Laravel version); while one waits the site is closed but for its ADMIN on the Migrations page |
| `storage/` | what the running app writes: `cache/iam/` (the IAM's keys, and its answer whether sign-in is required here, kept a minute), `http-cache/` (fetched pages), `icons/`, `logs/app.log` |
| `tests/` | the tests (`*.test.php`), their harness (`lib.php`, `run.php`, `support/`), fixtures, and `tools/` - `fake-iam.php`, the stand-in IAM (the survey's; it also gives the IAM's public answer about the application, `login_required` false by default, and - for a person given them - the account's `preferences` in the tokens and `POST /api/oidc/preferences`, as an IAM with its migration 015) that `stand_in_iam.test.php` signs in through and the browser check uses, and `browser-db.php`, the browser check's throwaway database (`build_analyzer_test_browser`) and the settings folder `BUILD_ANALYZER_TEST_CONFIG` points the site at (honoured by `App\Config` only for a `build_analyzer_test_` database) |
| `tools/` | `build.php` (assembles `../dist/`, what to upload) and `php.sh` (runs `BUILD_ANALYZER_PHP` where the `php` on PATH is older than 8.4) |
| `setup.php` | the installer: the database and the IAM, needed once on every installation, this computer included |
| `router.php` | makes PHP's built-in server behave like the host's Apache (development only) |
| `migration.php`, `sync-builds.php`, `sync-catalog.php`, `import-account.php`, `translations.php` | the command line (`src/Commands/`) |

Sign-in is the anotoki IAM's only, and so is the switch that requires it here (`src/Auth/SignInPolicy.php`: the vendored SDK reads it from the IAM; the tests put a fixed answer in its place, `tests/support/FixedSignInPolicy.php`). Every account holds `MEMBER`, an ordinary person here. Roles: `EDITOR` (syncs, the strings) and `ADMIN` (also sources on / off,
migrations, the languages themselves, deleting a person's data); a signed-in person with neither uses their own
accounts, and saves them as a file or imports one (`/api/me/*`). The tests make throwaway `build_analyzer_test_*` databases (and, for one setup test, a throwaway login)
on the server of `config/database.local.php` and drop them; their servers use ports 8590-8599.
