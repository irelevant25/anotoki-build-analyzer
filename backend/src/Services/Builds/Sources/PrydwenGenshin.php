<?php

namespace App\Services\Builds\Sources;

use App\Enums\Game;
use App\Services\Builds\BuildSource;
use App\Services\Builds\CharacterRef;
use App\Services\Builds\Parsing\NextFlight;
use App\Services\Builds\ScrapedBuild;
use App\Services\Catalog\SetBonusGroups;
use App\Config;
use App\Http\Fetcher;
use App\Support\Stats;
use App\Support\Talents;
use App\Support\Clock;
use RuntimeException;

/**
 * prydwen.gg Genshin Impact (Next.js App Router).
 *
 * "full-build" profiles contain curated rankings (weaponsData / artifactsData with one ranking cell per
 * group, artifactStats, substatsTalents, energyData). "analytics-only" profiles only have usage statistics;
 * those become a "Most used" build so the character is still covered.
 */
class PrydwenGenshin implements BuildSource
{
    public function __construct(
        protected readonly Fetcher $fetcher,
        protected readonly SetBonusGroups $setGroups,
        protected readonly Config $config,
    ) {}

    public function key(): string
    {
        return 'prydwen.genshin';
    }

    public function game(): Game
    {
        return Game::Genshin;
    }

    public function name(): string
    {
        return 'Prydwen';
    }

    public function baseUrl(): string
    {
        return 'https://www.prydwen.gg';
    }

    public function delayMs(): int
    {
        return 10_000;
    }

    public function listCharacters(): array
    {
        $html = $this->fetch($this->baseUrl().'/genshin-impact/characters');
        $props = NextFlight::findProps($html, 'characters')
            ?? throw new RuntimeException('The character list was not found in the page data');

        $refs = [];
        foreach ($props['characters'] as $character) {
            if (empty($character['slug']) || empty($character['name'])) {
                continue;
            }
            $refs[] = new CharacterRef(
                slug: $character['slug'],
                name: $character['name'],
                url: $this->baseUrl().'/genshin-impact/characters/'.$character['slug'],
                released: empty($character['isUpcoming']),
                meta: ['element' => isset($character['element']) ? strtolower($character['element']) : null],
            );
        }

        return $refs;
    }

    public function fetchBuilds(CharacterRef $character): array
    {
        $html = $this->fetch($character->url);
        $info = NextFlight::findProps($html, 'profile_type');
        if (! $info) {
            return [];
        }

        $updatedAt = $this->value($info['last_updated'] ?? null);
        $common = [
            'sourceUrl' => $character->url,
            'updatedAt' => $updatedAt ? Clock::parse($updatedAt) : null,
            'gameVersion' => $this->value($info['build_release_patch'] ?? null) ?? $this->value($info['patch'] ?? null),
        ];

        $build = $info['build'] ?? null;
        if (($info['profile_type'] ?? null) === 'full-build' && is_array($build) && ! empty($build['weaponsData'])) {
            return $this->curated($build, $common);
        }

        if (is_array($info['usage'] ?? null) && ! empty($info['usage']['weapons'])) {
            return [$this->fromUsage($info['usage'], $common)];
        }

        return [];
    }

    protected function fetch(string $url): string
    {
        return $this->fetcher->get($url, [
            'browser' => true, // Cloudflare challenges non-browser user agents
            'delay_ms' => $this->delayMs(),
            'cache_hours' => (int) $this->config->get('fetch.cache_hours', 12),
        ]);
    }

