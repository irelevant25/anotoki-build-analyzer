<?php

use App\App;
use App\Auth\Role;
use App\Presenters\SyncRunPresenter;
use App\Repositories\BuildSources;
use App\Repositories\SyncRuns;
use App\Services\Builds\BuildSource;
use App\Services\Builds\BuildSourceRegistry;
use App\Web\HttpError;
use App\Web\Input;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * The build sources and their sync history. Listing is for every member (the dashboard shows how fresh the
 * builds are); switching sources and the history are for admins. A source is "Source N" with a key like
 * "genshin-2" - the API never names the site.
 *
 *   GET   /api/sources                          members
 *   PATCH /api/sources/{key}  {enabled}         admins
 *   GET   /api/sync-runs?source=&kind=&limit=   admins: history, newest first
 *   GET   /api/sync-runs/{id}                   admins: one run with its log
 */
return function (Slim\App $slim, App $app): void {
    $slim->get('/api/sources', function (Request $request, Response $response) use ($app): Response {
        $registry = $app->get(BuildSourceRegistry::class);
        $registry->ensureModels();
        $presenter = $app->get(SyncRunPresenter::class);
        $counts = $app->get(BuildSources::class)->counts();
        // Who started a run (an admin's IAM id and name) is for admins only.
        $admin = currentUser($request)->isAdmin();

        $sources = [];
        foreach ($registry->all() as $source) {
            /** @var BuildSource $source */
            $model = $registry->model($source);
            $latest = $app->get(SyncRuns::class)->latestFor($model->id);

            $sources[] = $presenter->source($model) + [
                'builds' => $counts[$model->id]['builds'] ?? 0,
                'characters' => $counts[$model->id]['characters'] ?? 0,
                'running' => (bool) $latest?->isActive(),
                'latest_run' => $latest ? $presenter->summary($latest, withStarter: $admin) : null,
            ];
        }
        // By game, then in the order of their numbers.
        usort($sources, fn ($a, $b) => [$a['game'], $a['key']] <=> [$b['game'], $b['key']]);

        return Json::send($response, $sources);
    })->add(requireRole($app, Role::MEMBER));

    $slim->group('', function (RouteCollectorProxy $group) use ($app) {
        $group->patch('/api/sources/{key}', function (Request $request, Response $response, array $args) use ($app): Response {
            $registry = $app->get(BuildSourceRegistry::class);
            $source = $registry->find((string) $args['key']) ?? throw HttpError::notFound();
            $input = Input::of($request);
            $enabled = $input->boolean('enabled', required: true);
            $input->valid();

            $model = $registry->model($source);
            $app->get(BuildSources::class)->update($model, ['enabled' => $enabled]);

            return Json::send($response, $app->get(SyncRunPresenter::class)->source($model));
        });

        $group->get('/api/sync-runs', function (Request $request, Response $response) use ($app): Response {
            $query = $request->getQueryParams();
            $registry = $app->get(BuildSourceRegistry::class);

            $sourceKey = null;
            if (is_string($query['source'] ?? null) && $query['source'] !== '') {
                // An unknown source has no runs; its internal key is not needed to say so.
                $sourceKey = $registry->find($query['source'])?->key() ?? '(none)';
            }
            $kind = is_string($query['kind'] ?? null) && $query['kind'] !== '' ? $query['kind'] : null;
            $limit = isset($query['limit']) && ctype_digit((string) $query['limit']) ? (int) $query['limit'] : 30;

            $runs = $app->get(SyncRuns::class)->recent($sourceKey, $kind, max(1, min(100, $limit)));
            $presenter = $app->get(SyncRunPresenter::class);

            return Json::send($response, array_map(fn ($run) => $presenter->summary($run), $runs));
        });

        $group->get('/api/sync-runs/{run:[0-9]+}', function (Request $request, Response $response, array $args) use ($app): Response {
            $run = $app->get(SyncRuns::class)->find((int) $args['run']) ?? throw HttpError::notFound();

            return Json::send($response, $app->get(SyncRunPresenter::class)->withLog($run));
        });
    })->add(requireRole($app, Role::ADMIN));
};
