<?php

namespace App\Services\Analysis;

use App\Enums\Game;
use App\Models\AccountCharacter;
use App\Models\AccountItem;
use App\Models\AccountWeapon;
use App\Support\LevelStats;
use App\Support\MainStatValues;
use App\Support\SetBonus;
use App\Support\Stats;

/**
 * A character's totals as its character sheet would show them, worked out from the gear the account holds (the
 * owner's round of 2026-10-26, step 3; docs/analysis.md, "The totals from the gear"): a file export carries no
 * totals, and the stat targets are compared with the sheet.
 *
 * The sheet's base (5% CRIT Rate, 50% CRIT DMG, 100% Energy Recharge), the character at its level in its
 * ascension's phase with the ascension stat's value there (LevelStats::characterAt(); an ascension the import did
 * not name - stored as 0 - is the fewest the level needs), the weapon at its level (LevelStats::weaponAt(): base ATK
 * and the secondary stat), every equipped artifact's main stat (its value as imported, or MainStatValues' for an
 * export without one) and substats, and the 2-piece bonus of every set worn on two pieces or more (SetBonus). HP,
 * ATK and DEF are the base times its percentage plus the flat points. The keys are a showcase's (`final_stats`) -
 * the three totals, then every stat of the sheet's: Elemental Mastery, the crit pair, Energy Recharge, Healing
 * Bonus, Physical DMG and the character's own element's DMG with their value, nought included, another element's
 * DMG only where the gear gives some -, so the two read alike and a target compares alike. Not in it: a weapon's
 * passive, a set's 4-piece, the party's resonance - nothing conditional, and nothing the catalog's numbers do not
 * say.
 *
 * Null while the catalog keeps no stats of the character; without the weapon's stats, no ATK.
 */
final class SheetTotals
{
    /**
     * @param  array<string, AccountItem>  $items  slot => the piece equipped
     * @return array<string, float>|null
     */
    public static function compute(Game $game, AccountCharacter $owned, ?AccountWeapon $weapon, array $items): ?array
    {
        $character = LevelStats::characterAt($owned->character, $owned->level, $owned->ascension ?? 0);
        if ($character === null) {
            return null;
        }
        $sheet = TargetTiers::SHEET_BASE;
        $add = function (string $stat, float $value) use (&$sheet): void {
            $sheet[$stat] = ($sheet[$stat] ?? 0.0) + $value;
        };
        $add($character['ascension']['stat'], $character['ascension']['value']);

        $weaponStats = $weapon === null ? null : LevelStats::weaponAt($weapon->weapon, $weapon->level, $weapon->ascension ?? 0);
        if ($weaponStats !== null && $weaponStats['secondary'] !== null) {
            $add($weaponStats['secondary']['stat'], $weaponStats['secondary']['value']);
        }

        $sets = [];
        foreach ($items as $item) {
            $main = $item->main_value ?? MainStatValues::compute($game, $item->main_stat, $item->rarity, $item->level);
            if ($main !== null) {
                $add($item->main_stat, (float) $main);
            }
            foreach ($item->substats as $substat) {
                $add((string) $substat['key'], (float) $substat['value']);
            }
            $sets[$item->set->key] = ['set' => $item->set, 'count' => ($sets[$item->set->key]['count'] ?? 0) + 1];
        }
        foreach ($sets as ['set' => $set, 'count' => $count]) {
            if ($count >= 2) {
                $bonus = SetBonus::twoPiece($game, $set);
                if ($bonus !== null) {
                    $add($bonus['stat'], $bonus['value']);
                }
            }
        }

        $totals = ['hp' => round($character['hp'] * (1 + ($sheet['hp_'] ?? 0.0) / 100) + ($sheet['hp'] ?? 0.0))];
        // Without the weapon's stats the sheet's ATK cannot be said; without a weapon at all it is the character's own.
        if ($weapon === null || $weaponStats !== null) {
            $totals['atk'] = round(($character['atk'] + ($weaponStats['atk'] ?? 0.0)) * (1 + ($sheet['atk_'] ?? 0.0) / 100) + ($sheet['atk'] ?? 0.0));
        }
        $totals['def'] = round($character['def'] * (1 + ($sheet['def_'] ?? 0.0) / 100) + ($sheet['def'] ?? 0.0));
        // The sheet's stats, nought included - as a showcase sends them -, another element's DMG only with a value.
        $always = ['eleMas', 'critRate_', 'critDMG_', 'enerRech_', 'heal_', 'physical_dmg_', Stats::elementDmg($owned->character->element)];
        foreach (['eleMas', 'critRate_', 'critDMG_', 'enerRech_', 'heal_', 'physical_dmg_', ...array_map(Stats::elementDmg(...), $game->elements())] as $stat) {
            $value = $sheet[$stat] ?? 0.0;
            if ($value > 0 || in_array($stat, $always, true)) {
                $totals[$stat] = round($value, Stats::isPercent($stat) ? 1 : 0);
            }
        }

        return $totals;
    }
}