    /**
     * @return list<ScrapedBuild>
     */
    private function curated(array $build, array $common): array
    {
        $weaponGroups = array_values($build['weaponsConfig']['groups'] ?? [['label' => 'Build']]);
        $artifactGroups = array_values($build['artifactsConfig']['groups'] ?? [['label' => 'Build']]);
        $type = trim((string) ($build['weaponsConfig']['type'] ?? '')) ?: 'Build';

        // Separate variants only when weapons and artifacts are ranked for the same set of roles;
        // otherwise the groups are team contexts for one build.
        $perGroup = count($weaponGroups) > 1 && count($weaponGroups) === count($artifactGroups);
        $variantCount = $perGroup ? count($weaponGroups) : 1;

        $mainStats = [];
        $statComments = [];
        foreach ($build['artifactStats'] ?? [] as $row) {
            $slot = strtolower(trim((string) ($row['slot'] ?? '')));
            $parsed = Stats::parsePriorityList(Game::Genshin, (string) ($row['stat'] ?? ''));
            if (in_array($slot, Game::Genshin->variableSlots(), true) && $parsed !== []) {
                $mainStats[$slot] = $parsed;
            }
            if (! empty($row['comment'])) {
                $statComments[] = ucfirst($slot).': '.trim($row['comment']);
            }
        }

        $substats = Stats::parsePriorityList(Game::Genshin, (string) ($build['substatsTalents']['stats'] ?? ''));
        $talents = Talents::parsePriority(Game::Genshin, (string) ($build['substatsTalents']['talents'] ?? ''));
        $energy = $this->energyTarget($build['energyConfig'] ?? [], $build['energyData'] ?? []);

        $notes = array_values(array_filter([
            $this->plain($build['weaponsConfig']['introNote'] ?? null),
            $this->plain($build['substatsTalents']['statsComments'] ?? null),
            $this->plain($build['substatsTalents']['talentsComments'] ?? null),
            $statComments ? implode("\n", $statComments) : null,
            $energy['text'] ?? null,
        ]));

        $builds = [];
        for ($group = 0; $group < $variantCount; $group++) {
            $label = $perGroup ? trim((string) ($weaponGroups[$group]['label'] ?? '')) : $type;
            $context = ! $perGroup && count($weaponGroups) > 1
                ? 'Weapon ranking for "'.($weaponGroups[0]['label'] ?? '').'" (other teams: '.implode(', ', array_map(fn ($g) => $g['label'] ?? '', array_slice($weaponGroups, 1))).')'
                : null;

            $builds[] = new ScrapedBuild(
                variant: $label !== '' ? $label : 'Build',
                role: $type,
                recommended: $group === 0,
                weapons: $this->rankedWeapons($build['weaponsData'], $group),
                sets: $this->rankedSets($build['artifactsData'] ?? [], $perGroup ? $group : 0),
                mainStats: $mainStats,
                substats: $substats,
                talents: $talents,
                statTargets: $energy ? [['stat' => 'enerRech_', 'min' => $energy['min'], 'max' => null, 'text' => $energy['text']]] : [],
                notes: implode("\n\n", array_filter([$context, ...$notes])) ?: null,
                sourceUrl: $common['sourceUrl'],
                updatedAt: $common['updatedAt'],
                gameVersion: $common['gameVersion'],
            );
        }

        return $builds;
    }

    /**
     * Cells hold either a rank ("1", "2") or relative damage ("124.72%"). Empty cells mean
     * "not rated for this group".
     */
    private function rankedWeapons(array $rows, int $group): array
    {
        $entries = [];
        foreach ($rows as $order => $row) {
            $name = trim((string) ($row['ref'] ?? ''));
            if ($name === '') {
                continue; // custom rows like "88% CDMG Stat Sticks" are not a specific weapon
            }
            $score = $this->cellScore($row['cells'][$group] ?? null);
            if ($score === null) {
                continue;
            }
            $refinement = preg_match('/R(\d)/i', (string) ($row['dupes'] ?? ''), $m) ? (int) $m[1] : null;
            $raw = $row['cells'][$group]['team'] ?? $row['cells'][$group]['solo'] ?? '';
            $entries[] = [
                'name' => $name,
                'refinement' => $refinement,
                'note' => trim(implode(' ', array_filter([
                    str_ends_with((string) $raw, '%') ? "Relative damage {$raw}".($refinement ? " at R{$refinement}" : '').'.' : null,
                    $this->plain($row['cells'][$group]['notes'] ?? null),
                ]))) ?: null,
                'score' => $score,
                'order' => $order,
            ];
        }

        $entries = $this->sortByScore($entries);

        // The same weapon can appear at several refinements; keep its best placement.
        $seen = [];
        $result = [];
        foreach ($entries as $entry) {
            if (isset($seen[$entry['name']])) {
                continue;
            }
            $seen[$entry['name']] = true;
            unset($entry['score'], $entry['order']);
            $result[] = $entry;
        }

        return $result;
    }

