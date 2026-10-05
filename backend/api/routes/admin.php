<?php

use App\App;
use App\Auth\Role;
use App\Cache\Locks;
use App\Db\Migrator;
use App\Support\Log;
use App\Web\HttpError;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * Schema changes on a host without a shell (admins only; the admin panel's Migrations page): upload the new
 * files in migrations/ by FTP, then apply them here. The same engine as `php migration.php` and setup.php,
 * and the IAM's shape of the two answers.
 *
 *   GET  /api/admin/migrations         {applied: [{name, applied_at}], pending: [{name}]}
 *   POST /api/admin/migrations/apply   {applied: [name], failed: name|null, error: string|null}
 *                                      - 200 whether or not a file failed (the page says where it stopped:
 *                                      the failed file rolled back whole, the ones before it applied);
 *                                      409 migrations_busy while another apply runs
 *
 * The `migrate` lock answers that early, and it expires after 300 s. What keeps a file from running twice
 * when an apply outlives it (or `php migration.php` runs meanwhile, which takes no such lock) is the
 * Migrator's own advisory lock, taken in each file's transaction: a second run waits, then passes over
 * what the first one applied.
 *
 * Nothing here asks whether the database is behind: these routes, signing in and the admin panel's frame
 * work with a migration pending, so an admin can always reach the page that applies it.
 */
return function (Slim\App $slim, App $app): void {
    $migrator = fn (): Migrator => $app->get(Migrator::class);

    $slim->group('/api/admin/migrations', function (RouteCollectorProxy $group) use ($app, $migrator) {
        $group->get('', function (Request $request, Response $response) use ($migrator): Response {
            return Json::send($response, $migrator()->status());
        });

        $group->post('/apply', function (Request $request, Response $response) use ($app, $migrator): Response {
            ignore_user_abort(true);
            @set_time_limit(300);

            $lock = $app->get(Locks::class)->lock('migrate', 300);
            if (! $lock->get()) {
                throw new HttpError(409, 'The migrations are being applied already.', ['code' => 'migrations_busy']);
            }

            try {
                // A failing migration is reported, not thrown: on a host without a shell the admin has only
                // this answer to go on (the log is FTP-only).
                $result = $migrator()->applyPending();
            } finally {
                $lock->release();
            }

            if ($result['failed'] !== null) {
                Log::error("Migration {$result['failed']} failed: {$result['error']}");
            }
            foreach ($result['applied'] as $file) {
                Log::info("Migration {$file} applied.");
            }

            return Json::send($response, [
                'applied' => $result['applied'],
                'failed' => $result['failed'],
                'error' => $result['error'],
            ]);
        });
    })->add(requireRole($app, Role::ADMIN));
};
