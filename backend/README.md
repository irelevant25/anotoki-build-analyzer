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
php backup.php --list                  # the database's backups (--create, --inspect=, --extract=, --restore=)
php tests/run.php                      # every test file, each on its own throwaway database
# the release: cd ../frontend && bun run anotoki-deploy build / deploy (../DEV.md, "Deploying")
```

| Path | What |
| --- | --- |
| `api/` | the front controller (`index.php`, reached through `api/.htaccess`), `app.php` (routes + middleware), `middleware/` (`site_gate.php`: the anotoki family's site gate - 503 `not_set_up` / `update_pending` but its open paths; `auth.php`: who a request is - the IAM token through the family's auth module, or for one's own data this browser's visitor key; nobody and no key while the IAM requires sign-in - and the role checks; `upkeep.php`: after an answer, the scrub of the IAM's deleted accounts and the day's backup; `errors.php`: JSON errors, an exception into the error log), `routes/` (`people.php`, `admin_tools.php` and `feedback.php`: the family's People page, admin tools - the errors the pages meet in the browser too, `POST /api/client-errors` - and feedback form behind this site's checks) |
| `src/` | the application (`App\` namespace, PSR-4); `Db/Migrations.php` puts this site's migrations between the library's own sets (`anotoki_admin` before them, `anotoki_translations` and `anotoki_people` after) on the engine of the family's library (`vendor/anotoki/lib`: Composer `anotoki/lib`, installed from GitHub; DEV.md "The shared library" says how to update it), `Services/Translations/SiteTranslations.php` sets up the library's translations module for this site's words (the routes in `api/routes/translations.php`, the command line `translations.php`), `Auth/Iam.php` its auth module (the IAM's client), `People/SitePeople.php` its people module on this site's `users` (the People page's delete runs in the library's transaction, on the connection it is given - a restore does it again on its own), and `Admin/AdminTools.php` its admin tools (the error log, the audit log, feedback, sealed backups); `Web/ClientAddress.php` where a request comes from, by the library's rule (Cloudflare's `CF-Connecting-IP` - with the origin secret only on a request carrying it), for the limits; `Web/Pipeline.php` runs several middleware as one (a route group's identity and its rule together) |
| `config/app.php` | settings that are the same everywhere (sources, catalog, showcase service, aliases, the limits, Cloudflare's ranges as the trusted proxies) |
| `config/*.local.php` | this installation's database and sign-in (required) and outgoing-request settings (optional) - written by `setup.php`, gitignored; `client_address.local.php` (optional, by hand) overrides the trusted proxies where the site is not behind Cloudflare; `cloudflare.local.php` (optional, by hand, the same in every anotoki site) holds the origin secret - with it `CF-Connecting-IP` is believed only on a request carrying it (`Config::originSecret()`). Each read cleanly or not at all (`Config::readLocalFile()`: never compiled unless it starts with `<?php`, nothing it prints ever sent); one passed over never reads looser than none - the required ones leave the site not set up, `fetch.local.php`'s defaults stand in, the proxies' file trusts none, the origin secret's fails closed - as does one of its that names no usable secret, so only deleting it switches the secret off (docs/operations.md, "Configuration") |
| `migrations/` | the schema and the site's strings, as SQL files applied in order (`001` adopts a database of the Laravel version), between the library's own sets (`vendor/anotoki/lib/backend/migrations/`: `admin` first, then `translations` and `people`); while one waits the site is closed but for its ADMIN on the Migrations page |
| `storage/` | what the running app writes: `cache/iam/` (the IAM's keys, its answer whether sign-in is required here, kept a minute, and where the scrub of its deleted accounts stands), `cache/` (feedback's limits; `config-said-*.stamp`, the day a settings file passed over was last said in PHP's error log), `http-cache/` (fetched pages), `icons/`, `logs/app.log` (what the site did) and `logs/errors-*.jsonl` (the error log; `client-errors.json` counts the pages' reports), `backups/` (the database's backups), `anotoki-lib.key` (the installation's key: the counters' and the error log's keyed hashes, the backups' seal) |
| `tests/` | the tests (`*.test.php`), their harness (`lib.php`, `run.php`, `support/`), fixtures, and `tools/` - `browser-db.php`, the browser check's throwaway database (`build_analyzer_test_browser`) and the settings folder `BUILD_ANALYZER_TEST_CONFIG` points the site at (honoured by `App\Config` only for a `build_analyzer_test_` database) |
| `tools/` | `check-release.php` / `.mjs` (a release's frontend names no other site - run by anotoki-deploy's build) and `php.sh` (runs `BUILD_ANALYZER_PHP` where the `php` on PATH is older than 8.4) |
| `setup.php` | the installer: the database and the IAM, needed once on every installation, this computer included |
| `router.php` | makes PHP's built-in server behave like the host's Apache (development only) |
| `migration.php`, `sync-builds.php`, `sync-catalog.php`, `import-account.php`, `translations.php`, `backup.php` | the command line (`src/Commands/`, and the library's commands) |

Sign-in is the anotoki IAM's only, and so is the switch that requires it here - both through the family's auth
module (anotoki-lib's `Anotoki\Lib\Auth`: the token check, `SignInRule`; the tests use its `TestIam` - its key, the
tokens it signs and its answers as files - and `stand_in_iam.test.php` and the browser check its stand-in IAM,
`vendor/anotoki/lib/backend/testing/stand-in-iam.php`). Every account holds `MEMBER`, an ordinary person here. Roles:
`EDITOR` (syncs, the strings) and `ADMIN` (also sources on / off, migrations, the languages themselves, People,
the error log, the audit log, feedback and backups); a signed-in person with neither uses their own
accounts, and saves them as a file or imports one (`/api/me/*`). The tests make throwaway `build_analyzer_test_*` databases (and, for one setup test, a throwaway login)
on the server of `config/database.local.php` and drop them; their servers use ports 8590-8599.
