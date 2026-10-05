<?php

use App\App;
use App\Auth\Role;
use App\Cache\LockTimeout;
use App\Enums\Game;
use App\Presenters\SyncRunPresenter;
use App\Repositories\SyncRuns;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Sync\SyncRunner;
use App\Web\HttpError;
use App\Web\Input;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * Build and catalog syncs, run in steps by an editor's browser (EDITOR or ADMIN).
 *
 * The Sources page starts a sync, then calls POST /api/sync/step again and again - waiting wait_ms in
 * between - until nothing is active. Each step is a short request (fetch.local.php step_seconds), so no
 * queue worker, scheduler or long-running process is involved; closing the page pauses the sync.
 *
 *   GET  /api/sync                       the runs going, and when the next step has work
 *   POST /api/sync/step                  one bounded piece of work
 *   POST /api/sync/builds  {force?}      "Sync all": every enabled source not synced within stale_hours
 *   POST /api/sources/{key}/sync         "Sync now" on one source, however recent its last sync
 *   POST /api/sync/catalog {games?}      refresh the game catalog
 *   POST /api/sync-runs/{id}/cancel      stop a run where it is (409 while a step holds it)
 */
return function (Slim\App $slim, App $app): void {
    $started = function (array $result) use ($app): array {
        $runner = $app->get(SyncRunner::class);
        $presenter = $app->get(SyncRunPresenter::class);
        $runs = $app->get(SyncRuns::class);
        $active = $runner->active();

        return [
            'started' => array_map(fn ($run) => $presenter->summary($runs->find((int) $run->id) ?? $run), $result['started']),
            'skipped' => $result['skipped'],
        ] + $presenter->status($active, $runner->waitMs($active));
    };

    $slim->group('', function (RouteCollectorProxy $group) use ($app, $started) {
        $group->get('/api/sync', function (Request $request, Response $response) use ($app): Response {
            $runner = $app->get(SyncRunner::class);
            $active = $runner->active();

            return Json::send($response, $app->get(SyncRunPresenter::class)->status($active, $runner->waitMs($active)));
        });

        $group->post('/api/sync/step', function (Request $request, Response $response) use ($app): Response {
            // A step always finishes its bookkeeping, even when the tab is closed mid-request, and has
            // time to spare over its own budget.
            ignore_user_abort(true);
            @set_time_limit(max(60, (int) $app->config->get('fetch.step_seconds', 20) + 40));

            return Json::send($response, $app->get(SyncRunPresenter::class)->step($app->get(SyncRunner::class)->step()));
        });

        $group->post('/api/sync/builds', function (Request $request, Response $response) use ($app, $started): Response {
            $input = Input::of($request);
            $force = $input->boolean('force') ?? false;
            $input->valid();

            $sources = array_values($app->get(BuildSourceRegistry::class)->all());

            return Json::send($response, $started($app->get(SyncRunner::class)->startBuilds($sources, ['force' => $force], currentUser($request))));
        });

        $group->post('/api/sources/{key}/sync', function (Request $request, Response $response, array $args) use ($app, $started): Response {
            $source = $app->get(BuildSourceRegistry::class)->find((string) $args['key']) ?? throw HttpError::notFound();

            return Json::send($response, $started($app->get(SyncRunner::class)->startBuilds([$source], ['force' => true], currentUser($request))));
        });

        $group->post('/api/sync/catalog', function (Request $request, Response $response) use ($app, $started): Response {
            $input = Input::of($request);
            $games = $input->games('games') ?? Game::cases();
            $input->valid();

            return Json::send($response, $started($app->get(SyncRunner::class)->startCatalog(array_values(array_unique($games, SORT_REGULAR)), true, currentUser($request))));
        });

        $group->post('/api/sync-runs/{run:[0-9]+}/cancel', function (Request $request, Response $response, array $args) use ($app): Response {
            $run = $app->get(SyncRuns::class)->find((int) $args['run']) ?? throw HttpError::notFound();

            try {
                $run = $app->get(SyncRunner::class)->cancel($run, currentUser($request));
            } catch (LockTimeout) {
                throw new HttpError(409, 'A sync step is still running. Try again in a moment.');
            }

            return Json::send($response, $app->get(SyncRunPresenter::class)->summary($app->get(SyncRuns::class)->find((int) $run->id) ?? $run));
        });
    })->add(requireRole($app, ...Role::STAFF));
};
