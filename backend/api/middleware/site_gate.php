<?php

use Anotoki\Lib\Backups\Http\BackupsRoutes;
use Anotoki\Lib\ErrorLog\Http\ErrorLogRoutes;
use Anotoki\Lib\Migrations\Http\SiteGate;
use Anotoki\Lib\Migrations\Http\SiteState;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Translations\Http\TranslationsRoutes;
use App\App;
use App\Support\Log;

/**
 * The site gate - the anotoki family's one behaviour while a site is not ready (anotoki/lib: SiteState,
 * SiteGate), in front of every route. Where the site stands is decided afresh for every request:
 *
 *   not set up        config/database.local.php or auth.local.php is missing or incomplete (setup.php writes
 *                     them; which file and which value is said in the log only): every API path answers
 *                     503 {code: "not_set_up", installed, message} - with "setup": "/setup.php" only while
 *                     the site was never installed. setup.php writes storage/installed.lock when it finishes
 *                     on a real host (never on this computer), and from then on nobody is sent to it again.
 *   update pending    a migration file is ready to apply: every API path answers 503 {code: "update_pending",
 *                     message} with Retry-After: 60 - but the open paths below, so the pages have their
 *                     settings and their words, people can sign in, and an ADMIN reaches Apply.
 *   unavailable       the database cannot be reached: everything passes, and the routes' own error handling
 *                     answers (a 500 server_error, the details in the log).
 *   ready             everything passes.
 *
 * GET /api/site-status always answers {state, installed}: how the pages learn where the site stands
 * (api/app.php).
 */

/**
 * The paths answered while an update waits: /api/meta (what the pages load before anything - where people
 * sign in, and whether the IAM requires it), the strings of a language (the bundle the pages read, the
 * library's route, opened by its own pattern: TranslationsRoutes::openPath(); it answers 503
 * translations_unavailable itself while the tables are not there), the Migrations page's API (GET
 * /api/admin/migrations, GET .../file, POST .../apply), and two of the anotoki library's admin tools, which need
 * no table: the error log (GET /api/admin/errors, GET .../{fingerprint}, DELETE - ErrorLogRoutes::openPaths())
 * and the backups (the list, the proof asked for, one backup, making one, its download ticket and file, what a
 * restore would take away - BackupsRoutes::openPaths(); never a restore): a backup just before Apply is the
 * point. Each an ADMIN's, judged from the token alone (requireTokenRole()). Nothing else: a route that may need
 * what a waiting migration makes stays closed until it is applied.
 *
 * Each is a route exactly, never a prefix. The gate reads the path decoded, as the router does, and neither
 * resolves dot segments: under a prefix, a crafted /api/admin/migrations/../x (or %2e%2e, %2F) would be let
 * past the gate into the app. A language's code is one segment, and its route refuses anything that is not a
 * language's code (`..` too) before any query. (D: `$` is the end of the path, never before a newline at its
 * end.)
 *
 * @return list<string>
 */
function siteOpenPaths(): array
{
    return ['/api/meta', TranslationsRoutes::openPath(), '~^/api/admin/migrations(/file|/apply)?$~D', ...ErrorLogRoutes::openPaths(), ...BackupsRoutes::openPaths()];
}

/** Where this site stands: its settings, whether setup.php finished on a real host, and its migrations. */
function siteState(App $app): SiteState
{
    return new SiteState(
        settingsProblem: function () use ($app): ?string {
            if ($app->config->isSetUp()) {
                return null;
            }
            $problems = implode('; ', $app->config->problems());
            Log::info('Not set up: ' . $problems);

            return $problems;
        },
        installed: fn(): bool => is_file($app->config->storage('installed.lock')),
        // A request's own Migrator (App\App builds it once per request): what is applied is asked anew each time.
        migrator: fn(): Migrator => $app->get(Migrator::class),
    );
}

function siteGate(Slim\App $slim, SiteState $state): SiteGate
{
    return new SiteGate($state, $slim->getResponseFactory(), siteOpenPaths());
}
