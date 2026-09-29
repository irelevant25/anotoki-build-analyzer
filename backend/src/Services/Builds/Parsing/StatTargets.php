<?php

namespace App\Services\Builds\Parsing;

use App\Enums\Game;
use App\Support\Stats;

/**
 * Pulls numeric stat goals out of free-text notes, e.g.
 * "125-145% Energy Recharge", "2000+ ATK", "Energy Recharge: 160%", "SPD 134+".
 *
 * To avoid reading effect descriptions ("grants 20% ATK") as goals, a number only counts when it is a
 * range, ends with "+", or follows a colon.
 */
final class StatTargets
{
    private const STAT_WORDS = [
        'genshin' => 'Energy Recharge|ER|CRIT Rate|CRIT DMG|Crit Rate|Crit DMG|Elemental Mastery|EM|ATK|HP|DEF',
        'hsr' => 'SPD|Speed|CRIT Rate|CRIT DMG|Crit Rate|Crit DMG|Effect Hit Rate|EHR|Effect RES|Break Effect|BE|Energy Regeneration Rate|ERR|ATK|HP|DEF',
    ];

    // "2,000" (thousands separator) must be tried first, but only when a comma group is present;
    // otherwise "3000" would be cut to "300".
    private const NUMBER = '\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?';

    /**
     * @return list<array{stat: string, min: float|null, max: float|null, text: string}>
     */
    public static function extract(Game $game, ?string $text): array
    {
        if (! $text) {
            return [];
        }

        $plain = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>'], '. ', $text);
        $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');

        $words = self::STAT_WORDS[$game->value];
        $n = self::NUMBER;
        $dash = '(?:-|–|—|~|to)';

        // "125-145% Energy Recharge", "2000+ ATK", "180%+ CRIT DMG"
        $numberFirst = "/(?<![\w.,])(?<min>{$n})\s*(?<p1>%)?\s*(?:{$dash}\s*(?<max>{$n})\s*(?<p2>%)?)?\s*(?<plus>\+)?\s*(?:of\s+)?(?<stat>{$words})(?![\w])/iu";
        // "Energy Recharge: 125-145%", "SPD 134+", "CRIT Rate: 70%"
        $statFirst = "/(?<![\w])(?<stat>{$words})\s*(?<colon>:|≥|>=)?\s*(?:of\s+|at least\s+|around\s+|~\s*)?(?<min>{$n})\s*(?<p1>%)?\s*(?:{$dash}\s*(?<max>{$n})\s*(?<p2>%)?)?\s*(?<plus>\+)?/iu";

        $targets = [];
        foreach ([$numberFirst, $statFirst] as $pattern) {
            preg_match_all($pattern, $plain, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($matches as $m) {
                $isGoal = $m['max'] !== null || $m['plus'] !== null || ($m['colon'] ?? null) !== null;
                if (! $isGoal) {
                    continue;
                }

                $target = self::build($game, $m['stat'], $m['min'], $m['max'], $m['p1'] !== null || $m['p2'] !== null, $m[0]);
                if ($target) {
                    $targets[$target['stat']] ??= $target;
                }
            }
        }

        return array_values($targets);
    }

    private static function build(Game $game, string $label, string $min, ?string $max, bool $percent, string $text): ?array
    {
        $stat = Stats::parse($game, $label)[0] ?? null;
        if (! $stat) {
            return null;
        }

        $minValue = (float) str_replace(',', '', $min);
        $maxValue = $max === null ? null : (float) str_replace(',', '', $max);

        // Goals for ATK/HP/DEF without a % sign are totals ("2000+ ATK"), i.e. flat stats.
        if (in_array($stat, ['atk_', 'hp_', 'def_'], true) && ! $percent) {
            $stat = rtrim($stat, '_');
        }

        if ($minValue <= 0 || (Stats::isPercent($stat) && $minValue > 400)) {
            return null;
        }

        return ['stat' => $stat, 'min' => $minValue, 'max' => $maxValue, 'text' => trim($text)];
    }
}
