<?php

namespace App\Support;

use App\Enums\Game;

/**
 * Main stat values for scanner exports that only contain the main stat *type* (GOOD): interpolated between +0
 * and max level (accurate to ~0.1).
 */
final class MainStatValues
{
    /** Genshin: rarity => stat => [value at +0, value at max level]. */
    private const GENSHIN = [
        5 => [
            'hp' => [717, 4780],
            'atk' => [47, 311],
            'hp_' => [7.0, 46.6],
            'atk_' => [7.0, 46.6],
            'def_' => [8.7, 58.3],
            'eleMas' => [28.0, 186.5],
            'enerRech_' => [7.8, 51.8],
            'critRate_' => [4.7, 31.1],
            'critDMG_' => [9.3, 62.2],
            'heal_' => [5.4, 35.9],
            'elemental' => [7.0, 46.6],
            'physical_dmg_' => [8.7, 58.3],
        ],
        4 => [
            'hp' => [645, 3571],
            'atk' => [42, 232],
            'hp_' => [6.3, 34.8],
            'atk_' => [6.3, 34.8],
            'def_' => [7.9, 43.5],
            'eleMas' => [25.2, 139.3],
            'enerRech_' => [7.0, 38.7],
            'critRate_' => [4.2, 23.2],
            'critDMG_' => [8.4, 46.4],
            'heal_' => [4.8, 26.8],
            'elemental' => [6.3, 34.8],
            'physical_dmg_' => [7.9, 43.5],
        ],
    ];

    public static function compute(Game $game, string $stat, int $rarity, int $level): ?float
    {
        $lookup = str_ends_with($stat, '_dmg_') && $stat !== 'physical_dmg_' ? 'elemental' : $stat;

        $range = match ($game) {
            Game::Genshin => self::GENSHIN[$rarity][$lookup] ?? null,
        };
        if (!$range) {
            return null;
        }
        $max = $game->maxItemLevel($rarity);
        $value = $range[0] + (($range[1] - $range[0]) * min($level, $max)) / $max;

        return round($value, Stats::isPercent($stat) ? 1 : 0);
    }
}
