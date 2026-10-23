<?php

namespace App\Services\Builds;

use App\Support\Json;

/**
 * A build's parts as an import compares them (docs/builds.md, "Hand edits"): a part an editor changed by hand stays
 * the editor's until the file changes the same part, so an import compares part by part - with what the file said of
 * the build last time (builds.file_build), and with what is stored now -, never the whole build.
 *
 * Each part in a canonical form: what the file says of it, nothing this site adds or no longer shows - a weapon's or
 * a set option's rank (its place says it), whether the catalog knows a weapon (the page asks the catalog itself), and
 * the texts the format still carries but the site never shows: a weapon's, a set option's and a team's note, a stat
 * target's text (the build's own notes are no part at all). Priorities, names and the order of every list as they are:
 * the analysis and the page read them so. A stat target's bound as a float, so 70 and 70.0 - as a browser sends one
 * back, as a file may write it - are the same; a main stat's slots in one order, whichever order they were written in.
 */
final class BuildParts
{
    /** The parts, in the order an import's question names them. */
    public const PARTS = ['weapons', 'sets', 'main_stats', 'substats', 'talent_priority', 'stat_targets', 'teams', 'game_version'];

    /**
     * @param  array<string, mixed>  $build  as BuildFile::read() gives it, or as stored (its columns decoded)
     * @return array<string, string>  part => its canonical JSON, in the order of PARTS
     */
    public static function of(array $build): array
    {
        $mainStats = [];
        foreach ((array) ($build['main_stats'] ?? []) as $slot => $stats) {
            $mainStats[(string) $slot] = self::ranked($stats, 'stat');
        }
        ksort($mainStats, SORT_STRING);

        return [
            'weapons' => Json::encode(
                array_map(fn(array $weapon) => [(string) ($weapon['key'] ?? ''), (string) ($weapon['name'] ?? ''), $weapon['refinement'] ?? null], self::list($build['weapons'] ?? null)),
            ),
            'sets' => Json::encode(
                array_map(
                    fn(array $option) => [
                        (string) ($option['kind'] ?? ''),
                        $option['choose'] ?? null,
                        array_map(
                            fn(array $piece) => [
                                (string) ($piece['label'] ?? ''),
                                $piece['count'] ?? null,
                                array_values((array) ($piece['keys'] ?? [])),
                                array_values((array) ($piece['names'] ?? [])),
                            ],
                            self::list($option['pieces'] ?? null),
                        ),
                    ],
                    self::list($build['sets'] ?? null),
                ),
            ),
            'main_stats' => Json::encode(array_map(fn(string $slot) => [$slot, $mainStats[$slot]], array_keys($mainStats))),
            'substats' => Json::encode(self::ranked($build['substats'] ?? null, 'stat')),
            'talent_priority' => Json::encode(self::ranked($build['talent_priority'] ?? null, 'talent')),
            'stat_targets' => Json::encode(
                array_map(
                    fn(array $target) => [(string) ($target['stat'] ?? ''), self::bound($target['min'] ?? null), self::bound($target['max'] ?? null), (int) ($target['from_rank'] ?? 0)],
                    self::list($build['stat_targets'] ?? null),
                ),
            ),
            'teams' => Json::encode(
                array_map(
                    fn(array $team) => [
                        (string) ($team['name'] ?? ''),
                        array_map(
                            fn(array $member) => [
                                (string) ($member['key'] ?? ''),
                                (string) ($member['name'] ?? ''),
                                $member['role'] ?? null,
                                (int) ($member['min_rank'] ?? 0),
                                array_map(fn(array $one) => [(string) ($one['key'] ?? ''), (string) ($one['name'] ?? '')], self::list($member['alternatives'] ?? null)),
                            ],
                            self::list($team['members'] ?? null),
                        ),
                    ],
                    self::list($build['teams'] ?? null),
                ),
            ),
            'game_version' => Json::encode($build['game_version'] ?? null),
        ];
    }

    /**
     * The parts whose canonical forms differ, in the order of PARTS.
     *
     * @param  array<string, string>  $a  of()
     * @param  array<string, string>  $b  of()
     * @return list<string>
     */
    public static function differing(array $a, array $b): array
    {
        return array_values(array_filter(self::PARTS, fn(string $part) => ($a[$part] ?? null) !== ($b[$part] ?? null)));
    }

    /**
     * [{stat|talent, priority}] as [[stat, priority], ...]; none (null or empty) as [].
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function ranked(mixed $list, string $name): array
    {
        return array_map(fn(array $entry) => [(string) ($entry[$name] ?? ''), $entry['priority'] ?? null], self::list($list));
    }

    /**
     * A list of objects, none (null) as [].
     *
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function bound(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
