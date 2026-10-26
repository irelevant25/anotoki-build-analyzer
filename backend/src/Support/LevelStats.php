<?php

namespace App\Support;

use App\Models\GameCharacter;
use App\Models\GameWeapon;

/**
 * A character's and a weapon's stats at a level, worked out from what the catalog keeps of them (`meta.stats`, as
 * genshin.anotoki.eu's catalog hands it on - Providers\GenshinCatalog): at the game's top level, 90, for the stat
 * targets, which are compared on the character sheet (that site's phases end at level 80); and at the level a
 * character or weapon stands at, for the sheet's totals worked out from the gear (App\Services\Analysis\SheetTotals).
 *
 * The game's growth curves enter here as the few constants the top level needs (docs/stats-and-slots.md, "Level
 * 90"); a level inside a phase is read off the phase's own two ends:
 * - a character's base HP, ATK and DEF grow by one curve per rarity, the same for all three; from level 80
 *   ascended to level 90 each grows by the level 1 value times CHARACTER_STEP (the curve's multiplier at 90 less
 *   its multiplier at 80: 5-star 8.0000 - 7.0972, 4-star 7.0000 - 6.1740), the ascension bonus already in the
 *   level 80 value and unchanged after it;
 * - a weapon's base ATK follows one of a dozen curves, told apart by its rarity and level 1 value: WEAPON_ATK_90
 *   gives the level 90 value for each pair the game has (a 5-star 48 is 674, a 4-star 42 is 510); a pair the
 *   table lacks keeps its level 80 value, a little below the truth;
 * - a weapon's secondary stat follows one curve whatever the weapon: WEAPON_SECONDARY_90 times its level 1 value
 *   (9.6% CRIT DMG at level 1 is 44.1% at 90);
 * - inside a phase (PHASE_LEVELS: 0 is levels 1-20, 6 is 80-90) a stat grows between the phase's start and end as
 *   the catalog keeps them (`meta.stats.phases`), taken as a straight line - the curves are near enough to one
 *   within a phase; phase 6 ends at the level 90 value above. A weapon's phase ends where the next begins (the
 *   ascension's step inside), the last at level 90. Without the phases (a catalog refreshed before they came) the
 *   level 90 values stand in, whatever the level;
 * - an ascension below the fewest the level needs is read as that fewest (leastAscension(): an import naming none
 *   stores 0, and a level 90 at phase 0 is no state the game has), one past 6 as 6.
 */
final class LevelStats
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

    /** The levels of each ascension phase: its first (ascended) and its last. */
    public const PHASE_LEVELS = [0 => [1, 20], 1 => [20, 40], 2 => [40, 50], 3 => [50, 60], 4 => [60, 70], 5 => [70, 80], 6 => [80, 90]];

    /**
     * The fewest ascensions a level needs - the phase a character or weapon at that level is in at the least: level
     * 20 may be ascended once or not yet, level 21 is ascended once. What a level is read at when the ascension kept
     * says less (an import that names none stores 0).
     */
    public static function leastAscension(int $level): int
    {
        foreach (self::PHASE_LEVELS as $phase => [, $to]) {
            if ($level <= $to) {
                return $phase;
            }
        }

        return 6;
    }

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
     * The same at a level the character stands at, in the phase its ascensions put it in - never below the fewest
     * the level needs - with the level held inside the phase; the level 90 values where the catalog keeps no phases.
     *
     * @return array{hp: float, atk: float, def: float, ascension: array{stat: string, value: float}}|null
     */
    public static function characterAt(GameCharacter $character, int $level, int $ascension): ?array
    {
        $top = self::character($character);
        if ($top === null) {
            return null;
        }
        $phase = max(min(6, $ascension), self::leastAscension($level));
        $own = self::phase($character->meta['stats']['phases'] ?? null, $phase);
        if ($own === null || !self::levels($own['start'] ?? null) || ($phase < 6 && !self::levels($own['end'] ?? null))) {
            return $top;
        }
        [$from, $to] = self::PHASE_LEVELS[$phase];
        $along = $to - $from > 0 ? (max($from, min($to, $level)) - $from) / ($to - $from) : 0;
        $stats = $character->meta['stats'];
        $at = fn(string $stat) => round(
            (float) $own['start'][$stat] +
                $along *
                    (($phase === 6 ? (float) $own['start'][$stat] + (float) $stats['base'][$stat] * self::CHARACTER_STEP[$character->rarity] : (float) $own['end'][$stat]) -
                        (float) $own['start'][$stat]),
            1,
        );

        return [
            'hp' => $at('hp'),
            'atk' => $at('atk'),
            'def' => $at('def'),
            'ascension' => ['stat' => $top['ascension']['stat'], 'value' => self::number($own['ascension'] ?? null) ? (float) $own['ascension'] : $top['ascension']['value']],
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

    /**
     * The same at a level the weapon stands at, in its ascension's phase - never below the fewest the level needs -;
     * the level 90 values where the catalog keeps no phases.
     *
     * @return array{atk: float, secondary: array{stat: string, value: float}|null}|null
     */
    public static function weaponAt(GameWeapon $weapon, int $level, int $ascension): ?array
    {
        $top = self::weapon($weapon);
        if ($top === null) {
            return null;
        }
        $phase = max(min(6, $ascension), self::leastAscension($level));
        $phases = $weapon->meta['stats']['phases'] ?? null;
        $own = self::phase($phases, $phase);
        $next = $phase < 6 ? self::phase($phases, $phase + 1) : null;
        if ($own === null || !self::number($own['atk'] ?? null) || ($phase < 6 && ($next === null || !self::number($next['atk'] ?? null)))) {
            return $top;
        }
        [$from, $to] = self::PHASE_LEVELS[$phase];
        $along = $to - $from > 0 ? (max($from, min($to, $level)) - $from) / ($to - $from) : 0;
        $endAtk = $phase === 6 ? $top['atk'] : (float) $next['atk'];
        $secondary = null;
        if ($top['secondary'] !== null && self::number($own['secondary'] ?? null)) {
            $endSecondary = $phase === 6 ? $top['secondary']['value'] : (float) ($next['secondary'] ?? $own['secondary']);
            $secondary = ['stat' => $top['secondary']['stat'], 'value' => round((float) $own['secondary'] + $along * ($endSecondary - (float) $own['secondary']), 1)];
        }

        return [
            'atk' => round((float) $own['atk'] + $along * ($endAtk - (float) $own['atk']), 1),
            'secondary' => $secondary ?? $top['secondary'],
        ];
    }

    /** The entry of one phase among the catalog's, or null. */
    private static function phase(mixed $phases, int $phase): ?array
    {
        if (!is_array($phases)) {
            return null;
        }
        foreach ($phases as $entry) {
            if (is_array($entry) && ($entry['phase'] ?? null) === $phase) {
                return $entry;
            }
        }

        return null;
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
