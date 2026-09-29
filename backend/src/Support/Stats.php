<?php

namespace App\Support;

use App\Enums\Game;

/**
 * Canonical stat keys shared by importers, scrapers and the analyzer.
 *
 * Genshin keys follow the GOOD format (hp_, atk_, critRate_, enerRech_ ...);
 * HSR keys use the same style (spd, effectHitRate_, breakEffect_, energyRegen_ ...).
 * A trailing underscore marks a percentage stat.
 */
final class Stats
{
    /** Placeholder used by builds that say "Elemental DMG" without naming the element. */
    public const ELEMENTAL_DMG = 'elemental_dmg_';

    /**
     * Max value of a single 5-star substat roll.
     */
    private const MAX_ROLL = [
        'genshin' => [
            'hp' => 298.75, 'atk' => 19.45, 'def' => 23.15,
            'hp_' => 5.83, 'atk_' => 5.83, 'def_' => 7.29,
            'eleMas' => 23.31, 'enerRech_' => 6.48,
            'critRate_' => 3.89, 'critDMG_' => 7.77,
        ],
        'hsr' => [
            'hp' => 42.337549, 'atk' => 21.168773, 'def' => 21.168773,
            'hp_' => 4.32, 'atk_' => 4.32, 'def_' => 5.4,
            'spd' => 2.6, 'critRate_' => 3.24, 'critDMG_' => 6.48,
            'effectHitRate_' => 4.32, 'effectRes_' => 4.32, 'breakEffect_' => 6.48,
        ],
    ];

    private const LABELS = [
        'hp' => ['HP', 'HP'],
        'hp_' => ['HP%', 'HP%'],
        'atk' => ['ATK', 'ATK'],
        'atk_' => ['ATK%', 'ATK%'],
        'def' => ['DEF', 'DEF'],
        'def_' => ['DEF%', 'DEF%'],
        'eleMas' => ['Elemental Mastery', 'EM'],
        'enerRech_' => ['Energy Recharge', 'ER'],
        'critRate_' => ['CRIT Rate', 'CR'],
        'critDMG_' => ['CRIT DMG', 'CD'],
        'heal_' => ['Healing Bonus', 'Heal'],
        'spd' => ['SPD', 'SPD'],
        'spd_' => ['SPD%', 'SPD%'],
        'effectHitRate_' => ['Effect Hit Rate', 'EHR'],
        'effectRes_' => ['Effect RES', 'RES'],
        'breakEffect_' => ['Break Effect', 'BE'],
        'energyRegen_' => ['Energy Regeneration Rate', 'ERR'],
        self::ELEMENTAL_DMG => ['Elemental DMG Bonus', 'Elem DMG'],
    ];

    /**
     * Flat stat => its percentage counterpart (used for weighting flat rolls).
     */
    public const FLAT_TO_PERCENT = ['hp' => 'hp_', 'atk' => 'atk_', 'def' => 'def_'];

    /** What parse() reads a label as: elements whose DMG bonus a label can name, then words for the stats. */
    private const ELEMENTS = [
        'genshin' => ['pyro', 'hydro', 'anemo', 'electro', 'dendro', 'cryo', 'geo', 'physical'],
        'hsr' => ['physical', 'fire', 'ice', 'lightning', 'thunder', 'wind', 'quantum', 'imaginary'],
    ];

    private const COMMON = [
        'hp' => 'hp_', 'maxhp' => 'hp_', 'flathp' => 'hp',
        'atk' => 'atk_', 'attack' => 'atk_', 'flatatk' => 'atk',
        'def' => 'def_', 'defense' => 'def_', 'defence' => 'def_', 'flatdef' => 'def',
        'cr' => 'critRate_', 'critrate' => 'critRate_', 'critchance' => 'critRate_',
        'cd' => 'critDMG_', 'critdmg' => 'critDMG_', 'cdmg' => 'critDMG_',
        'elementaldmg' => self::ELEMENTAL_DMG, 'elementdmg' => self::ELEMENTAL_DMG, 'dmg' => self::ELEMENTAL_DMG,
        'elemental' => self::ELEMENTAL_DMG,
    ];

