<?php

namespace App\Services\Analysis;

use App\Enums\Game;
use App\Models\Build;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Support\MainStatValues;
use App\Support\MaxLevelStats;
use App\Support\SetBonus;
use App\Support\Stats;

/**
 * A build's stat targets, worked out from the game's numbers rather than read from anywhere (the owner's round of
 * 2026-10-26, docs/decisions.md; the method in docs/analysis.md, "Stat targets"):
 *
 * - the fixed sources of each stat on the character sheet at level 90: the sheet's base (5% CRIT Rate, 50% CRIT
 *   DMG, 100% Energy Recharge), the character's ascension stat, the weapon's secondary stat, the main stat of each
 *   artifact slot (a 5-star piece at +20; the flower's HP and the plume's ATK always, the three variable slots as
 *   the build asks or as the player wears them) and the 2-piece bonuses of the set option - the build's first, or
 *   the one the player wears where the build lists it;
 * - the stated targets of the build that hold at the character's constellation (a file's or an editor's - Energy
 *   Recharge mostly) are requirements: the substat rolls they take are paid first, and they are shown as they were
 *   stated, never tiered;
 * - each tier is a budget of useful substat rolls at the average roll (TIERS: 16, 24 and 32 of the 45 a set of
 *   five pieces holds), what is left after the requirements split among the build's substats by their priority
 *   (PRIORITY_SHARES, steeper than the scorer's weights: a target is what the top stats reach), no stat taking more
 *   rolls than the pieces that can roll it allow (ROLLS_PER_PIECE each), CRIT Rate and CRIT DMG as one share
 *   whatever their priorities, split so CRIT DMG is twice the CRIT Rate in combat - the sheet's plus what the set
 *   option's 4-piece adds, Blizzard Strayer's 40 % (SetBonus::fourPieceCritRate()) - with the combat CRIT Rate
 *   never past CRIT_RATE_CAP;
 * - HP, ATK and DEF go out as the sheet's totals, every other stat as its percentage or points.
 *
 * Nothing conditional is in the sources - a 4-piece bonus, a weapon's passive, a buff -, as nothing conditional is
 * on the sheet, which is what the targets are compared with (an account's `final_stats`); a 4-piece's CRIT Rate
 * enters the crit pair's balance alone.
 */
final class TargetTiers
{
    /** Each tier as useful substat rolls at the average roll, in order. */
    public const TIERS = ['basic' => 16, 'intermediate' => 24, 'advanced' => 32];

    /** The average of the four values a roll can take (70, 80, 90 and 100 % of the max). */
    public const AVERAGE_ROLL = 0.85;

    /**
     * A substat's share of the rolls by its priority (1 = the most important); lower priorities LOWEST_SHARE. Steep:
     * the owner's samples of the top players (2026-10-26) put nearly every roll into the top stats and a few into
     * the rest.
     */
    public const PRIORITY_SHARES = [1 => 1.0, 2 => 0.35, 3 => 0.2, 4 => 0.12];

    public const LOWEST_SHARE = 0.1;

    /**
     * The Energy Recharge the tiers stop at, by the build's role, where the build states no requirement: more is a
     * need no number of the game's gives, and the top players run far less than a priority's share would buy
     * (a sub DPS with Energy Recharge first at 190 % where the share bought 310 %). A stated requirement, higher
     * or lower, decides instead.
     */
    public const ENERGY_RECHARGE_MOST = ['main_dps' => 140.0, 'sub_dps' => 180.0, 'support' => 200.0];

    /** The most rolls of one stat per piece that can roll it (one whose main stat it is not). */
    public const ROLLS_PER_PIECE = 5;

    /** What the sheet shows of a stat with nothing equipped; every other stat starts at nought. */
    public const SHEET_BASE = ['critRate_' => 5.0, 'critDMG_' => 50.0, 'enerRech_' => 100.0];

    /** The CRIT Rate in combat the targets stop at: past it the rolls go to CRIT DMG (more is seldom of use). */
    public const CRIT_RATE_CAP = 85.0;

