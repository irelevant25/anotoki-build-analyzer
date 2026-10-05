<?php

use App\App;
use App\Auth\Role;
use App\Cache\LockTimeout;
use App\Presenters\SyncRunPresenter;
use App\Repositories\BuildSources;
use App\Repositories\SyncRuns;
use App\Services\Builds\BuildFile;
use App\Services\Builds\BuildFileException;
use App\Services\Builds\BuildSource;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Builds\BuildTransfer;
use App\Services\Builds\SourceSyncing;
use App\Web\HttpError;
use App\Web\Input;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * The build sources and their sync history. Listing is public (the dashboard shows how fresh the builds
 * are); the history and moving a source's builds through a file are for editors (EDITOR or ADMIN), and
 * switching a source on or off is for admins. A source is "Source N" with a key like "genshin-2" - the API
 * never names the site.
 *
 *   GET   /api/sources                          public; who started the latest run only for editors
 *   PATCH /api/sources/{key}  {enabled}         admins
 *   GET   /api/sources/{key}/export             editors: the source's builds as a file to save,
 *                                               "builds-genshin-2-2026-09-29.json" (format: BuildFile)
 *   POST  /api/sources/{key}/import  <file>     editors: the file's JSON as the body; the source's builds
 *                                               become the file's. Answers the counts, the characters the
 *                                               catalog lacks and the source as GET /api/sources lists it;
 *                                               422 when the file does not fit this source (nothing is
 *                                               written), 409 while the source syncs or a step runs
 *   GET   /api/sync-runs?source=&kind=&limit=   editors: history, newest first
 *   GET   /api/sync-runs/{id}                   editors: one run with its log
 *
 * Each group carries its role check, so a route added to one cannot go without it.
 */
return function (Slim\App $slim, App $app): void {
    /**
     * A source as the Sources page lists it: its state, its counts ($counts from BuildSources::counts()),
     * whether a sync of it is going, and its latest run - with who started it when $staff.
     */
    $summary = function (BuildSource $source, array $counts, bool $staff) use ($app): array {
        $model = $app->get(BuildSourceRegistry::class)->model($source);
        $latest = $app->get(SyncRuns::class)->latestFor($model->id);
        $presenter = $app->get(SyncRunPresenter::class);

        return $presenter->source($model) + [
            'builds' => $counts[$model->id]['builds'] ?? 0,
            'characters' => $counts[$model->id]['characters'] ?? 0,
            'running' => (bool) $latest?->isActive(),
            'latest_run' => $latest ? $presenter->summary($latest, withStarter: $staff) : null,
        ];
    };

    $slim->get('/api/sources', function (Request $request, Response $response) use ($app, $summary): Response {
        $registry = $app->get(BuildSourceRegistry::class);
        $registry->ensureModels();
        $counts = $app->get(BuildSources::class)->counts();
        // Who started a run (an editor's IAM id and name) is for editors and admins only.
        $staff = currentUserOrNull($request)?->isStaff() ?? false;

        $sources = array_map(fn (BuildSource $source) => $summary($source, $counts, $staff), array_values($registry->all()));
        // By game, then in the order of their numbers.
        usort($sources, fn ($a, $b) => [$a['game'], $a['key']] <=> [$b['game'], $b['key']]);

        return Json::send($response, $sources);
    })->add(optionalSignIn($app));

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
    })->add(requireRole($app, Role::ADMIN));

    $slim->group('', function (RouteCollectorProxy $group) use ($app, $summary) {
        $group->get('/api/sources/{key}/export', function (Request $request, Response $response, array $args) use ($app): Response {
            $source = $app->get(BuildSourceRegistry::class)->find((string) $args['key']) ?? throw HttpError::notFound();
            $file = $app->get(BuildTransfer::class)->export($source);

            return Json::download($response, $file, "builds-{$file['source']}-".substr((string) $file['exported_at'], 0, 10).'.json');
        });

        $group->post('/api/sources/{key}/import', function (Request $request, Response $response, array $args) use ($app, $summary): Response {
            // An import that has started finishes, even when the tab is closed while it runs.
            ignore_user_abort(true);
            @set_time_limit(BuildTransfer::MAX_SECONDS);

            $source = $app->get(BuildSourceRegistry::class)->find((string) $args['key']) ?? throw HttpError::notFound();
            $bytes = strlen((string) $request->getBody());
            if ($bytes === 0) {
                // What PHP makes of a body larger than its post_max_size: the file arrives as nothing.
                throw new HttpError(422, 'No file arrived. A file larger than this server accepts arrives empty.');
            }
            if ($bytes > BuildFile::MAX_BYTES) {
                throw new HttpError(422, sprintf('The file is larger than %d MB, far more than the builds of a source take.', BuildFile::MAX_BYTES / 1048576));
            }

            try {
                $result = $app->get(BuildTransfer::class)->import($source, $request->getParsedBody(), currentUser($request));
            } catch (BuildFileException $e) {
                throw new HttpError(422, $e->getMessage());
            } catch (SourceSyncing $e) {
                throw new HttpError(409, $e->getMessage());
            } catch (LockTimeout) {
                throw new HttpError(409, 'A sync step is still running. Try again in a moment.');
            }

            return Json::send($response, $result + ['source' => $summary($source, $app->get(BuildSources::class)->counts(), true)]);
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
    })->add(requireRole($app, ...Role::STAFF));
};
