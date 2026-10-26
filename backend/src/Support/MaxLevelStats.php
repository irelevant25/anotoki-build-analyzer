<?php

namespace App\Support;

use App\Models\GameCharacter;
use App\Models\GameWeapon;

/**
 * A character's and a weapon's stats at the game's top level (90), worked out from what the catalog keeps of them
 * (`meta.stats`, as genshin.anotoki.eu's catalog hands it on - Providers\GenshinCatalog): the stat targets are
 * compared on the character sheet, which shows the level 90 values, and that site's phases end at level 80.
 *
 * The game's growth curves enter here, as the few constants the top level needs (docs/stats-and-slots.md, "Level
 * 90"):
 * - a character's base HP, ATK and DEF grow by one curve per rarity, the same for all three; from level 80
 *   ascended to level 90 each grows by the level 1 value times CHARACTER_STEP (the curve's multiplier at 90 less
 *   its multiplier at 80: 5-star 8.0000 - 7.0972, 4-star 7.0000 - 6.1740), the ascension bonus already in the
 *   level 80 value and unchanged after it;
 * - a weapon's base ATK follows one of a dozen curves, told apart by its rarity and level 1 value: WEAPON_ATK_90
 *   gives the level 90 value for each pair the game has (a 5-star 48 is 674, a 4-star 42 is 510); a pair the
 *   table lacks keeps its level 80 value, a little below the truth;
 * - a weapon's secondary stat follows one curve whatever the weapon: WEAPON_SECONDARY_90 times its level 1 value
 *   (9.6% CRIT DMG at level 1 is 44.1% at 90).
 */
final class MaxLevelStats
{
    /** Level 90 less level 80 ascended, in level 1 values, per rarity. */
    public const CHARACTER_STEP = [5 => 0.9028, 4 => 0.826];

    /** Base ATK at level 90 by rarity and level 1 base ATK. */
    public const WEAPON_ATK_90 = [
        5 => [44 => 542, 46 => 608, 48 => 674, 49 => 741],
        4 => [39 => 440, 41 => 454, 42 => 510, 44 => 565, 45 => 620],
        3 => [38 => 354, 39 => 401, 40 => 448],
    ];

    /** A secondary stat at level 90, in level 1 values. */
    public const WEAPON_SECONDARY_90 = 4.59375;

    /**
     * A character's base HP, ATK and DEF at level 90 and its ascension stat with its whole value; null while the
     * catalog has no stats of it.
     *
     * @return array{hp: float, atk: float, def: float, ascension: array{stat: string, value: float}}|null
     */
    public static function character(GameCharacter $character): ?array
    {
        $stats = $character->meta['stats'] ?? null;
        $step = self::CHARACTER_STEP[$character->rarity ?? 0] ?? null;
        if (!is_array($stats) || $step === null || !self::levels($stats['base'] ?? null) || !self::levels($stats['lv80'] ?? null) || !self::stat($stats['ascension'] ?? null)) {
            return null;
        }

        $at90 = fn(string $stat) => round((float) $stats['lv80'][$stat] + (float) $stats['base'][$stat] * $step, 1);

        return [
            'hp' => $at90('hp'),
            'atk' => $at90('atk'),
            'def' => $at90('def'),
            'ascension' => ['stat' => (string) $stats['ascension']['stat'], 'value' => (float) $stats['ascension']['value']],
        ];
    }

    /**
     * A weapon's base ATK at level 90 and its secondary stat with its level 90 value (null for a weapon without
     * one); null while the catalog has no stats of it.
     *
     * @return array{atk: float, secondary: array{stat: string, value: float}|null}|null
     */
    public static function weapon(GameWeapon $weapon): ?array
    {
        $stats = $weapon->meta['stats'] ?? null;
        if (!is_array($stats) || !self::number($stats['base_atk'] ?? null) || !self::number($stats['lv80_atk'] ?? null)) {
            return null;
        }
        $base = (float) $stats['base_atk'];
        $at90 = self::WEAPON_ATK_90[$weapon->rarity ?? 0][(int) round($base)] ?? null;
        $secondary = $stats['secondary'] ?? null;

        return [
            'atk' => (float) ($at90 ?? $stats['lv80_atk']),
            'secondary' => self::stat($secondary) ? ['stat' => (string) $secondary['stat'], 'value' => round((float) $secondary['value'] * self::WEAPON_SECONDARY_90, 1)] : null,
        ];
    }

    private static function levels(mixed $level): bool
    {
        return is_array($level) && self::number($level['hp'] ?? null) && self::number($level['atk'] ?? null) && self::number($level['def'] ?? null);
    }

    private static function stat(mixed $stat): bool
    {
        return is_array($stat) && is_string($stat['stat'] ?? null) && $stat['stat'] !== '' && self::number($stat['value'] ?? null);
    }

    private static function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && $value >= 0;
    }
}
