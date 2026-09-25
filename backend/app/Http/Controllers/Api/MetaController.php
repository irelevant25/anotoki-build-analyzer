<?php

namespace App\Http\Controllers\Api;

use App\Enums\Game;
use App\Http\Controllers\Controller;
use App\Support\Stats;
use App\Support\Talents;

/**
 * Static game metadata the frontend needs for labels (slots, stats, talents).
 */
class MetaController extends Controller
{
    public function __invoke(): array
    {
        return [
            'games' => array_map(fn (Game $game) => $this->game($game), Game::cases()),
            'version' => '0.1.0',
        ];
    }

    private function game(Game $game): array
    {
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
    }
}
