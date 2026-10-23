<?php

use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Support\JsonBody;
use Anotoki\Lib\Support\Refusal;
use App\Admin\AdminTools;
use App\App;
use App\Auth\Role;
use App\Db\Db;
use App\Enums\Game;
use App\Models\GameCharacter;
use App\Repositories\Builds;
use App\Repositories\CatalogRows;
use App\Services\Builds\BuildEditConflict;
use App\Services\Builds\BuildEditException;
use App\Services\Builds\BuildEditor;
use App\Services\Builds\BuildFile;
use App\Services\Builds\BuildFileException;
use App\Services\Builds\BuildImport;
use App\Services\Builds\BuildImportBusy;
use App\Services\Builds\BuildImportPlanChanged;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogAnswer;
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
 *                                             carrying them was made, the latest imports with who made each, the
 *                                             catalog's lists, and the characters with builds with what the editors
 *                                             did to them (`editing`: BuildEditor::overview())
 *   GET  /api/admin/builds/{game}/catalog     what the builds editor picks from and holds a build to: the game's
 *                                             facts and the catalog's characters, weapons and sets
 *   GET  /api/admin/builds/{game}/characters/{key}   a character's builds as the editor edits them (BuildEditor::load())
 *   PUT  /api/admin/builds/{game}/characters/{key}   <the character's builds as the editor leaves them>: saved
 *                                             (BuildEditor::save()), answered as the GET; 422 invalid_body,
 *                                             invalid_build, invalid_note or names_another_site with the build and the
 *                                             path in it (nothing written), 409 busy while an import or another save
 *                                             writes, 409 changed when the builds changed since they were loaded
 *   GET  /api/admin/builds/{game}/export      the game's builds as a file to save, "builds-genshin-2026-10-20.json"
 *                                             (format: BuildFile)
 *   POST /api/admin/builds/{game}/import[?dry_run=1][&file=<ids>|all&plan=<hash>]   <file>: the file's JSON as the
 *                                             body. The game's builds become the file's but for what editors did by
 *                                             hand (BuildImport) - or, as a dry run, what that would do, every edit
 *                                             standing, nothing written: with the questions an editor answers and
 *                                             their plan. `file` names the questions answered with the file (their
 *                                             ids, comma-separated, or all) and comes with the preview's `plan`.
 *                                             422 when the file does not fit or the choices name no question
 *                                             (nothing written), 409 while another import writes, 409 plan_changed
 *                                             when the questions are not the preview's any more
 *   POST /api/admin/catalog/{game}/{list}[?force=1]   one list of the catalog (characters, weapons, sets)
 *                                             refreshed from the game's provider - a conditional request unless
 *                                             forced: {outcome: changed | unchanged, written, list, entries,
 *                                             refreshed_at, problem}; 409 while another refresh of it runs, 502 when
 *                                             the provider is closed, has no such list or cannot be read (its
 *                                             reason as the message)
 *
 * An import, a save of the editor's and a refresh are in the audit log (builds.imported and builds.edited, each in its
 * own transaction; catalog.refreshed). The group carries its role check, so a route added to it cannot go without one.
 */
