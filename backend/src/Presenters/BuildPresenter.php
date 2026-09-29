<?php

namespace App\Presenters;

use App\Models\Build;
use App\Repositories\CatalogRows;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Catalog\Catalog;
use App\Support\Clock;
use App\Support\Neutral;

/**
 * Builds as the API sends them, with weapon and set icons from the catalog.
 *
 * A build is shown as coming from "Source N"; the page it was read from is not sent at all, and every text
 * copied from the page (notes, names of variants, stat target texts) is passed through Neutral::text().
 */
final class BuildPresenter
{
    public function __construct(private readonly CatalogRows $rows, private readonly BuildSourceRegistry $registry) {}

    /**
     * @param  list<Build>  $builds  with their source and character
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
                    array_push($setKeys, ...($piece['keys'] ?? []));
                }
            }
        }

        $weapons = $this->rows->byKeys($game, Catalog::WEAPON, $weaponKeys);
        $sets = $this->rows->byKeys($game, Catalog::SET, $setKeys);

        return array_map(fn (Build $build) => $this->present($build, $weapons, $sets), $builds);
    }

    public function sourceOf(Build $build): array
    {
        return ['key' => $this->registry->publicKey($build->source->key), 'name' => $this->registry->label($build->source->key)];
    }

    private function present(Build $build, array $weapons, array $sets): array
    {
        return [
            'id' => $build->id,
            'source' => $this->sourceOf($build),
            'variant' => Neutral::text($build->variant),
            'role' => Neutral::text($build->role),
            'recommended' => $build->recommended,
            'weapons' => array_map(fn ($weapon) => Neutral::deep($weapon) + [
                'icon_url' => Icons::of($weapons[$weapon['key']] ?? null),
                'rarity' => ($weapons[$weapon['key']] ?? null)?->rarity,
            ], $build->weapons),
            'sets' => array_map(fn ($option) => array_merge(Neutral::deep($option), [
                'pieces' => array_map(fn ($piece) => Neutral::deep($piece) + [
                    'icon_url' => count($piece['keys']) === 1 ? Icons::of($sets[$piece['keys'][0]] ?? null) : null,
                ], $option['pieces']),
            ]), $build->sets),
            'main_stats' => (object) $build->main_stats,
            'substats' => $build->substats,
            'talent_priority' => $build->talent_priority,
            'stat_targets' => Neutral::deep($build->stat_targets),
            'notes' => Neutral::text($build->notes),
            'source_updated_at' => Clock::iso($build->source_updated_at),
            'game_version' => $build->game_version,
            'fetched_at' => Clock::iso($build->fetched_at),
            'changed_at' => Clock::iso($build->changed_at),
        ];
    }
}
