<?php

use App\App;
use App\Db\Db;
use App\Enums\Game;
use App\Models\GameCharacter;
use App\Presenters\BuildPresenter;
use App\Presenters\Icons;
use App\Repositories\Builds;
use App\Repositories\CatalogRows;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Catalog\Catalog;
use App\Support\Clock;
use App\Web\HttpError;
use App\Web\Input;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * The builds browser, independent of any account. Public: signed in or not, everybody reads the same, and
 * nothing in it is anybody's own.
 *
 *   GET /api/builds/{game}        characters with builds, with how many and from which sources
 *   GET /api/builds/{game}/{key}  every build of one character, from the switched-on sources
 */
return function (Slim\App $slim, App $app): void {
    $slim
        ->group('/api/builds', function (RouteCollectorProxy $group) use ($app) {
            $group->get('/{game}', function (Request $request, Response $response, array $args) use ($app): Response {
                $input = new Input(['game' => $args['game']]);
                $game = $input->game('game', required: true);
                $input->valid();

                $registry = $app->get(BuildSourceRegistry::class);
                $byCharacter = [];
                foreach ($app->get(Builds::class)->summaries($game) as $row) {
                    $byCharacter[(int) $row['game_character_id']][] = $row;
                }

                $characters = $app->get(CatalogRows::class)->byIds(Catalog::CHARACTER, array_keys($byCharacter));
                usort($characters, function (GameCharacter $a, GameCharacter $b) {
                    // Newest release first (unknown dates first, as the database sorts them), then by name.
                    $ta = $a->released_at?->getTimestamp() ?? PHP_INT_MAX;
                    $tb = $b->released_at?->getTimestamp() ?? PHP_INT_MAX;

                    return [$tb, $a->name] <=> [$ta, $b->name];
                });

                return Json::send(
                    $response,
                    array_map(function (GameCharacter $character) use ($byCharacter, $registry) {
                        $list = $byCharacter[$character->id];
                        $changed = array_filter(array_map(fn($row) => Db::time($row['changed_at']), $list));

                        return [
                            'key' => $character->key,
                            'name' => $character->name,
                            'element' => $character->element,
                            'rarity' => $character->rarity,
                            'weapon_type' => $character->weapon_type,
                            'icon_url' => Icons::of($character),
                            'released_at' => $character->released_at?->format('Y-m-d'),
                            'builds' => count($list),
                            'sources' => array_values(array_unique(array_map(fn($row) => $registry->label($row['source_key']), $list))),
                            'changed_at' => $changed === [] ? null : Clock::iso(max($changed)),
                        ];
                    }, $characters),
                );
            });

            $group->get('/{game}/{key}', function (Request $request, Response $response, array $args) use ($app): Response {
                $game = Game::tryFrom((string) $args['game']) ?? throw HttpError::notFound();
                $character = $app->get(CatalogRows::class)->findByKey($game, Catalog::CHARACTER, (string) $args['key']);
                if (!($character instanceof GameCharacter)) {
                    throw HttpError::notFound();
                }

                $builds = $app->get(Builds::class)->forCharacters([$character->id])[$character->id] ?? [];

                return Json::send($response, [
                    'character' => [
                        'key' => $character->key,
                        'name' => $character->name,
                        'element' => $character->element,
                        'rarity' => $character->rarity,
                        'weapon_type' => $character->weapon_type,
                        'icon_url' => Icons::of($character),
                    ],
                    'builds' => $app->get(BuildPresenter::class)->collection($builds),
                ]);
            });
        })
        ->add(optionalSignIn($app));
};
