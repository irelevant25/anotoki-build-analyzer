<?php

namespace App\Services\Analysis;

use App\Enums\Game;
use App\Support\Stats;

/**
 * Scores artifact substats against a build's substat priorities.
 *
 * Every substat is converted to "rolls" (value / max single roll), multiplied by the weight of its priority.
 * The item's score is compared with an ideal item of the same kind: all upgrades landing in the best
 * available stat (a 5-star piece has at most 9 rolls: 4 initial + 5 upgrades).
 */
final class ItemScorer
{
    /** Weight by priority position (1 = most important). */
    private const PRIORITY_WEIGHTS = [1 => 1.0, 2 => 0.8, 3 => 0.6, 4 => 0.45];

    private const LOWEST_WEIGHT = 0.3;

    /** @var array<string, float> stat => weight */
    private array $weights;

    /**
     * @param  list<array{stat: string, priority: int}>  $substats
     */
    public function __construct(private readonly Game $game, array $substats)
    {
        $weights = [];
        foreach ($substats as $entry) {
            $weight = self::PRIORITY_WEIGHTS[$entry['priority']] ?? self::LOWEST_WEIGHT;
            $weights[$entry['stat']] = max($weights[$entry['stat']] ?? 0, $weight);
        }

        foreach (Stats::FLAT_TO_PERCENT as $flat => $percent) {
            if (isset($weights[$percent]) && !isset($weights[$flat])) {
                $weights[$flat] = round($weights[$percent] * $game->flatStatFactor(), 3);
            }
        }

        $this->weights = $weights;
    }

    /**
     * @return array<string, float>
     */
    public function weights(): array
    {
        return $this->weights;
    }

    public function weight(string $stat): float
    {
        return $this->weights[$stat] ?? 0.0;
    }

    /**
     * @param  list<array{key: string, value: float, rolls?: int|null}>  $substats
     * @return array{score: float, ideal: float, percent: int, grade: string, useful_rolls: float, substats: list<array>}
     */
    public function score(string $mainStat, int $rarity, array $substats): array
    {
        $details = [];
        $score = 0.0;
        $usefulRolls = 0.0;

        foreach ($substats as $substat) {
            $max = Stats::maxRoll($this->game, $substat['key']);
            $weight = $this->weight($substat['key']);
            $rolls = $max ? $substat['value'] / $max : 0.0;
            $score += $weight * $rolls;
            if ($weight >= 0.5) {
                $usefulRolls += $rolls;
            }

            $details[] = [
                'key' => $substat['key'],
                'value' => $substat['value'],
                'rolls' => $substat['rolls'] ?? ($max ? max(1, (int) round($substat['value'] / ($max * 0.85))) : null),
                'weight' => $weight,
                'roll_value' => round($rolls, 2),
            ];
        }

        $ideal = $this->ideal($mainStat, $rarity);
        $percent = $ideal > 0 ? (int) round(min(1.0, $score / $ideal) * 100) : 0;

        return [
            'score' => round($score, 2),
            'ideal' => round($ideal, 2),
            'percent' => $percent,
            'grade' => self::grade($percent),
            'useful_rolls' => round($usefulRolls, 1),
            'substats' => $details,
        ];
    }

    /**
     * Best possible weighted score for an item with this main stat: the four best-weighted stats that
     * can appear as substats (a substat can never equal the main stat), with all upgrades in the best one.
     */
    private function ideal(string $mainStat, int $rarity): float
    {
        $candidates = [];
        foreach (Stats::substatKeys($this->game) as $stat) {
            if ($stat !== $mainStat) {
                $candidates[] = $this->weight($stat);
            }
        }
        rsort($candidates);
        $top = array_slice(array_pad($candidates, 4, 0.0), 0, 4);

        // 5-star: 4 initial + 5 upgrades. Lower rarities have fewer upgrades and smaller rolls.
        $upgrades = match (true) {
            $rarity >= 5 => 5,
            $rarity === 4 => 4,
            default => 3,
        };
        $rollSize = match (true) {
            $rarity >= 5 => 1.0,
            $rarity === 4 => 0.8,
            default => 0.6,
        };

        return ($top[0] * (1 + $upgrades) + $top[1] + $top[2] + $top[3]) * $rollSize;
    }

    public static function grade(int $percent): string
    {
        return match (true) {
            $percent >= 70 => 'S',
            $percent >= 55 => 'A',
            $percent >= 42 => 'B',
            $percent >= 30 => 'C',
            default => 'D',
        };
    }

    /**
     * Substat priorities to fall back on when a build lists none (e.g. usage-statistics builds):
     * crit when the circlet/body asks for crit, plus the scaling stats its main stats point to.
     *
     * @param  array<string, list<array{stat: string, priority: int}>>  $mainStats
     * @return list<array{stat: string, priority: int}>
     */
    public static function fallbackSubstats(Game $game, array $mainStats): array
    {
        $mainKeys = [];
        foreach ($mainStats as $slotStats) {
            foreach ($slotStats as $entry) {
                $mainKeys[$entry['stat']] = true;
            }
        }

        $result = [];
        if (isset($mainKeys['critRate_']) || isset($mainKeys['critDMG_'])) {
            $result[] = ['stat' => 'critRate_', 'priority' => 1];
            $result[] = ['stat' => 'critDMG_', 'priority' => 1];
        }
        foreach (['atk_', 'hp_', 'def_', 'eleMas', 'enerRech_'] as $stat) {
            if (isset($mainKeys[$stat]) && Stats::maxRoll($game, $stat) !== null) {
                $result[] = ['stat' => $stat, 'priority' => 2];
            }
        }
        if (!isset($mainKeys['enerRech_']) && Stats::maxRoll($game, 'enerRech_') !== null) {
            $result[] = ['stat' => 'enerRech_', 'priority' => 3];
        }

        return $result;
    }
}
