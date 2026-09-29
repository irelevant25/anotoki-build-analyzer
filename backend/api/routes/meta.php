<?php

use App\App;
use App\Auth\AuthMode;
use App\Enums\Game;
use App\Support\Stats;
use App\Support\Talents;
use App\Version;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/meta - what the frontend loads before it draws anything: how people sign in, and the game
 * metadata for labels (slots, stats, talents). Public: it is read before anyone has signed in.
 */
return function (Slim\App $slim, App $app): void {
    $slim->get('/api/meta', function (Request $request, Response $response) use ($app): Response {
        $iam = AuthMode::of($app->config) === AuthMode::Iam;

        $game = function (Game $game): array {
            $statKeys = [...Stats::substatKeys($game)];
            foreach ($game->mainStatOptions() as $options) {
                array_push($statKeys, ...$options);
            }
            $statKeys = array_values(array_unique([...$statKeys, Stats::ELEMENTAL_DMG, 'spd_']));

            $stats = [];
            foreach ($statKeys as $key) {
                $stats[$key] = [
                    'label' => Stats::label($key, $game),
                    'short' => Stats::label($key, $game, true),
                    'percent' => Stats::isPercent($key),
                ];
            }

            $talentKeys = $game === Game::Genshin
                ? ['normal', 'skill', 'burst']
                : ['basic', 'skill', 'ultimate', 'talent', 'memo_skill', 'memo_talent'];

            return [
                'key' => $game->value,
                'label' => $game->label(),
                'slots' => array_map(fn ($slot) => ['key' => $slot, 'label' => $game->slotLabel($slot), 'variable' => in_array($slot, $game->variableSlots(), true)], $game->slots()),
                'weapon_label' => $game->weaponLabel(),
                'item_label' => $game->itemLabel(),
                'rank_label' => $game->rankLabel(),
                'refinement_prefix' => $game === Game::Genshin ? 'R' : 'S',
                'rank_prefix' => $game === Game::Genshin ? 'C' : 'E',
                'elements' => $game->elements(),
                'weapon_types' => $game->weaponTypes(),
                'stats' => $stats,
                'talents' => array_combine($talentKeys, array_map(fn ($key) => Talents::label($game, $key), $talentKeys)),
            ];
        };

        return Json::send($response, [
            'games' => array_map($game, Game::cases()),
            'version' => Version::CURRENT,
            'auth' => [
                'mode' => AuthMode::of($app->config)->value,
                'issuer' => $iam ? rtrim((string) $app->config->get('auth.issuer'), '/') : null,
                'client_id' => $iam ? (string) $app->config->get('auth.client_id') : null,
            ],
        ]);
    });
};
