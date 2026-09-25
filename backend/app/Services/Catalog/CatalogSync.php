<?php

namespace App\Services\Catalog;

use App\Enums\Game;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Services\Http\Fetcher;
use App\Support\Key;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Downloads the character / weapon / set lists from Project Amber (yatta.moe) and upserts the catalog.
 */
class CatalogSync
{
    private const GENSHIN_ELEMENTS = [
        'Fire' => 'pyro', 'Water' => 'hydro', 'Wind' => 'anemo', 'Electric' => 'electro',
        'Grass' => 'dendro', 'Ice' => 'cryo', 'Rock' => 'geo',
    ];

    private const GENSHIN_WEAPONS = [
        'WEAPON_SWORD_ONE_HAND' => 'sword', 'WEAPON_CLAYMORE' => 'claymore', 'WEAPON_POLE' => 'polearm',
        'WEAPON_BOW' => 'bow', 'WEAPON_CATALYST' => 'catalyst',
    ];

    private const HSR_ELEMENTS = [
        'Physical' => 'physical', 'Fire' => 'fire', 'Ice' => 'ice', 'Thunder' => 'lightning',
        'Wind' => 'wind', 'Quantum' => 'quantum', 'Imaginary' => 'imaginary',
    ];

    public const HSR_PATHS = [
        'Warrior' => 'destruction', 'Rogue' => 'hunt', 'Mage' => 'erudition', 'Shaman' => 'harmony',
        'Warlock' => 'nihility', 'Knight' => 'preservation', 'Priest' => 'abundance', 'Memory' => 'remembrance',
        'Elation' => 'elation',
    ];

    private const HSR_PATH_LABELS = [
        'destruction' => 'Destruction', 'hunt' => 'The Hunt', 'erudition' => 'Erudition', 'harmony' => 'Harmony',
        'nihility' => 'Nihility', 'preservation' => 'Preservation', 'abundance' => 'Abundance',
        'remembrance' => 'Remembrance', 'elation' => 'Elation',
    ];

    public function __construct(private readonly Fetcher $fetcher, private readonly Catalog $catalog) {}

    /**
     * @return array{characters: int, weapons: int, sets: int}
     */
    public function sync(Game $game): array
    {
        $counts = DB::transaction(fn () => $game === Game::Genshin ? $this->syncGenshin() : $this->syncHsr());
        $this->catalog->flush();

        return $counts;
    }

    /**
     * True when the catalog for a game is empty or older than the configured refresh interval.
     */
    public function isStale(Game $game): bool
    {
        $latest = GameCharacter::query()->where('game', $game->value)->whereNotNull('external_id')->max('updated_at');

        return ! $latest || Carbon::parse($latest)->lt(now()->subDays((int) config('hoyo.catalog.refresh_days')));
    }

    private function syncGenshin(): array
    {
        $api = config('hoyo.catalog.genshin.api');
        $assets = config('hoyo.catalog.genshin.assets');
        $game = Game::Genshin;

        $characters = 0;
        $travelers = [];
        foreach ($this->items("{$api}/avatar") as $id => $item) {
            $element = self::GENSHIN_ELEMENTS[$item['element'] ?? ''] ?? null;
            $row = [
                'name' => $item['name'],
                'element' => $element,
                'rarity' => $this->rarity($item['rank'] ?? null),
                'weapon_type' => self::GENSHIN_WEAPONS[$item['weaponType'] ?? ''] ?? null,
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/{$item['icon']}.png" : null,
                'released_at' => isset($item['release']) ? Carbon::createFromTimestamp($item['release']) : null,
            ];

            if ($item['name'] === 'Traveler' && $element) {
                // Aether (10000005) and Lumine (10000007) share one entry per element.
                $key = 'traveler'.$element;
                $travelers[$key]['ids'][] = (string) $id;
                $travelers[$key]['row'] ??= ['name' => 'Traveler ('.ucfirst($element).')'] + $row;

                continue;
            }

            $this->upsert(GameCharacter::class, $game, Key::from($item['name']), $row);
            $characters++;
        }

        foreach ($travelers as $key => $traveler) {
            $this->upsert(GameCharacter::class, $game, $key, $traveler['row'] + ['meta' => ['ids' => $traveler['ids']]]);
            $characters++;
        }

        $weapons = 0;
        $seen = [];
        $weaponItems = $this->items("{$api}/weapon");
        ksort($weaponItems);
        foreach ($weaponItems as $id => $item) {
            $key = Key::from($item['name']);
            // A few weapons exist twice (quest / trial copies); keep the lowest ID.
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->upsert(GameWeapon::class, $game, $key, [
                'name' => $item['name'],
                'rarity' => $this->rarity($item['rank'] ?? null),
                'weapon_type' => self::GENSHIN_WEAPONS[$item['type'] ?? ''] ?? null,
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/{$item['icon']}.png" : null,
            ]);
            $weapons++;
        }

        $sets = 0;
        foreach ($this->items("{$api}/reliquary") as $id => $item) {
            $this->upsert(GameSet::class, $game, Key::from($item['name']), [
                'name' => $item['name'],
                'kind' => 'artifact',
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/reliquary/{$item['icon']}.png" : null,
                'meta' => ['rarities' => $item['levelList'] ?? [], 'bonuses' => array_values($item['affixList'] ?? [])],
            ]);
            $sets++;
        }

        return compact('characters', 'weapons', 'sets');
    }

