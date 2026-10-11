<?php

use Anotoki\Lib\Auth\Http\AuthRoutes;
use Anotoki\Lib\Auth\IamClient;
use Anotoki\Lib\Auth\SignInRule;
use App\App;
use App\Auth\Visitors;
use App\Enums\Game;
use App\Support\Stats;
use App\Support\Talents;
use App\Version;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/meta - what the frontend loads before it draws anything: the anotoki library's boot configuration
 * for signing in (`auth`, AuthRoutes::configBody(): the IAM's address and this site's client id there; whether
 * the IAM requires everybody to sign in here - login_required, read from the IAM by the server, so the page
 * never asks it; true when the IAM has never answered - and whether a sign-in can be offered at all -
 * sign_in_available: false once the IAM answered that it does not know this site, and then the pages offer no
 * sign-in and its hint never sends anybody there), how many days what a visitor imports is kept after their
 * last visit (visitors.keep_days, for the notice), and the game metadata for labels (slots, stats, talents).
 * Public, whether or not sign-in is required: it is read before anyone has signed in, with no token.
 */
return function (Slim\App $slim, App $app): void {
    $slim->get('/api/meta', function (Request $request, Response $response) use ($app): Response {
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
            'auth' => AuthRoutes::configBody($app->get(SignInRule::class), $app->get(IamClient::class)),
            'visitors' => [
                'keep_days' => $app->get(Visitors::class)->keepDays(),
            ],
        ]);
    });
};
