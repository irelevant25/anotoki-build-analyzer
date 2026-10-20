<?php

namespace App\Presenters;

use App\Models\Build;
use App\Repositories\CatalogRows;
use App\Services\Catalog\Catalog;
use App\Support\Clock;
use App\Support\Neutral;

/**
 * Builds as the API sends them, with weapon and set icons from the catalog.
 *
 * A build is a variation of its character's - a role, a qualifier, the constellation it needs - and names nowhere
 * it came from: every text it was read with (notes, its qualifier, names, stat target texts) is passed through
 * Neutral once more on the way out.
 */
final class BuildPresenter
{
    public function __construct(private readonly CatalogRows $rows) {}

    /**
     * @param  list<Build>  $builds  with their character
     * @return list<array>
     */
    public function collection(array $builds): array
    {
        if ($builds === []) {
            return [];
        }

        $game = $builds[0]->character->game;
        $weaponKeys = [];
        $setKeys = [];
        foreach ($builds as $build) {
            array_push($weaponKeys, ...array_column($build->weapons, 'key'));
            foreach ($build->sets as $option) {
                foreach ($option['pieces'] ?? [] as $piece) {
                    array_push($setKeys, ...$piece['keys'] ?? []);
                }
            }
        }

        $weapons = $this->rows->byKeys($game, Catalog::WEAPON, $weaponKeys);
        $sets = $this->rows->byKeys($game, Catalog::SET, $setKeys);

        return array_map(fn(Build $build) => $this->present($build, $weapons, $sets), $builds);
    }

    private function present(Build $build, array $weapons, array $sets): array
    {
        return [
            'id' => $build->id,
            'role' => $build->role,
            'qualifier' => (string) Neutral::text($build->qualifier),
            'min_rank' => $build->min_rank,
            'position' => $build->position,
            'weapons' => array_map(
                fn($weapon) => Neutral::deep($weapon) + [
                    'icon_url' => Icons::of($weapons[$weapon['key']] ?? null),
                    'rarity' => ($weapons[$weapon['key']] ?? null)?->rarity,
                ],
                $build->weapons,
            ),
            'sets' => array_map(
                fn($option) => array_merge(Neutral::deep($option), [
                    'pieces' => array_map(
                        fn($piece) => Neutral::deep($piece) + [
                            'icon_url' => count($piece['keys']) === 1 ? Icons::of($sets[$piece['keys'][0]] ?? null) : null,
                        ],
                        $option['pieces'],
                    ),
                ]),
                $build->sets,
            ),
            'main_stats' => (object) $build->main_stats,
            'substats' => $build->substats,
            'talent_priority' => $build->talent_priority,
            'stat_targets' => Neutral::deep($build->stat_targets),
            'notes' => Neutral::text($build->notes),
            'game_version' => Neutral::text($build->game_version),
            'checked_at' => Clock::iso($build->checked_at),
            'changed_at' => Clock::iso($build->changed_at),
        ];
    }
}
