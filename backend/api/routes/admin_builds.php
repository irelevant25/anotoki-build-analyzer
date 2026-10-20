<?php

use Anotoki\Lib\Audit\AuditLog;
use App\Admin\AdminTools;
use App\App;
use App\Auth\Role;
use App\Cache\Cache;
use App\Db\Db;
use App\Enums\Game;
use App\Repositories\Builds;
use App\Services\Builds\BuildFile;
use App\Services\Builds\BuildFileException;
use App\Services\Builds\BuildImport;
use App\Services\Builds\BuildImportBusy;
use App\Services\Catalog\CatalogSync;
use App\Support\Clock;
use App\Support\Log;
use App\Web\HttpError;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * The admin panel's Builds page, for editors (EDITOR or ADMIN): a game's builds brought in from a file and written
 * out to one, the imports made, and the game catalog refreshed list by list. The builds are put together elsewhere
 * (docs/builds.md); the site never fetches them itself.
 *
 *   GET  /api/admin/builds                    per game: how many builds and characters, when the newest file
 *                                             carrying them was made, the latest imports with who made each, and
 *                                             the catalog's lists
 *   GET  /api/admin/builds/{game}/export      the game's builds as a file to save, "builds-genshin-2026-10-20.json"
 *                                             (format: BuildFile)
 *   POST /api/admin/builds/{game}/import[?dry_run=1]   <file>: the file's JSON as the body. The game's builds become
 *                                             the file's - or, as a dry run, what that would do, nothing written.
 *                                             422 when the file does not fit (nothing written), 409 while another
 *                                             import writes
 *   POST /api/admin/catalog/{game}/{list}     one list of the catalog (characters, weapons, sets) refreshed from
 *                                             the game data provider; 409 while another refresh of it runs, 502
 *                                             when the provider cannot be read
 *
 * An import and a refresh are in the audit log (builds.imported, in the import's transaction; catalog.refreshed).
 * The group carries its role check, so a route added to it cannot go without one.
 */
return function (Slim\App $slim, App $app): void {
    $gameOf = fn(array $args): Game => Game::tryFrom((string) $args['game']) ?? throw HttpError::notFound();

    $slim
        ->group('', function (RouteCollectorProxy $group) use ($app, $gameOf) {
            $group->get('/api/admin/builds', function (Request $request, Response $response) use ($app): Response {
                $counts = $app->get(Builds::class)->counts();
                $imports = $app->get(BuildImport::class);
                $catalog = $app->get(CatalogSync::class);

                $games = [];
                $people = [];
                foreach (Game::cases() as $game) {
                    $recent = $imports->recent($game);
                    array_push($people, ...array_filter(array_column($recent, 'imported_by')));
                    $games[] = [
                        'game' => $game->value,
                        'characters' => $counts[$game->value]['characters'],
                        'builds' => $counts[$game->value]['builds'],
                        'checked_at' => Clock::iso(Db::time($counts[$game->value]['checked_at'])),
                        'changed_at' => Clock::iso(Db::time($counts[$game->value]['changed_at'])),
                        'imports' => $recent,
                        'catalog' => $catalog->lists($game),
                    ];
                }

                // Who made each import, by the name this site keeps; a deleted person's import is nobody's.
                $names = AdminTools::names($app, $people);
                foreach ($games as &$entry) {
                    foreach ($entry['imports'] as &$import) {
                        $id = $import['imported_by'];
                        $import['imported_by'] = $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? "user {$id}"];
                    }
                    unset($import);
                }
                unset($entry);

                return Json::send($response, $games);
            });

            $group->get('/api/admin/builds/{game}/export', function (Request $request, Response $response, array $args) use ($app, $gameOf): Response {
                $file = $app->get(BuildImport::class)->export($gameOf($args));

                return Json::download($response, $file, "builds-{$file['game']}-" . substr((string) $file['exported_at'], 0, 10) . '.json');
            });

            $group->post('/api/admin/builds/{game}/import', function (Request $request, Response $response, array $args) use ($app, $gameOf): Response {
                $game = $gameOf($args);
                $dryRun = in_array($request->getQueryParams()['dry_run'] ?? null, ['1', 'true'], true);
                if (!$dryRun) {
                    // An import that has started finishes, even when the tab is closed while it runs.
                    ignore_user_abort(true);
                }
                @set_time_limit(BuildImport::MAX_SECONDS);

                $bytes = strlen((string) $request->getBody());
                if ($bytes === 0) {
                    // What PHP makes of a body larger than its post_max_size: the file arrives as nothing.
                    throw new HttpError(422, 'No file arrived. A file larger than this server accepts arrives empty.');
                }
                if ($bytes > BuildFile::MAX_BYTES) {
                    throw new HttpError(422, sprintf('The file is larger than %d MB, far more than a game\'s builds take.', BuildFile::MAX_BYTES / 1048576));
                }

                $user = currentUser($request);
                try {
                    $result = $app->get(BuildImport::class)->import(
                        $request->getParsedBody(),
                        $game,
                        $user,
                        $dryRun,
                        // In the import's transaction: committed with it, or not at all.
                        fn(array $answer) => $app->get(AuditLog::class)->record($request, $user->id, 'builds.imported', 'game', $game->value, [
                            'created' => $answer['created'],
                            'updated' => $answer['updated'],
                            'removed' => $answer['removed'],
                            'characters' => $answer['characters'],
                            'skipped' => count($answer['skipped']),
                        ]),
                    );
                } catch (BuildFileException $e) {
                    throw new HttpError(422, $e->getMessage());
                } catch (BuildImportBusy $e) {
                    throw new HttpError(409, $e->getMessage());
                }

                return Json::send($response, $result);
            });

            $group->post('/api/admin/catalog/{game}/{list}', function (Request $request, Response $response, array $args) use ($app, $gameOf): Response {
                $game = $gameOf($args);
                $list = (string) $args['list'];
                if (!in_array($list, CatalogSync::LISTS, true)) {
                    throw HttpError::notFound();
                }
                // A refresh that has started finishes, even when the tab is closed while it runs.
                ignore_user_abort(true);
                @set_time_limit(120);

                // One refresh of a list at a time; the claim outlives a request that dies, by two minutes.
                $cache = $app->get(Cache::class);
                $claim = "catalog-refresh:{$game->value}:{$list}";
                if (!$cache->add($claim, Clock::timestamp(), 120)) {
                    throw new HttpError(409, 'This list is being refreshed. Try again in a moment.');
                }
                $sync = $app->get(CatalogSync::class);
                try {
                    $count = $sync->syncList($game, $list);
                } catch (Throwable $e) {
                    Log::exception($e, "Catalog refresh of {$game->value} {$list}");

                    throw new HttpError(502, 'The game data provider could not be read. Try again later.');
                } finally {
                    $cache->forget($claim);
                }
                $app->get(AuditLog::class)->record($request, currentUser($request)->id, 'catalog.refreshed', 'game', $game->value, ['list' => $list, 'entries' => $count]);

                $lists = array_column($sync->lists($game), null, 'list');

                return Json::send($response, ['written' => $count] + $lists[$list]);
            });
        })
        ->add(requireRole($app, ...Role::STAFF));
};
