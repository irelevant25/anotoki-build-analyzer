<?php

use App\App;
use App\Auth\Role;
use App\Cache\Locks;
use App\Db\Db;
use App\Db\Migrator;
use App\Support\Log;
use App\Web\HttpError;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * Schema changes on a host without a shell (admins only): upload the new files in migrations/ by FTP, then
 * apply them here. The same engine as `php migration.php` and setup.php.
 *
 *   GET  /api/admin/migrations         {pending, applied_count, last_applied}
 *   POST /api/admin/migrations/apply   {ok, applied, output} + the status after
 */
return function (Slim\App $slim, App $app): void {
    $migrator = fn () => new Migrator($app->get(Db::class)->raw(), Migrator::directory());

    $slim->group('/api/admin/migrations', function (RouteCollectorProxy $group) use ($app, $migrator) {
        $group->get('', function (Request $request, Response $response) use ($migrator): Response {
            return Json::send($response, $migrator()->status());
        });

        $group->post('/apply', function (Request $request, Response $response) use ($app, $migrator): Response {
            ignore_user_abort(true);
            @set_time_limit(300);

            $lock = $app->get(Locks::class)->lock('migrate', 300);
            if (! $lock->get()) {
                throw new HttpError(409, 'The migrations are being applied already.');
            }

            try {
                // A failing migration is reported, not thrown: on a host without a shell the admin has only
                // this answer to go on (the log is FTP-only).
                $result = $migrator()->applyPending(['local_user_id' => (int) $app->config->get('auth.local_user_id', 1)]);
            } finally {
                $lock->release();
            }

            $output = [];
            foreach ($result['applied'] as $file) {
                $output[] = "Applied {$file}.";
            }
            if ($result['failed'] !== null) {
                $output[] = "{$result['failed']} failed: {$result['error']}";
                Log::error("Migration {$result['failed']} failed: {$result['error']}");
            }

            return Json::send($response, [
                'ok' => $result['failed'] === null,
                'applied' => $result['applied'],
                'output' => $output === [] ? 'Nothing to apply.' : implode("\n", $output),
            ] + $migrator()->status());
        });
    })->add(requireRole($app, Role::ADMIN));
};
