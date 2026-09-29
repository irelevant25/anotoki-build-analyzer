<?php

namespace App\Services\Builds\Sources;

use App\Enums\Game;
use App\Services\Builds\BuildSource;
use App\Services\Builds\CharacterRef;
use App\Services\Builds\Parsing\NextFlight;
use App\Services\Builds\Parsing\StatTargets;
use App\Services\Builds\ScrapedBuild;
use App\Config;
use App\Http\Fetcher;
use App\Support\Stats;
use App\Support\Talents;
use App\Support\Clock;
use RuntimeException;

/**
 * prydwen.gg Honkai: Star Rail (Next.js App Router). The build block is a props object in the RSC payload
 * with statBuilds / relicBuilds / coneBuilds / skillOrder. robots.txt asks for a 10 s crawl delay.
 */
class PrydwenHsr implements BuildSource
{
    public function __construct(protected readonly Fetcher $fetcher, protected readonly Config $config) {}

    public function key(): string
    {
        return 'prydwen.hsr';
    }

    public function game(): Game
    {
        return Game::Hsr;
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
        $html = $this->fetch($this->baseUrl().'/star-rail/characters');
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
                url: $this->baseUrl().'/star-rail/characters/'.$character['slug'],
                released: ($character['isReleased'] ?? true) === true && empty($character['upcoming']),
                meta: ['element' => isset($character['element']) ? strtolower($character['element']) : null],
            );
        }

        return $refs;
    }

    public function fetchBuilds(CharacterRef $character): array
    {
        $html = $this->fetch($character->url);
        $props = NextFlight::findProps($html, 'statBuilds');
        if (! $props || empty($props['statBuilds']) || ($props['character']['showBuild'] ?? true) === false) {
            return [];
        }

        $info = $props['character'] ?? [];
        $weapons = $this->lightCones($props['coneBuilds'] ?? []);
        $sets = $this->sets($props['relicBuilds'] ?? []);
        $talents = Talents::parsePriority(Game::Hsr, (string) ($props['skillOrder']['Order'] ?? ''));
        $endgame = StatTargets::extract(Game::Hsr, $info['endgameStats'] ?? null);
        $updatedAt = ! empty($info['updatedAt']) ? Clock::parse($info['updatedAt']) : null;
        $version = $this->value($props['lastUpdate']['build'] ?? null);

        $builds = [];
        foreach (array_values($props['statBuilds']) as $index => $stat) {
            $mainStats = [];
            foreach (['body', 'feet', 'sphere', 'rope'] as $slot) {
                $parsed = Stats::parsePriorityList(Game::Hsr, (string) ($stat[$slot] ?? ''));
                if ($parsed !== []) {
                    $mainStats[$slot] = $parsed;
                }
            }

            $substatText = (string) ($stat['substats'] ?? '');
            $targets = $this->mergeTargets($endgame, $this->untilTargets($substatText));

            $notes = array_filter([
                $this->plain($stat['comments'] ?? null),
                ! empty($props['skillOrder']['TracePriority']) ? 'Major traces: '.$props['skillOrder']['TracePriority'] : null,
            ]);

            $builds[] = new ScrapedBuild(
                variant: trim((string) ($stat['name'] ?? '')) ?: 'Build',
                role: null,
                recommended: $index === 0,
                weapons: $weapons,
                sets: $sets,
                mainStats: $mainStats,
                substats: Stats::parsePriorityList(Game::Hsr, $substatText),
                talents: $talents,
                statTargets: $targets,
                notes: $notes ? implode("\n", $notes) : null,
                sourceUrl: $character->url,
                updatedAt: $updatedAt,
                gameVersion: $version,
            );
        }

        return $builds;
    }

    protected function fetch(string $url): string
    {
        return $this->fetcher->get($url, [
            'browser' => true, // Cloudflare challenges non-browser user agents
            'delay_ms' => $this->delayMs(),
            'cache_hours' => (int) $this->config->get('fetch.cache_hours', 12),
        ]);
    }

    private function lightCones(array $cones): array
    {
        $result = [];
        foreach ($cones as $cone) {
            $name = trim((string) ($cone['cone_name'] ?? ''));
            if ($name === '' || in_array($name, array_column($result, 'name'), true)) {
                continue;
            }
            $superimposition = (int) ($cone['dupes'] ?? 0);
            $result[] = [
                'name' => $name,
                'refinement' => $superimposition > 0 ? $superimposition : null,
                'note' => $this->plain($cone['comment'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * relic_set_2: "" = 4pc of relic_set_1, "Flex" = 2pc + any 2pc from flex_options, otherwise 2pc + 2pc.
     * Planar ornaments are listed in ornament_options (usually on the first relic build).
     */
    private function sets(array $relicBuilds): array
    {
        $relics = [];
        $planars = [];

        foreach ($relicBuilds as $build) {
            $first = trim((string) ($build['relic_set_1'] ?? ''));
            $second = trim((string) ($build['relic_set_2'] ?? ''));
            $note = $this->plain($build['comment'] ?? null);

            if ($first !== '') {
                if ($second === '') {
                    $pieces = [['names' => [$first], 'label' => $first, 'count' => 4]];
                } elseif (strcasecmp($second, 'Flex') === 0) {
                    $flex = array_values(array_filter(array_map('trim', $build['flex_options'] ?? [])));
                    $pieces = [['names' => [$first], 'label' => $first, 'count' => 2]];
                    if ($flex !== []) {
                        $pieces[] = ['names' => $flex, 'label' => 'Flex: '.implode(' / ', $flex), 'count' => 2];
                    }
                } else {
                    $pieces = [
                        ['names' => [$first], 'label' => $first, 'count' => 2],
                        ['names' => [$second], 'label' => $second, 'count' => 2],
                    ];
                }
                $relics[] = ['kind' => 'relic', 'choose' => null, 'pieces' => $pieces, 'note' => $note];
            }

            foreach ($build['ornament_options'] ?? [] as $ornament) {
                $name = trim((string) ($ornament['name'] ?? ''));
                if ($name === '' || isset($planars[$name])) {
                    continue;
                }
                $planars[$name] = [
                    'kind' => 'planar',
                    'choose' => null,
                    'pieces' => [['names' => [$name], 'label' => $name, 'count' => 2]],
                    'note' => $this->plain($ornament['comment'] ?? null),
                ];
            }
        }

        return array_merge($relics, array_values($planars));
    }

    /**
     * "Effect Hit Rate (Until 75%) > SPD" => EHR target 75.
     */
    private function untilTargets(string $substats): array
    {
        $targets = [];
        preg_match_all('/([A-Za-z][A-Za-z %.]*?)\s*\(\s*(?:until|up to|to|at least|min(?:imum)?)?\s*(\d+(?:\.\d+)?)\s*(%)?\s*\+?\s*\)/i', $substats, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $stat = Stats::parse(Game::Hsr, trim($m[1]))[0] ?? null;
            if ($stat) {
                $targets[] = ['stat' => $stat, 'min' => (float) $m[2], 'max' => null, 'text' => trim($m[0])];
            }
        }

        return $targets;
    }

    private function mergeTargets(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $target) {
                $merged[$target['stat']] ??= $target;
            }
        }

        return array_values($merged);
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
