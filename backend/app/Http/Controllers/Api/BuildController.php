<?php

namespace App\Http\Controllers\Api;

use App\Enums\Game;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BuildPresenter;
use App\Models\Build;
use App\Models\GameCharacter;
use Illuminate\Validation\Rule;

/**
 * Browse scraped builds independently of any account.
 */
class BuildController extends Controller
{
    /**
     * Characters of a game that have at least one build, with per-source counts.
     */
    public function characters(string $game): array
    {
        validator(['game' => $game], ['game' => [Rule::enum(Game::class)]])->validate();

        $builds = Build::query()
            ->with('source:id,key,name')
            ->whereHas('character', fn ($query) => $query->where('game', $game))
            ->get(['id', 'build_source_id', 'game_character_id', 'changed_at', 'source_updated_at'])
            ->groupBy('game_character_id');

        return GameCharacter::query()
            ->whereIn('id', $builds->keys())
            ->orderByDesc('released_at')
            ->orderBy('name')
            ->get()
            ->map(function (GameCharacter $character) use ($builds) {
                $list = $builds->get($character->id);

                return [
                    'key' => $character->key,
                    'name' => $character->name,
                    'element' => $character->element,
                    'rarity' => $character->rarity,
                    'weapon_type' => $character->weapon_type,
                    'icon_url' => $character->icon_url,
                    'released_at' => $character->released_at?->toDateString(),
                    'builds' => $list->count(),
                    'sources' => $list->pluck('source.name')->unique()->values()->all(),
                    'changed_at' => $list->max('changed_at')?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * All builds of one character, with weapon / set icons from the catalog.
     */
    public function character(string $game, string $key): array
    {
        $character = GameCharacter::query()->where('game', $game)->where('key', $key)->firstOrFail();
        $builds = Build::query()->with(['source', 'character'])->where('game_character_id', $character->id)
            ->whereHas('source', fn ($query) => $query->where('enabled', true))
            ->orderBy('build_source_id')->orderBy('position')->get();

        return [
            'character' => [
                'key' => $character->key,
                'name' => $character->name,
                'element' => $character->element,
                'rarity' => $character->rarity,
                'weapon_type' => $character->weapon_type,
                'icon_url' => $character->icon_url,
            ],
            'builds' => BuildPresenter::collection($builds),
        ];
    }
}
