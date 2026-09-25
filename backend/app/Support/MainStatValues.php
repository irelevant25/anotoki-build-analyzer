<?php

namespace App\Support;

use App\Enums\Game;

/**
 * Main stat values for scanner exports that only contain the main stat *type* (GOOD, Reliquary Archiver,
 * HSR-Scanner). Genshin values are interpolated between +0 and max level (accurate to ~0.1);
 * HSR values follow the game's linear base + step * level formula.
 */
final class MainStatValues
{
    /** Genshin: rarity => stat => [value at +0, value at max level]. */
    private const GENSHIN = [
        5 => [
            'hp' => [717, 4780], 'atk' => [47, 311],
            'hp_' => [7.0, 46.6], 'atk_' => [7.0, 46.6], 'def_' => [8.7, 58.3],
            'eleMas' => [28.0, 186.5], 'enerRech_' => [7.8, 51.8],
            'critRate_' => [4.7, 31.1], 'critDMG_' => [9.3, 62.2], 'heal_' => [5.4, 35.9],
            'elemental' => [7.0, 46.6], 'physical_dmg_' => [8.7, 58.3],
        ],
        4 => [
            'hp' => [645, 3571], 'atk' => [42, 232],
            'hp_' => [6.3, 34.8], 'atk_' => [6.3, 34.8], 'def_' => [7.9, 43.5],
            'eleMas' => [25.2, 139.3], 'enerRech_' => [7.0, 38.7],
            'critRate_' => [4.2, 23.2], 'critDMG_' => [8.4, 46.4], 'heal_' => [4.8, 26.8],
            'elemental' => [6.3, 34.8], 'physical_dmg_' => [7.9, 43.5],
        ],
    ];

    /** HSR 5-star: stat => [base, step per level]. */
    private const HSR = [
        'hp' => [112.896, 39.5136], 'atk' => [56.448, 19.7568],
        'hp_' => [6.912, 2.4192], 'atk_' => [6.912, 2.4192], 'def_' => [8.64, 3.024],
        'critRate_' => [5.184, 1.8144], 'critDMG_' => [10.368, 3.6288], 'heal_' => [5.5296, 1.9354],
        'effectHitRate_' => [6.912, 2.4192], 'spd' => [4.032, 1.4],
        'elemental' => [6.2208, 2.1773], 'physical_dmg_' => [6.2208, 2.1773],
        'breakEffect_' => [10.368, 3.6277], 'energyRegen_' => [3.1104, 1.0886],
    ];

    public static function compute(Game $game, string $stat, int $rarity, int $level): ?float
    {
        $lookup = str_ends_with($stat, '_dmg_') && $stat !== 'physical_dmg_' ? 'elemental' : $stat;

        if ($game === Game::Genshin) {
            $range = self::GENSHIN[$rarity][$lookup] ?? null;
            if (! $range) {
                return null;
            }
            $max = $game->maxItemLevel($rarity);
            $value = $range[0] + ($range[1] - $range[0]) * min($level, $max) / $max;

            return round($value, Stats::isPercent($stat) ? 1 : 0);
        }

        if ($rarity !== 5 || ! isset(self::HSR[$lookup])) {
            return null;
        }
        [$base, $step] = self::HSR[$lookup];

        return round($base + $step * min($level, 15), 3);
    }
}
