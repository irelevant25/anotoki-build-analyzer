<?php

namespace App\Services\Builds\Sources;

use App\Enums\Game;
use App\Services\Builds\CharacterRef;
use App\Services\Builds\ScrapedBuild;
use App\Support\Stats;

class GenshinGgHsr extends GenshinGgSource
{
    public function key(): string
    {
        return 'genshin-gg.hsr';
    }

    public function game(): Game
    {
        return Game::Hsr;
    }

    protected function isCharacterList(array $first): bool
    {
        return isset($first['light_cone'], $first['relic'], $first['path']);
    }

    protected function characterUrl(string $slug): string
    {
        return $this->baseUrl() . '/star-rail/characters/' . $slug . '/';
    }

    public function fetchBuilds(CharacterRef $character): array
    {
        $raw = $this->raw($character);
        if (!$raw || empty($raw['light_cone'])) {
            return [];
        }

        $weapons = [];
        foreach ($this->lines($raw['light_cone']) as $name) {
            [$base, $superimposition] = $this->splitRefinement($name);
            if ($base !== '') {
                $weapons[] = ['name' => $base, 'refinement' => $superimposition, 'note' => null];
            }
        }

        $sets = [
            ...array_map(fn($text) => $this->setOption($text, 'relic'), $this->lines($raw['relic'] ?? [])),
            ...array_map(fn($text) => $this->setOption($text, 'planar'), $this->lines($raw['ornament'] ?? [])),
        ];

        // main_stats = [body, feet, sphere, rope]; "Any" means no preference.
        $mainStats = [];
        foreach (['body', 'feet', 'sphere', 'rope'] as $index => $slot) {
            $parsed = Stats::parsePriorityList(Game::Hsr, (string) ($raw['main_stats'][$index] ?? ''));
            if ($parsed !== []) {
                $mainStats[$slot] = $parsed;
            }
        }

        // sub_stats are ordered and may carry goals inline: "Speed (186+)".
        $targets = [];
        foreach ($raw['sub_stats'] ?? [] as $text) {
            if (preg_match('/^(.*?)\s*\(\s*(\d+(?:\.\d+)?)\s*(%)?\s*\+?\s*\)/', (string) $text, $m)) {
                $stat = Stats::parse(Game::Hsr, $m[1])[0] ?? null;
                if ($stat) {
                    $targets[] = ['stat' => $stat, 'min' => (float) $m[2], 'max' => null, 'text' => trim((string) $text)];
                }
            }
        }

        return [
            new ScrapedBuild(
                variant: 'Build',
                role: null,
                recommended: true,
                weapons: $weapons,
                sets: array_values(array_filter($sets)),
                mainStats: $mainStats,
                substats: Stats::parsePriorityList(Game::Hsr, implode(' > ', array_map('strval', $raw['sub_stats'] ?? []))),
                statTargets: $targets,
                sourceUrl: $character->url,
            ),
        ];
    }
}