    private const SPECIFIC = [
        'genshin' => [
            'em' => 'eleMas', 'elementalmastery' => 'eleMas', 'mastery' => 'eleMas', 'elemas' => 'eleMas',
            'er' => 'enerRech_', 'energyrecharge' => 'enerRech_', 'recharge' => 'enerRech_', 'energyrechargerate' => 'enerRech_',
            'enerrech' => 'enerRech_', 'energyrechage' => 'enerRech_', // typo on genshin.gg
            'healing' => 'heal_', 'heal' => 'heal_', 'hb' => 'heal_',
        ],
        'hsr' => [
            'spd' => 'spd', 'speed' => 'spd',
            'ehr' => 'effectHitRate_', 'effecthitrate' => 'effectHitRate_', 'effecthit' => 'effectHitRate_', 'hitrate' => 'effectHitRate_',
            'effectres' => 'effectRes_', 'effres' => 'effectRes_', 'res' => 'effectRes_', 'effectresistance' => 'effectRes_',
            'be' => 'breakEffect_', 'breakeffect' => 'breakEffect_', 'break' => 'breakEffect_',
            'err' => 'energyRegen_', 'er' => 'energyRegen_', 'energyregen' => 'energyRegen_',
            'energyregenerationrate' => 'energyRegen_', 'energyregenrate' => 'energyRegen_', 'energyregeneration' => 'energyRegen_',
            'ohb' => 'heal_', 'outgoinghealing' => 'heal_', 'healing' => 'heal_', 'heal' => 'heal_',
        ],
    ];

    /**
     * Every key parse() can give for a game - so every stat key a build of that game holds (an imported
     * builds file may name no other: the API sends these keys as they are).
     *
     * @return list<string>
     */
    public static function keys(Game $game): array
    {
        $keys = [
            ...array_values(self::COMMON),
            ...array_values(self::SPECIFIC[$game->value]),
            ...array_map(self::elementKey(...), self::ELEMENTS[$game->value]),
        ];
        // "SPD%" (a set bonus) is the one key parse() makes of another with a "%".
        if (in_array('spd', $keys, true)) {
            $keys[] = 'spd_';
        }

        return array_values(array_unique($keys));
    }

    public static function isPercent(string $key): bool
    {
        return str_ends_with($key, '_');
    }

    public static function maxRoll(Game $game, string $key): ?float
    {
        return self::MAX_ROLL[$game->value][$key] ?? null;
    }

    /**
     * @return list<string> Stats that can appear as substats.
     */
    public static function substatKeys(Game $game): array
    {
        return array_keys(self::MAX_ROLL[$game->value]);
    }

    public static function label(string $key, Game $game, bool $short = false): string
    {
        if (isset(self::LABELS[$key])) {
            if ($game === Game::Hsr && $key === 'heal_') {
                return $short ? 'OHB' : 'Outgoing Healing Boost';
            }

            return self::LABELS[$key][$short ? 1 : 0];
        }

        if (str_ends_with($key, '_dmg_')) {
            $element = ucfirst(substr($key, 0, -5));

            return $short ? "{$element} DMG" : ($game === Game::Hsr ? "{$element} DMG Boost" : "{$element} DMG Bonus");
        }

        return $key;
    }

    /**
     * Element-specific DMG stat for a character element (e.g. "pyro" => "pyro_dmg_").
     */
    public static function elementDmg(?string $element): ?string
    {
        return $element ? strtolower($element).'_dmg_' : null;
    }

