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

        // Per piece, the equipped sets with enough pieces for it, each once: an option can list many pieces
        // and a piece many sets, but only the few sets worn can take part in a match.
        $candidates = array_map(fn (array $piece) => array_values(array_unique(array_filter(
            $piece['keys'], fn ($key) => ($equipped[$key] ?? 0) >= $piece['count'],
        ))), $pieces);
        $memo = [];
        $matched = self::maxMatched($candidates, 0, [], $required, $memo);

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
     * The most pieces from $index on that distinct equipped sets satisfy, up to $cap: matching more than the
     * option requires changes nothing (evaluate() caps it anyway). Each state - the piece, the sets used so
     * far - is worked out once, so the search stays small however many pieces an option lists.
     *
     * @param  list<list<string>>  $candidates  per piece, the equipped sets that satisfy it
     * @param  list<string>  $used
     * @param  array<string, int>  $memo
     */
    private static function maxMatched(array $candidates, int $index, array $used, int $cap, array &$memo): int
    {
        if ($index >= count($candidates) || $cap === 0) {
            return 0;
        }
        sort($used);
        $state = $index.':'.implode(',', $used);
        if (isset($memo[$state])) {
            return $memo[$state];
        }

        // Option 1: leave this piece unsatisfied.
        $best = self::maxMatched($candidates, $index + 1, $used, $cap, $memo);

        // Option 2: satisfy it with a set not used yet.
        foreach ($candidates[$index] as $key) {
            if ($best === $cap) {
                break;
            }
            if (! in_array($key, $used, true)) {
                $best = max($best, 1 + self::maxMatched($candidates, $index + 1, [...$used, $key], $cap - 1, $memo));
            }
        }

        return $memo[$state] = $best;
    }
}