    private function syncHsr(): array
    {
        $api = config('hoyo.catalog.hsr.api');
        $assets = config('hoyo.catalog.hsr.assets');
        $game = Game::Hsr;

        $items = $this->items("{$api}/avatar");
        ksort($items);

        // Detect names shared by several characters (e.g. the two March 7th) to disambiguate by path.
        $nameCounts = array_count_values(array_map(fn ($item) => Key::from($item['name']), $items));

        $characters = 0;
        $trailblazers = [];
        foreach ($items as $id => $item) {
            $path = self::HSR_PATHS[$item['types']['pathType'] ?? ''] ?? null;
            $row = [
                'name' => $item['name'],
                'element' => self::HSR_ELEMENTS[$item['types']['combatType'] ?? ''] ?? null,
                'rarity' => $this->rarity($item['rank'] ?? null),
                'weapon_type' => $path,
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/avatar/{$item['icon']}.png" : null,
                'released_at' => isset($item['release']) ? Carbon::createFromTimestamp($item['release']) : null,
            ];

            $key = Key::from($item['name']);
            if ($key === 'trailblazer' && $path) {
                $tbKey = 'trailblazer'.$path;
                $trailblazers[$tbKey]['ids'][] = (string) $id;
                $trailblazers[$tbKey]['row'] ??= ['name' => 'Trailblazer ('.self::HSR_PATH_LABELS[$path].')'] + $row;

                continue;
            }

            if ($nameCounts[$key] > 1 && $path) {
                $first = min(array_keys(array_filter($items, fn ($other) => Key::from($other['name']) === $key)));
                if ((string) $first !== (string) $id) {
                    $key .= $path;
                    $row['name'] .= ' ('.self::HSR_PATH_LABELS[$path].')';
                }
            }

            $this->upsert(GameCharacter::class, $game, $key, $row);
            $characters++;
        }

        foreach ($trailblazers as $key => $trailblazer) {
            $this->upsert(GameCharacter::class, $game, $key, $trailblazer['row'] + ['meta' => ['ids' => $trailblazer['ids']]]);
            $characters++;
        }

        $weapons = 0;
        foreach ($this->items("{$api}/equipment") as $id => $item) {
            $this->upsert(GameWeapon::class, $game, Key::from($item['name']), [
                'name' => $item['name'],
                'rarity' => $this->rarity($item['rank'] ?? null),
                'weapon_type' => self::HSR_PATHS[$item['types']['pathType'] ?? ''] ?? null,
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/equipment/medium/{$item['icon']}.png" : null,
            ]);
            $weapons++;
        }

        $sets = 0;
        foreach ($this->items("{$api}/relic") as $id => $item) {
            $this->upsert(GameSet::class, $game, Key::from($item['name']), [
                'name' => $item['name'],
                'kind' => ($item['isPlanarSuit'] ?? false) ? 'planar' : 'relic',
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/relic/{$item['icon']}.png" : null,
            ]);
            $sets++;
        }

        return compact('characters', 'weapons', 'sets');
    }

    /**
     * @return array<string|int, array<string, mixed>>
     */
    private function items(string $url): array
    {
        $json = $this->fetcher->getJson($url, ['delay_ms' => 300]);
        $items = $json['data']['items'] ?? [];

        // Names can contain in-game markup such as "Silver Wolf LV.<unbreak>999</unbreak>".
        foreach ($items as &$item) {
            if (isset($item['name'])) {
                $item['name'] = trim(html_entity_decode(strip_tags((string) $item['name']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return $items;
    }

    private function rarity(mixed $rank): ?int
    {
        if (! is_numeric($rank)) {
            return null;
        }

        // Aloy is flagged as a special rarity (105) in game data.
        return (int) $rank > 5 ? 5 : (int) $rank;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function upsert(string $class, Game $game, string $key, array $row): void
    {
        if ($key === '') {
            return;
        }

        // Match by in-game ID first so a renamed entry (new English name, fixed markup) keeps its row and
        // everything linked to it, unless another row already owns the new key.
        $existing = null;
        if (! empty($row['external_id'])) {
            $existing = $class::query()->where('game', $game->value)->where('external_id', $row['external_id'])->first();
        }
        if ($existing && $existing->key !== $key && $class::query()->where('game', $game->value)->where('key', $key)->exists()) {
            $existing = null;
        }

        if ($existing) {
            $existing->fill(['key' => $key] + $row)->save();

            return;
        }

        $class::query()->updateOrCreate(['game' => $game->value, 'key' => $key], $row);
    }
}