    /**
     * A build's targets for a character and a weapon the catalog knows the stats of: the rows and what they
     * rest on; null while the catalog lacks the character's stats or has no weapon of the build's with stats.
     *
     * @param  array<string, GameWeapon>  $weapons  the build's weapons the catalog knows, by key
     * @param  array<string, GameSet>  $sets  the sets of the build's options the catalog knows, by key
     * @param  GameWeapon|null  $inHand  the weapon the player wears (its stats preferred to the build's first)
     * @param  array<string, string>  $wornMainStats  slot => the main stat the player wears, for the sheet to follow where the build lists it
     * @param  int  $rank  the constellation the stated targets are held at
     * @param  int|null  $wornOption  the build's set option the player wears (its index), for the bonuses to follow; null: the first
     * @return array{rows: list<array<string, mixed>>, basis: array{weapon: string, sets: string|null, main_stats: array<string, string>}}|null
     */
    public static function forBuild(Build $build, GameCharacter $character, array $weapons, array $sets, ?GameWeapon $inHand, array $wornMainStats, int $rank, ?int $wornOption = null): ?array
    {
        $game = $character->game;
        $stats = MaxLevelStats::character($character);
        if ($stats === null) {
            return null;
        }

        // The weapon in hand where the catalog knows its stats, else the first of the build's it knows.
        $weapon = $inHand !== null && MaxLevelStats::weapon($inHand) !== null ? $inHand : null;
        foreach ($weapon === null ? $build->weapons : [] as $entry) {
            $candidate = $weapons[$entry['key']] ?? null;
            if ($candidate !== null && MaxLevelStats::weapon($candidate) !== null) {
                $weapon = $candidate;
                break;
            }
        }
        if ($weapon === null) {
            return null;
        }

        $element = $character->element;
        $mainStats = self::mainStats($game, $build, $wornMainStats, $element);
        $substats = self::resolve($build->substats ?: ItemScorer::fallbackSubstats($game, self::wantedMainStats($build, $element)), $element);
        $requirements = self::resolve(BuildEvaluator::targetsFor($build->stat_targets ?? [], $rank), $element);
        // The set option worn where it is one of the build's, else the build's first: its bonuses are the sheet's.
        $option = $build->sets[$wornOption ?? 0] ?? ($build->sets[0] ?? null);

        return [
            'rows' => self::rows(
                $game,
                $stats,
                (array) MaxLevelStats::weapon($weapon),
                $mainStats,
                self::bonuses($game, $option, $sets),
                $substats,
                $requirements,
                self::conditionalCritRate($option, $sets),
                self::ENERGY_RECHARGE_MOST[$build->role] ?? self::ENERGY_RECHARGE_MOST['sub_dps'],
            ),
            'basis' => [
                'weapon' => $weapon->name,
                'sets' => $option === null ? null : BuildEvaluator::optionPieces($option),
                'main_stats' => array_intersect_key($mainStats, array_flip($game->variableSlots())),
            ],
        ];
    }

    /**
     * The CRIT Rate a set option adds in combat through a 4-piece bonus (Blizzard Strayer's 40 %,
     * SetBonus::fourPieceCritRate()): not on the sheet, but part of the balance between CRIT Rate and CRIT DMG.
     *
     * @param  array<string, mixed>|null  $option
     * @param  array<string, GameSet>  $sets
     */
    private static function conditionalCritRate(?array $option, array $sets): float
    {
        if ($option === null) {
            return 0.0;
        }
        $pieces = $option['pieces'] ?? [];
        // The pieces the option takes, as bonuses() takes them: the first "choose" where only some are needed.
        foreach (array_slice($pieces, 0, BuildEvaluator::optionChoose($option) ?? count($pieces)) as $piece) {
            if ((int) ($piece['count'] ?? 0) < 4) {
                continue;
            }
            foreach ($piece['keys'] ?? [] as $key) {
                if (isset($sets[$key])) {
                    return SetBonus::fourPieceCritRate($sets[$key]);
                }
            }
        }

        return 0.0;
    }

