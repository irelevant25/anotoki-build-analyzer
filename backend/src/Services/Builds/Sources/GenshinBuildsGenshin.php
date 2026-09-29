<?php

namespace App\Services\Builds\Sources;

use App\Enums\Game;
use App\Services\Builds\BuildSource;
use App\Services\Builds\CharacterRef;
use App\Services\Builds\Parsing\AstroIslands;
use App\Services\Builds\Parsing\StatTargets;
use App\Services\Builds\ScrapedBuild;
use App\Services\Catalog\SetBonusGroups;
use App\Config;
use App\Http\Fetcher;
use App\Support\Stats;
use App\Support\Talents;
use RuntimeException;

/**
 * genshin-builds.com (Astro site). Build data is embedded as JSON in the props of the
 * "CharacterBuildTabs" island on /en/character/{slug}; the list comes from the "CharactersList" island.
 * The site explicitly allows crawling (robots.txt, llms.txt).
 */
class GenshinBuildsGenshin implements BuildSource
{
    public function __construct(
        protected readonly Fetcher $fetcher,
        protected readonly SetBonusGroups $setGroups,
        protected readonly Config $config,
    ) {}

    public function key(): string
    {
        return 'genshin-builds.genshin';
    }

    public function game(): Game
    {
        return Game::Genshin;
    }

    public function name(): string
    {
        return 'Genshin-Builds';
    }

    public function baseUrl(): string
    {
        return 'https://genshin-builds.com';
    }

    public function delayMs(): int
    {
        return 1500;
    }

    public function listCharacters(): array
    {
        $html = $this->fetch($this->baseUrl().'/en/characters');
        $props = AstroIslands::find($html, 'CharactersList')
            ?? throw new RuntimeException('The character list was not found on the page');

        $refs = [];
        foreach ($props['characters'] ?? [] as $character) {
            if (empty($character['id']) || empty($character['name'])) {
                continue;
            }
            $released = empty($character['upcoming']) && ($character['status'] ?? 'released') !== 'speculated';
            // A missing element renders as "Traveler (undefined)"; the slug ("traveler_pyro") is reliable.
            $name = str_contains($character['name'], 'undefined')
                ? ucwords(str_replace('_', ' ', $character['id']))
                : $character['name'];
            $refs[] = new CharacterRef(
                slug: $character['id'],
                name: $name,
                url: $this->baseUrl().'/en/character/'.$character['id'],
                released: $released,
                meta: ['element' => $character['element']['id'] ?? null],
            );
        }

        return $refs;
    }

    public function fetchBuilds(CharacterRef $character): array
    {
        $html = $this->fetch($character->url);
        $props = AstroIslands::find($html, 'CharacterBuildTabs');
        if (! $props || empty($props['builds'])) {
            return [];
        }

        $version = preg_match('/\(v(\d+\.\d+)\)/', $html, $m) ? $m[1] : null;
        $weaponNames = array_map(fn ($weapon) => $weapon['name'] ?? null, $props['weapons'] ?? []);
        $artifacts = $props['artifacts'] ?? [];

        $builds = [];
        $usedNames = [];
        foreach ($props['builds'] as $build) {
            $variant = $this->prettyName((string) ($build['name'] ?? 'Build'));
            if (isset($usedNames[$variant])) {
                $variant .= ' #'.(++$usedNames[$variant]);
            } else {
                $usedNames[$variant] = 1;
            }

            $notes = trim(implode("\n", array_filter([
                $this->plain($build['description'] ?? null),
                $this->plain($build['build_notes'] ?? null),
            ])));

            $builds[] = new ScrapedBuild(
                variant: $variant,
                role: isset($build['role']) ? $this->prettyName((string) $build['role']) : null,
                recommended: (bool) ($build['recommended'] ?? false),
                weapons: $this->weapons($build, $weaponNames),
                sets: $this->sets($build, $artifacts),
                mainStats: $this->mainStats($build['stats'] ?? []),
                substats: Stats::ordered(Game::Genshin, $build['stats_priority'] ?? []),
                talents: Talents::ordered(Game::Genshin, $build['talent_priority'] ?? []),
                statTargets: StatTargets::extract(Game::Genshin, ($build['build_notes'] ?? '').' '.($build['description'] ?? '')),
                notes: $notes !== '' ? $notes : null,
                sourceUrl: $character->url,
                gameVersion: $version,
            );
        }

        return $builds;
    }

    protected function fetch(string $url): string
    {
        return $this->fetcher->get($url, [
            'browser' => true,
            'delay_ms' => $this->delayMs(),
            'cache_hours' => (int) $this->config->get('fetch.cache_hours', 12),
        ]);
    }

