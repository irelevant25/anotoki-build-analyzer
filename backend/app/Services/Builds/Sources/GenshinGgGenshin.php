<?php

namespace App\Services\Builds\Sources;

use App\Enums\Game;
use App\Services\Builds\CharacterRef;
use App\Services\Builds\ScrapedBuild;
use App\Support\Stats;

class GenshinGgGenshin extends GenshinGgSource
{
    public function key(): string
    {
        return 'genshin-gg.genshin';
    }

    public function game(): Game
    {
        return Game::Genshin;
    }

    protected function isCharacterList(array $first): bool
    {
        return isset($first['build'], $first['type'], $first['weapon']);
    }

    protected function characterUrl(string $slug): string
    {
        return $this->baseUrl().'/characters/'.$slug.'/';
    }

    public function fetchBuilds(CharacterRef $character): array
    {
        $raw = $this->raw($character);
        $build = $raw['build'] ?? null;
        if (! is_array($build) || empty($build['weapons'])) {
            return [];
        }

        $weapons = [];
        foreach ($this->lines($build['weapons']) as $name) {
            [$base, $refinement] = $this->splitRefinement($name);
            if ($base !== '') {
                $weapons[] = ['name' => $base, 'refinement' => $refinement, 'note' => null];
            }
        }

        $sets = array_values(array_filter(array_map(fn ($text) => $this->setOption($text, 'artifact'), $this->lines($build['artifacts'] ?? []))));

        // stats = [sands, goblet, circlet]; "HP% / Elemental Mastery" lists equal alternatives.
        $mainStats = [];
        foreach (['sands', 'goblet', 'circlet'] as $index => $slot) {
            $parsed = Stats::parsePriorityList(Game::Genshin, (string) ($build['stats'][$index] ?? ''));
            if ($parsed !== []) {
                $mainStats[$slot] = $parsed;
            }
        }

        $substatText = implode(' > ', array_map('strval', $build['substats'] ?? []));

        return [new ScrapedBuild(
            variant: trim((string) ($build['name'] ?? '')) ?: 'Build',
            role: $raw['role'] ?? null,
            recommended: true,
            weapons: $weapons,
            sets: $sets,
            mainStats: $mainStats,
            substats: Stats::parsePriorityList(Game::Genshin, $substatText),
            sourceUrl: $character->url,
        )];
    }
}