return function (Slim\App $slim, App $app): void {
    $gameOf = fn(array $args): Game => Game::tryFrom((string) $args['game']) ?? throw HttpError::notFound();
    // A character of the game's catalog, by its key: one the catalog lacks has no builds to edit.
    $characterOf = fn(Game $game, array $args): GameCharacter => $app->get(CatalogRows::class)->findByKey($game, Catalog::CHARACTER, (string) $args['key']) ?? throw HttpError::notFound();

    $slim
        ->group('', function (RouteCollectorProxy $group) use ($app, $gameOf, $characterOf) {
            $group->get('/api/admin/builds', function (Request $request, Response $response) use ($app): Response {
                $counts = $app->get(Builds::class)->counts();
                $imports = $app->get(BuildImport::class);
                $catalog = $app->get(CatalogSync::class);
                $editor = $app->get(BuildEditor::class);

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
                        'editing' => $editor->overview($game),
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

            $group->get('/api/admin/builds/{game}/catalog', function (Request $request, Response $response, array $args) use ($app, $gameOf): Response {
                return Json::send($response, $app->get(BuildEditor::class)->catalog($gameOf($args)));
            });

            $group->get('/api/admin/builds/{game}/characters/{key}', function (Request $request, Response $response, array $args) use ($app, $gameOf, $characterOf): Response {
                $game = $gameOf($args);

                return Json::send($response, $app->get(BuildEditor::class)->load($game, $characterOf($game, $args)));
            });

            $group->put('/api/admin/builds/{game}/characters/{key}', function (Request $request, Response $response, array $args) use ($app, $gameOf, $characterOf): Response {
                $game = $gameOf($args);
                $character = $characterOf($game, $args);
                $body = JsonBody::read($request, BuildEditor::MAX_BYTES);
                if ($body instanceof Refusal) {
                    throw new HttpError($body->status, $body->message, ['code' => $body->code]);
                }
                // Objects as arrays, as the builds reader takes them ({} and [] alike: a slot's main stats can be either).
                $payload = BuildEditor::arrays($body);
                $user = currentUser($request);
                try {
                    $state = $app->get(BuildEditor::class)->save(
                        $game,
                        $character,
                        $payload,
                        // In the save's transaction: committed with it, or not at all.
                        fn(array $details) => $app->get(AuditLog::class)->record($request, $user->id, 'builds.edited', 'character', $character->key, $details),
                    );
                } catch (BuildFileException $e) {
                    throw new HttpError(422, sprintf('Build %d: %s', ($e->build ?? 0) + 1, $e->getMessage()), ['code' => 'invalid_build', 'build' => $e->build, 'path' => $e->path]);
                } catch (BuildEditException $e) {
                    throw new HttpError(422, $e->getMessage(), array_filter(['code' => $e->kind, 'build' => $e->build, 'path' => $e->path], fn($value) => $value !== null));
                } catch (BuildImportBusy $e) {
                    throw new HttpError(409, $e->getMessage(), ['code' => 'busy']);
                } catch (BuildEditConflict $e) {
                    throw new HttpError(409, $e->getMessage(), ['code' => 'changed']);
                }

                return Json::send($response, $state);
            });

            $group->get('/api/admin/builds/{game}/export', function (Request $request, Response $response, array $args) use ($app, $gameOf): Response {
                $file = $app->get(BuildImport::class)->export($gameOf($args));

                return Json::download($response, $file, "builds-{$file['game']}-" . substr((string) $file['exported_at'], 0, 10) . '.json');
            });

            $group->post('/api/admin/builds/{game}/import', function (Request $request, Response $response, array $args) use ($app, $gameOf): Response {
                $game = $gameOf($args);
                $query = $request->getQueryParams();
                $dryRun = in_array($query['dry_run'] ?? null, ['1', 'true'], true);
                // The questions answered with the file: "all", or their ids ("b12,r3"); none, every edit stands.
                $choices = is_string($query['file'] ?? null) ? trim($query['file']) : '';
                $fileWins = $choices === 'all' ? true : ($choices === '' ? [] : array_values(array_unique(explode(',', $choices))));
                if (is_array($fileWins) && array_filter($fileWins, fn(string $id) => !preg_match('/^[br]\d{1,18}\z/', $id)) !== []) {
                    throw new HttpError(422, 'The choices are not the ids of an import\'s questions.');
                }
                $plan = is_string($query['plan'] ?? null) && preg_match('/^[0-9a-f]{64}\z/', $query['plan']) ? $query['plan'] : null;
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
                            'questions' => count($answer['questions']),
                            'file_taken' => $answer['file_taken'],
                            'edits_kept' => $answer['edits_kept'],
                        ]),
                        $fileWins,
                        $plan,
                    );
                } catch (BuildFileException $e) {
                    throw new HttpError(422, $e->getMessage());
                } catch (BuildImportBusy $e) {
                    throw new HttpError(409, $e->getMessage());
                } catch (BuildImportPlanChanged $e) {
                    throw new HttpError(409, $e->getMessage(), ['code' => 'plan_changed']);
                }

                return Json::send($response, $result);
            });

            $group->post('/api/admin/catalog/{game}/{list}', function (Request $request, Response $response, array $args) use ($app, $gameOf): Response {
                $game = $gameOf($args);
                $list = (string) $args['list'];
                if (!in_array($list, CatalogSync::LISTS, true)) {
                    throw HttpError::notFound();
                }
                $force = in_array($request->getQueryParams()['force'] ?? null, ['1', 'true'], true);
                // A refresh that has started finishes, even when the tab is closed while it runs.
                ignore_user_abort(true);
                @set_time_limit(120);

                $sync = $app->get(CatalogSync::class);
                try {
                    $result = $sync->refresh($game, $list, $force);
                } catch (Throwable $e) {
                    Log::exception($e, "Catalog refresh of {$game->value} {$list}");

                    throw new HttpError(502, 'The game data provider could not be read. Try again later.');
                }
                if ($result['outcome'] === CatalogSync::BUSY) {
                    throw new HttpError(409, (string) $result['problem']);
                }
                if (!in_array($result['outcome'], [CatalogAnswer::CHANGED, CatalogAnswer::UNCHANGED], true)) {
                    throw new HttpError(502, (string) $result['problem']);
                }
                $app->get(AuditLog::class)->record($request, currentUser($request)->id, 'catalog.refreshed', 'game', $game->value, [
                    'list' => $list,
                    'outcome' => $result['outcome'],
                    'entries' => $result['written'],
                ]);

                $lists = array_column($sync->lists($game), null, 'list');

                return Json::send($response, ['outcome' => $result['outcome'], 'written' => $result['written']] + $lists[$list]);
            });
        })
        ->add(requireRole($app, ...Role::STAFF));
};