    /**
     * @param  array<string, string|null>  $names
     */
    protected function weapons(array $build, array $names): array
    {
        $notes = [];
        foreach ($build['weapon_notes'] ?? [] as $note) {
            $notes[$note['id'] ?? ''] = $this->plain($note['note'] ?? null);
        }

        $weapons = [];
        foreach ($build['weapons'] ?? [] as $weapon) {
            $id = $weapon['id'] ?? null;
            $name = $names[$id] ?? ($id ? ucwords(str_replace('_', ' ', $id)) : null);
            if (! $name) {
                continue;
            }
            $refinement = (int) ($weapon['r'] ?? 0);
            $weapons[] = [
                'name' => $name,
                'refinement' => $refinement > 0 ? $refinement : null,
                'note' => $notes[$id] ?? null,
            ];
        }

        return $weapons;
    }

    /**
     * One id = 4pc, two ids = 2pc + 2pc, three or more = "choose two" 2pc sets.
     * Pseudo sets such as "80elementalmastery_set" list their member sets as children.
     */
    protected function sets(array $build, array $artifacts): array
    {
        $notes = [];
        foreach ($build['artifact_notes'] ?? [] as $note) {
            $notes[$note['id'] ?? ''] = $this->plain($note['note'] ?? null);
        }

        $piece = function (string $id, int $count) use ($artifacts): array {
            $artifact = $artifacts[$id] ?? null;
            if ($artifact === null && ($pseudo = $this->pseudoSet($id))) {
                return $pseudo + ['count' => $count];
            }
            $children = array_values(array_filter(array_map(fn ($child) => $child['name'] ?? null, $artifact['children'] ?? [])));

            return [
                'names' => $children !== [] ? $children : [$artifact['name'] ?? ucwords(str_replace('_', ' ', $id))],
                'label' => $artifact['name'] ?? ucwords(str_replace('_', ' ', $id)),
                'count' => $count,
            ];
        };

        $options = [];
        foreach ($build['sets'] ?? [] as $option) {
            $ids = array_values(array_filter((array) $option, 'is_string'));
            if ($ids === []) {
                continue;
            }

            $isPseudo = fn (string $id) => ! empty($artifacts[$id]['children'])
                || (! isset($artifacts[$id]) && $this->pseudoSet($id) !== null);
            if (count($ids) === 1 && ! $isPseudo($ids[0])) {
                $options[] = ['kind' => 'artifact', 'choose' => null, 'pieces' => [$piece($ids[0], 4)], 'note' => $notes[$ids[0]] ?? null];

                continue;
            }

            $options[] = [
                'kind' => 'artifact',
                'choose' => count($ids) > 2 ? 2 : null,
                'pieces' => array_map(fn ($id) => $piece($id, 2), $ids),
                'note' => $notes[$ids[0]] ?? null,
            ];
        }

        return $options;
    }

    /**
     * Pseudo sets the page does not describe, e.g. "15_cryo_dmg_set" or "30_def_set": any set whose
     * 2-piece bonus gives that stat.
     *
     * @return array{names: list<string>, label: string}|null
     */
    protected function pseudoSet(string $id): ?array
    {
        $key = preg_replace('/[^a-z0-9]/', '', strtolower($id)) ?? '';
        if (! preg_match('/^(?:and)?\d*([a-z]+?)(?:dmg)?(?:bonus)?set$/', $key, $m)) {
            return null;
        }

        $stat = Stats::parse($this->game(), $m[1])[0] ?? null;
        $names = $stat ? $this->setGroups->names($this->game(), $stat) : [];

        return $names === [] ? null : [
            'names' => $names,
            'label' => Stats::label($stat, $this->game(), true).' set',
        ];
    }

    /**
     * @param  array<string, list<string>>  $stats
     */
    protected function mainStats(array $stats): array
    {
        $result = [];
        foreach ($this->game()->variableSlots() as $slot) {
            // Listed alternatives are in preference order; a CRIT Rate / CRIT DMG pair counts as equal.
            $ordered = Stats::ordered($this->game(), array_map('strval', $stats[$slot] ?? []));
            if ($ordered !== []) {
                $result[$slot] = $ordered;
            }
        }

        return $result;
    }

    /**
     * "DPS & ENERGY SUPPORT" => "DPS & Energy Support".
     */
    protected function prettyName(string $name): string
    {
        $name = trim($name);
        if ($name !== mb_strtoupper($name)) {
            return $name;
        }

        $pretty = ucwords(mb_strtolower($name), " \t-/&(");

        return preg_replace_callback(
            '/\b(Dps|Sub-dps|Em|Er|Atk|Hp|Def|Crit|Aoe|Na|Ca|F2p|Dot|Spd|Be|Ehr)\b/i',
            fn ($m) => strtoupper($m[0]),
            $pretty,
        ) ?? $pretty;
    }

    protected function plain(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        $text = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
    }
}