    private function rankedSets(array $rows, int $group): array
    {
        $entries = [];
        foreach ($rows as $order => $row) {
            $score = $this->cellScore($row['cells'][$group] ?? null);
            if ($score === null) {
                continue;
            }

            $note = $this->plain($row['cells'][$group]['notes'] ?? null);
            $name = trim((string) ($row['ref'] ?? ''));
            $option = $name !== ''
                ? ['kind' => 'artifact', 'choose' => null, 'pieces' => [['names' => [$name], 'label' => $name, 'count' => 4]], 'note' => $note]
                : $this->customSetOption((string) ($row['custom'] ?? ''), $note);

            if ($option) {
                $entries[] = $option + ['score' => $score, 'order' => $order];
            }
        }

        return array_map(function ($entry) {
            unset($entry['score'], $entry['order']);

            return $entry;
        }, $this->sortByScore($entries));
    }

    /**
     * "2PC EM + 2PC EM", "2PC ATK% Combinations", "2PC HP% + 2PC Hydro DMG%".
     */
    private function customSetOption(string $text, ?string $note): ?array
    {
        $pieces = [];
        foreach (preg_split('/\s*\+\s*/', $text) ?: [] as $part) {
            if (! preg_match('/2\s*-?\s*p(?:c|iece)?\s+(.+)$/i', trim($part), $m)) {
                continue;
            }
            $label = trim(preg_replace('/\bcombinations?\b/i', '', $m[1]) ?? $m[1]);
            $stat = Stats::parse(Game::Genshin, $label)[0] ?? null;
            $names = $stat ? $this->setGroups->names(Game::Genshin, $stat) : [];
            if ($names !== []) {
                $pieces[] = ['names' => $names, 'label' => "{$label} set", 'count' => 2];
            }
        }

        if ($pieces === []) {
            return null;
        }
        if (count($pieces) === 1) {
            $pieces[] = $pieces[0]; // "2PC ATK% Combinations" = two different ATK% sets
        }

        return ['kind' => 'artifact', 'choose' => null, 'pieces' => $pieces, 'note' => trim($text.($note ? ". {$note}" : ''))];
    }

