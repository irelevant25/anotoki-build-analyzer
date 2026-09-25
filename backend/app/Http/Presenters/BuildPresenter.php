<?php

namespace App\Http\Presenters;

use App\Models\Build;
use App\Models\GameSet;
use App\Models\GameWeapon;
use Illuminate\Support\Collection;

/**
 * API representation of builds, with weapon and set icons looked up from the catalog.
 */
final class BuildPresenter
{
    /**
     * @param  Collection<int, Build>  $builds  (with "source" and "character" loaded)
     * @return list<array>
     */
    public static function collection(Collection $builds): array
    {
        if ($builds->isEmpty()) {
            return [];
        }

        $game = $builds->first()->character->game->value;
        $weaponKeys = $builds->flatMap(fn (Build $build) => array_column($build->weapons ?? [], 'key'))->unique()->values();
        $setKeys = $builds->flatMap(fn (Build $build) => collect($build->sets ?? [])
            ->flatMap(fn ($option) => collect($option['pieces'])->flatMap(fn ($piece) => $piece['keys'])))->unique()->values();

        $weapons = GameWeapon::query()->where('game', $game)->whereIn('key', $weaponKeys)->get()->keyBy('key');
        $sets = GameSet::query()->where('game', $game)->whereIn('key', $setKeys)->get()->keyBy('key');

        return $builds->map(fn (Build $build) => self::present($build, $weapons, $sets))->values()->all();
    }

    private static function present(Build $build, Collection $weapons, Collection $sets): array
    {
        return [
            'id' => $build->id,
            'source' => ['key' => $build->source->key, 'name' => $build->source->name, 'base_url' => $build->source->base_url],
            'variant' => $build->variant,
            'role' => $build->role,
            'recommended' => $build->recommended,
            'weapons' => array_map(fn ($weapon) => $weapon + [
                'icon_url' => $weapons->get($weapon['key'])?->icon_url,
                'rarity' => $weapons->get($weapon['key'])?->rarity,
            ], $build->weapons ?? []),
            'sets' => array_map(fn ($option) => array_merge($option, [
                'pieces' => array_map(fn ($piece) => $piece + [
                    'icon_url' => count($piece['keys']) === 1 ? $sets->get($piece['keys'][0])?->icon_url : null,
                ], $option['pieces']),
            ]), $build->sets ?? []),
            'main_stats' => (object) ($build->main_stats ?? []),
            'substats' => $build->substats ?? [],
            'talent_priority' => $build->talent_priority,
            'stat_targets' => $build->stat_targets,
            'notes' => $build->notes,
            'source_url' => $build->source_url,
            'source_updated_at' => $build->source_updated_at?->toIso8601String(),
            'game_version' => $build->game_version,
            'fetched_at' => $build->fetched_at?->toIso8601String(),
            'changed_at' => $build->changed_at?->toIso8601String(),
        ];
    }
}
