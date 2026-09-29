<?php

namespace App\Services\Analysis;

/**
 * Checks equipped set pieces against a build's set options.
 *
 * An option has pieces [{keys, count}] (a piece accepts any of its set keys) and an optional "choose"
 * (how many pieces must be satisfied, e.g. "any two of these 2pc bonuses"). One equipped set can satisfy
 * only one piece: 4pc Gilded Dreams gives the +80 EM bonus once, not twice.
 */
final class SetMatcher
{
    /**
     * @param  array<string, int>  $equipped  set key => equipped piece count
     * @param  array{pieces: list<array{keys: list<string>, count: int}>, choose?: int|null}  $option
     * @return array{satisfied: bool, fraction: float, matched: int, required: int}
     */
    public static function evaluate(array $equipped, array $option): array
    {
        $pieces = $option['pieces'] ?? [];
        $required = (int) ($option['choose'] ?? count($pieces));
        if ($required === 0) {
            return ['satisfied' => true, 'fraction' => 1.0, 'matched' => 0, 'required' => 0];
        }

        $matched = self::maxMatched($equipped, $pieces, 0, []);

        // Partial credit: e.g. only 2 pieces of a 4pc set.
        $partial = 0.0;
        if ($matched < $required) {
            foreach ($pieces as $piece) {
                $best = 0;
                foreach ($piece['keys'] as $key) {
                    $best = max($best, min($equipped[$key] ?? 0, $piece['count']));
                }
                $partial = max($partial, $best / max(1, $piece['count']));
            }
        }

        $fraction = $matched >= $required ? 1.0 : ($matched + $partial * 0.5) / $required;

        return ['satisfied' => $matched >= $required, 'fraction' => min(1.0, $fraction), 'matched' => min($matched, $required), 'required' => $required];
    }

    /**
     * Maximum number of pieces that can be satisfied with distinct equipped sets (tiny backtracking search).
     *
     * @param  array<string, int>  $equipped
     * @param  list<string>  $used
     */
    private static function maxMatched(array $equipped, array $pieces, int $index, array $used): int
    {
        if ($index >= count($pieces)) {
            return 0;
        }

        // Option 1: leave this piece unsatisfied.
        $best = self::maxMatched($equipped, $pieces, $index + 1, $used);

        // Option 2: satisfy it with any unused set that has enough pieces.
        foreach ($pieces[$index]['keys'] as $key) {
            if (! in_array($key, $used, true) && ($equipped[$key] ?? 0) >= $pieces[$index]['count']) {
                $best = max($best, 1 + self::maxMatched($equipped, $pieces, $index + 1, [...$used, $key]));
            }
        }

        return $best;
    }
}