    /**
     * Parse a human stat label ("ATK%", "Energy Recharge", "Crit", "Pyro DMG Bonus", "SPD") into canonical keys.
     * Bare "ATK"/"HP"/"DEF" are read as percentages because builds practically never ask for flat stats.
     *
     * @return list<string> Usually one key; "Crit" / "CRIT Rate/DMG" yield both crit stats. Empty when unknown.
     */
    public static function parse(Game $game, string $text): array
    {
        $raw = mb_strtolower(trim($text));
        $raw = str_replace(['％', 'bonus', 'boost', 'damage', '(', ')'], ['%', '', '', 'dmg', '', ''], $raw);
        $norm = preg_replace('/[^a-z0-9%\/]+/', '', $raw) ?? '';

        if ($norm === '') {
            return [];
        }

        // "CRIT Rate/DMG", "CR/CD", "Crit Rate / Crit DMG"
        if (preg_match('/^(critrate\/(crit)?dmg|cr\/cd|crit(value)?|cv|critrate\/critdmg)$/', $norm)) {
            return ['critRate_', 'critDMG_'];
        }
        $norm = str_replace('/', '', $norm);

        $isPct = str_contains($norm, '%') || str_contains($norm, 'percent');
        $norm = str_replace(['%', 'percent'], '', $norm);

        foreach (self::ELEMENTS[$game->value] as $element) {
            // "Pyro DMG Bonus" => "pyrodmg"; a goblet/sphere listed just as "Pyro" means the same.
            if ($norm === $element || $norm === $element.'dmg') {
                return [self::elementKey($element)];
            }
        }

        $key = self::SPECIFIC[$game->value][$norm] ?? self::COMMON[$norm] ?? null;
        if ($key === null) {
            return [];
        }

        // "SPD%" is a real (set-bonus) stat; everything else with % is already a percentage key.
        if ($key === 'spd' && $isPct) {
            return ['spd_'];
        }

        return [$key];
    }

    /**
     * Parse a priority string such as "CRIT Rate = CRIT DMG > ATK% > Energy Recharge / EM".
     * ">" (or "≥", "»", ";") starts a lower priority group; "=", "/", "," and "or" keep the same priority.
     *
     * @return list<array{stat: string, priority: int}>
     */
    public static function parsePriorityList(Game $game, string $text): array
    {
        $result = [];
        $seen = [];
        $priority = 1;

        $groups = preg_split('/\s*(?:>|≥|»|;|\n)\s*/u', $text) ?: [];
        foreach ($groups as $group) {
            $added = false;
            // Keep "CRIT Rate/DMG" together so it resolves to both crit stats.
            $group = preg_replace('/crit\s*rate\s*\/\s*(crit\s*)?dmg/i', 'CRITBOTH', $group) ?? $group;
            foreach (preg_split('/\s*(?:=|\/|,|\bor\b|\band\b|&)\s*/iu', $group) ?: [] as $part) {
                $part = trim(str_replace('CRITBOTH', 'Crit Rate/DMG', $part), " \t.-–()");
                // Strip trailing notes like "ER (until 160%)" or "ATK% [if no X]".
                $part = trim(preg_replace('/\s*[\[(].*$/', '', $part) ?? $part);
                foreach ($part === '' ? [] : self::parse($game, $part) as $stat) {
                    if (! isset($seen[$stat])) {
                        $seen[$stat] = true;
                        $result[] = ['stat' => $stat, 'priority' => $priority];
                        $added = true;
                    }
                }
            }
            if ($added) {
                $priority++;
            }
        }

        return $result;
    }

    /**
     * Ordered labels without tie information ("CRIT Rate", "CRIT DMG", "ATK%") => [{stat, priority}].
     * CRIT Rate and CRIT DMG listed next to each other share one priority: sites list them in sequence but
     * treat them as equally valuable.
     *
     * @param  list<string>  $labels
     * @return list<array{stat: string, priority: int}>
     */
    public static function ordered(Game $game, array $labels): array
    {
        $result = [];
        $priority = 0;
        $previous = null;

        foreach ($labels as $label) {
            $stats = self::parse($game, (string) $label);
            if ($stats === []) {
                continue;
            }

            $critPair = count($stats) === 1 && $previous !== null
                && in_array($stats[0], ['critRate_', 'critDMG_'], true)
                && in_array($previous, ['critRate_', 'critDMG_'], true);
            if (! $critPair) {
                $priority++;
            }

            foreach ($stats as $stat) {
                if (! in_array($stat, array_column($result, 'stat'), true)) {
                    $result[] = ['stat' => $stat, 'priority' => $priority];
                }
            }
            $previous = count($stats) === 1 ? $stats[0] : null;
        }

        return $result;
    }

    private static function elementKey(string $element): string
    {
        return ($element === 'thunder' ? 'lightning' : $element).'_dmg_';
    }

    /**
     * Resolve the generic elemental placeholder to the character's element.
     */
    public static function resolveElemental(string $key, ?string $element): string
    {
        if ($key === self::ELEMENTAL_DMG && $element) {
            return self::elementDmg($element) ?? $key;
        }

        return $key;
    }
}