    /**
     * The set keys of the build's options: the sets whose bonuses the targets may count.
     *
     * @return list<string>
     */
    public static function setKeys(Build $build): array
    {
        $keys = [];
        foreach ($build->sets as $option) {
            foreach ($option['pieces'] ?? [] as $piece) {
                array_push($keys, ...$piece['keys'] ?? []);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The rows: the requirements as stated, then every substat of the build with its three tiers.
     *
     * @param  array{hp: float, atk: float, def: float, ascension: array{stat: string, value: float}}  $character  at level 90
     * @param  array{atk: float, secondary: array{stat: string, value: float}|null}  $weapon  at level 90
     * @param  array<string, string>  $mainStats  slot => stat, every slot of the game, no placeholder
     * @param  list<array{stat: string, value: float}>  $bonuses  the set bonuses in effect
     * @param  list<array{stat: string, priority: int}>  $substats  no placeholder
     * @param  list<array{stat: string, min: int|float|null, max: int|float|null, from_rank?: int}>  $requirements  the stated targets that hold
     * @param  float  $critRateBonus  the CRIT Rate the set adds in combat, for the pair's balance
     * @param  float|null  $energyMost  the Energy Recharge the split stops at where no requirement states one; null: no stop
     * @return list<array{stat: string, tiers: array{basic: float, intermediate: float, advanced: float}|null, min: float|null, max: float|null, from_rank: int|null}>
     */
    public static function rows(
        Game $game,
        array $character,
        array $weapon,
        array $mainStats,
        array $bonuses,
        array $substats,
        array $requirements,
        float $critRateBonus = 0.0,
        ?float $energyMost = null,
    ): array {
        $fixed = self::fixedSources($game, $character, $weapon, $mainStats, $bonuses);
        $base = ['hp' => $character['hp'], 'atk' => $character['atk'] + $weapon['atk'], 'def' => $character['def']];
        $roll = fn(string $stat): float => (float) Stats::maxRoll($game, $stat) * self::AVERAGE_ROLL;
        $caps = [];
        foreach (Stats::substatKeys($game) as $stat) {
            $caps[$stat] = self::ROLLS_PER_PIECE * count(array_filter($mainStats, fn(string $main) => $main !== $stat));
        }

        // 1. The requirements, paid first: the rolls each one needs, in its stat's rolling substat.
        $paid = [];
        $rows = [];
        foreach ($requirements as $requirement) {
            $stat = $requirement['stat'];
            $rolling = self::rolling($game, $stat);
            if ($rolling !== null && $requirement['min'] !== null) {
                $perRoll = isset($base[$stat]) ? ($base[$stat] * $roll($rolling)) / 100 : $roll($rolling);
                $current = isset($base[$stat]) ? self::total($stat, $base, $fixed) : $fixed[$stat] ?? 0.0;
                $needed = (int) ceil(max(0.0, ((float) $requirement['min'] - $current) / $perRoll - 1e-9));
                $paid[$rolling] = min($caps[$rolling], ($paid[$rolling] ?? 0) + $needed);
            }
            // The bounds as stored ("+ 0": a number whatever the JSON held), the page writing them in the game's notation.
            $rows[] = [
                'stat' => $stat,
                'tiers' => null,
                'min' => $requirement['min'] === null ? null : $requirement['min'] + 0,
                'max' => $requirement['max'] === null ? null : $requirement['max'] + 0,
                'from_rank' => (int) ($requirement['from_rank'] ?? 0),
            ];
        }
        $spent = array_sum($paid);

        // In the split, Energy Recharge's rolls stop at the role's ceiling (the fixed sources may be past it already);
        // a requirement above paid its own rolls before, unbounded by it.
        $splitCaps = $caps;
        if ($energyMost !== null && isset($splitCaps['enerRech_'])) {
            $splitCaps['enerRech_'] = (int) min($splitCaps['enerRech_'], max(0.0, ceil(($energyMost - ($fixed['enerRech_'] ?? 0.0)) / $roll('enerRech_') - 1e-9)));
        }

        // 2. The build's substats that no requirement already fills, each with its share.
        $weights = [];
        $order = [];
        foreach ($substats as $entry) {
            $stat = self::rolling($game, $entry['stat']);
            if ($stat === null || isset($paid[$stat]) || isset($weights[$stat])) {
                continue;
            }
            $weights[$stat] = self::PRIORITY_SHARES[$entry['priority']] ?? self::LOWEST_SHARE;
            $order[$stat] = [$entry['priority'], count($order)];
        }
        // CRIT Rate and CRIT DMG, both in the split, are one share balanced by the 1:2 rule whatever their
        // priorities: a build that ranks CRIT Rate last because its set adds it in combat still needs a sheet a
        // player can read, and the set's CRIT Rate is in the balance.
        $critPair = isset($weights['critRate_'], $weights['critDMG_']);

        // 3. Each tier: what is left of its budget, split and added to the fixed sources.
        $values = [];
        foreach (self::TIERS as $tier => $budget) {
            $rolls = self::split(max(0.0, $budget - $spent), $weights, $splitCaps, $critPair, $fixed, $roll, $critRateBonus);
            foreach ($paid as $stat => $count) {
                $rolls[$stat] = ($rolls[$stat] ?? 0) + $count;
            }
            $sheet = $fixed;
            foreach ($rolls as $stat => $count) {
                $sheet[$stat] = ($sheet[$stat] ?? 0.0) + $count * $roll($stat);
            }
            $values[$tier] = $sheet;
        }

        uksort($order, fn(string $a, string $b) => $order[$a] <=> $order[$b]);
        foreach (array_keys($order) as $stat) {
            // HP%, ATK% and DEF% go out as the sheet's totals, under the flat key.
            $shown = array_search($stat, Stats::FLAT_TO_PERCENT, true) ?: $stat;
            $tiers = [];
            foreach (self::TIERS as $tier => $budget) {
                $tiers[$tier] = isset($base[$shown]) ? round(self::total($shown, $base, $values[$tier])) : round($values[$tier][$stat] ?? 0.0, 1);
            }
            $rows[] = ['stat' => $shown, 'tiers' => $tiers, 'min' => null, 'max' => null, 'from_rank' => null];
        }

        return $rows;
    }

    /**
     * Every stat the sheet shows before any substat, as the targets start from it.
     *
     * @return array<string, float>
     */
    private static function fixedSources(Game $game, array $character, array $weapon, array $mainStats, array $bonuses): array
    {
        $fixed = self::SHEET_BASE;
        $add = function (string $stat, float $value) use (&$fixed): void {
            $fixed[$stat] = ($fixed[$stat] ?? 0.0) + $value;
        };
        $add($character['ascension']['stat'], $character['ascension']['value']);
        if ($weapon['secondary'] !== null) {
            $add($weapon['secondary']['stat'], $weapon['secondary']['value']);
        }
        foreach ($mainStats as $stat) {
            $add($stat, (float) MainStatValues::compute($game, $stat, 5, $game->maxItemLevel(5)));
        }
        foreach ($bonuses as $bonus) {
            $add($bonus['stat'], $bonus['value']);
        }

        return $fixed;
    }

    /**
     * The rolls left to a tier among the open substats by their weights, no stat past its cap, CRIT Rate and CRIT
     * DMG as one share split by the 1:2 rule.
     *
     * @param  array<string, float>  $weights
     * @param  array<string, int>  $caps
     * @param  array<string, float>  $fixed
     * @return array<string, float> stat => rolls
     */
    private static function split(float $rolls, array $weights, array $caps, bool $critPair, array $fixed, callable $roll, float $critRateBonus): array
    {
        $open = $weights;
        if ($critPair) {
            $open['crit'] = $open['critRate_'] + $open['critDMG_'];
            $caps['crit'] = $caps['critRate_'] + $caps['critDMG_'];
            unset($open['critRate_'], $open['critDMG_']);
        }

        $given = [];
        while ($rolls > 1e-9 && $open !== []) {
            $total = array_sum($open);
            $capped = false;
            foreach ($open as $stat => $weight) {
                $room = $caps[$stat] - ($given[$stat] ?? 0.0);
                if (($rolls * $weight) / $total >= $room - 1e-9) {
                    $given[$stat] = ($given[$stat] ?? 0.0) + $room;
                    $rolls -= $room;
                    unset($open[$stat]);
                    $capped = true;
                    break;
                }
            }
            if ($capped) {
                continue;
            }
            foreach ($open as $stat => $weight) {
                $given[$stat] = ($given[$stat] ?? 0.0) + ($rolls * $weight) / $total;
            }
            $rolls = 0.0;
        }

        if ($critPair) {
            $joint = $given['crit'] ?? 0.0;
            unset($given['crit']);
            [$given['critRate_'], $given['critDMG_']] = self::critSplit(
                $joint,
                ($fixed['critRate_'] ?? 0.0) + $critRateBonus,
                $fixed['critDMG_'] ?? 0.0,
                $roll('critRate_'),
                $roll('critDMG_'),
                $caps['critRate_'],
                $caps['critDMG_'],
            );
        }

        return $given;
    }

    /**
     * CRIT Rate and CRIT DMG rolls out of one share, so that CRIT DMG is twice the CRIT Rate in combat - the sheet's
     * with what the set adds - and that CRIT Rate never passes CRIT_RATE_CAP, nor its pieces' cap.
     *
     * @return array{float, float}
     */
    private static function critSplit(float $joint, float $rateFixed, float $dmgFixed, float $rateRoll, float $dmgRoll, int $rateCap, int $dmgCap): array
    {
        // The most CRIT Rate rolls there can be: its pieces' cap, and the cap in combat.
        $rateMost = min((float) $rateCap, max(0.0, (self::CRIT_RATE_CAP - $rateFixed) / $rateRoll));
        $rate = ($dmgFixed + $joint * $dmgRoll - 2 * $rateFixed) / (2 * $rateRoll + $dmgRoll);
        $rate = min(max(0.0, $rate), $joint, $rateMost);
        $dmg = min($joint - $rate, (float) $dmgCap);
        $rate = min($joint - $dmg, $rateMost);

        return [$rate, $dmg];
    }

    /** The sheet's total of HP, ATK or DEF: the base times its percentage, plus its flat points. */
    private static function total(string $stat, array $base, array $sheet): float
    {
        return $base[$stat] * (1 + ($sheet[$stat . '_'] ?? 0.0) / 100) + ($sheet[$stat] ?? 0.0);
    }

    /** The substat that moves a stat: itself, the percentage behind a flat total, none for a stat no substat rolls. */
    private static function rolling(Game $game, string $stat): ?string
    {
        $stat = Stats::FLAT_TO_PERCENT[$stat] ?? $stat;

        return in_array($stat, Stats::substatKeys($game), true) && !isset(Stats::FLAT_TO_PERCENT[$stat]) ? $stat : null;
    }

    /**
     * The main stat of every slot: the flower's and the plume's as they always are, a variable slot's the player's
     * own where the build lists it, else the build's top choice; a slot the build says nothing of stays as worn, or
     * as the game's first option.
     *
     * @param  array<string, string>  $worn
     * @return array<string, string>
     */
    private static function mainStats(Game $game, Build $build, array $worn, ?string $element): array
    {
        $options = $game->mainStatOptions();
        $wanted = self::wantedMainStats($build, $element);
        $mainStats = [];
        foreach ($game->slots() as $slot) {
            $listed = array_column($wanted[$slot] ?? [], 'stat');
            $mainStats[$slot] = match (true) {
                $listed !== [] && in_array($worn[$slot] ?? null, $listed, true) => $worn[$slot],
                $listed !== [] => $listed[0],
                isset($worn[$slot]) && in_array($worn[$slot], $options[$slot], true) => $worn[$slot],
                default => $options[$slot][0],
            };
        }

        return $mainStats;
    }

    /**
     * The build's main stats per slot with the element in place of "Elemental DMG", the top priority first.
     *
     * @return array<string, list<array{stat: string, priority: int}>>
     */
    private static function wantedMainStats(Build $build, ?string $element): array
    {
        $wanted = [];
        foreach ($build->main_stats as $slot => $entries) {
            $resolved = self::resolve($entries, $element);
            usort($resolved, fn(array $a, array $b) => $a['priority'] <=> $b['priority']);
            $wanted[$slot] = $resolved;
        }

        return $wanted;
    }

    /**
     * The 2-piece bonuses of a set option: one for each piece it takes (the first "choose" pieces where only some
     * are needed), from the first set of the piece the catalog knows.
     *
     * @param  array<string, mixed>|null  $option
     * @param  array<string, GameSet>  $sets
     * @return list<array{stat: string, value: float}>
     */
    private static function bonuses(Game $game, ?array $option, array $sets): array
    {
        if ($option === null) {
            return [];
        }
        $pieces = $option['pieces'] ?? [];
        $choose = BuildEvaluator::optionChoose($option);
        $bonuses = [];
        foreach (array_slice($pieces, 0, $choose ?? count($pieces)) as $piece) {
            if ((int) ($piece['count'] ?? 0) < 2) {
                continue;
            }
            foreach ($piece['keys'] ?? [] as $key) {
                if (isset($sets[$key])) {
                    $bonus = SetBonus::twoPiece($game, $sets[$key]);
                    if ($bonus !== null) {
                        $bonuses[] = $bonus;
                    }
                    break;
                }
            }
        }

        return $bonuses;
    }

    /**
     * Entries with "Elemental DMG" read as the character's element.
     *
     * @template T of array
     * @param  list<T>  $entries
     * @return list<T>
     */
    private static function resolve(array $entries, ?string $element): array
    {
        return array_map(fn(array $entry) => ['stat' => Stats::resolveElemental($entry['stat'], $element)] + $entry, $entries);
    }
}