    /**
     * Smaller is better for ranks, so percentages are negated to sort the same direction.
     */
    private function cellScore(?array $cell): ?float
    {
        $raw = trim((string) ($cell['team'] ?? $cell['solo'] ?? ''));
        if ($raw === '' || $raw === '-' || str_starts_with($raw, '$')) {
            return null;
        }
        if (str_ends_with($raw, '%')) {
            return -(float) rtrim($raw, '%');
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    private function sortByScore(array $entries): array
    {
        usort($entries, fn ($a, $b) => [$a['score'], $a['order']] <=> [$b['score'], $b['order']]);

        return $entries;
    }

    /**
     * Energy Recharge table ("Solo Hydro: 210-230%") => the lowest recommended value plus a readable summary.
     *
     * @return array{min: float, text: string}|null
     */
    private function energyTarget(array $config, array $rows): ?array
    {
        $columns = array_values($config['columns'] ?? []);
        $lines = [];
        $minimum = null;

        foreach ($rows as $row) {
            $values = [];
            foreach (array_values($row['values'] ?? []) as $index => $value) {
                $value = trim((string) $value);
                if ($value === '' || $value === '-') {
                    continue;
                }
                $values[] = (isset($columns[$index]) ? $columns[$index].': ' : '').$value;
                if (preg_match('/^(\d{2,3})(?:\s*[-–]\s*\d{2,3})?\s*%/', $value, $m) && ! preg_match('/don.t build|not recommended/i', $value)) {
                    $minimum = $minimum === null ? (float) $m[1] : min($minimum, (float) $m[1]);
                }
            }
            if ($values !== []) {
                $lines[] = trim((string) ($row['label'] ?? '')).' - '.implode('; ', $values);
            }
        }

        if ($minimum === null) {
            return null;
        }

        return ['min' => $minimum, 'text' => "Energy Recharge requirements:\n".implode("\n", $lines)];
    }

    /**
     * Usage statistics => a "Most used" build (weapons, sets and main stats players actually run).
     */
    private function fromUsage(array $usage, array $common): ScrapedBuild
    {
        $weapons = [];
        foreach (array_slice($usage['weapons'] ?? [], 0, 8) as $weapon) {
            if (! empty($weapon['name'])) {
                $weapons[] = ['name' => $weapon['name'], 'refinement' => null, 'note' => sprintf('Used by %.1f%% of players.', (float) ($weapon['percentage'] ?? 0))];
            }
        }

        $sets = [];
        foreach (array_slice($usage['artifactSets'] ?? [], 0, 6) as $combo) {
            $a = trim((string) ($combo['nameA'] ?? ''));
            $b = trim((string) ($combo['nameB'] ?? ''));
            if ($a === '' || $b === '') {
                continue; // "rainbow" / incomplete combinations
            }
            $pieces = $a === $b
                ? [['names' => [$a], 'label' => $a, 'count' => 4]]
                : [['names' => [$a], 'label' => $a, 'count' => 2], ['names' => [$b], 'label' => $b, 'count' => 2]];
            $sets[] = ['kind' => 'artifact', 'choose' => null, 'pieces' => $pieces, 'note' => sprintf('Used by %.1f%% of players.', (float) ($combo['percentage'] ?? 0))];
        }

        $mainStats = [];
        foreach (['sands', 'goblet', 'circlet'] as $slot) {
            $priority = 1;
            foreach ($usage[$slot.'MainStats'] ?? [] as $row) {
                // Only main stats a meaningful share of players use.
                if ((float) ($row['percentage'] ?? 0) < 15) {
                    continue;
                }
                $stat = Stats::parse(Game::Genshin, str_replace('_', ' ', (string) ($row['statType'] ?? '')))[0] ?? null;
                if ($stat && ! in_array($stat, array_column($mainStats[$slot] ?? [], 'stat'), true)) {
                    $mainStats[$slot][] = ['stat' => $stat, 'priority' => $priority++];
                }
            }
        }

        return new ScrapedBuild(
            variant: 'Most used',
            role: 'Usage statistics',
            recommended: false,
            weapons: $weapons,
            sets: $sets,
            mainStats: $mainStats,
            substats: [],
            talents: [],
            statTargets: [],
            notes: sprintf('This source has no curated build for this character yet. Built from %s tracked players.', number_format((int) ($usage['totalCount'] ?? 0))),
            sourceUrl: $common['sourceUrl'],
            updatedAt: ! empty($usage['syncedAt']) ? Clock::parse($usage['syncedAt']) : $common['updatedAt'],
            gameVersion: $common['gameVersion'],
        );
    }

    private function value(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && ! str_starts_with($value, '$') ? $value : null;
    }

    private function plain(?string $html): ?string
    {
        if ($html === null || trim($html) === '' || str_starts_with($html, '$')) {
            return null;
        }
        $text = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{2,}/", "\n", $text) ?? $text);
    }
}
