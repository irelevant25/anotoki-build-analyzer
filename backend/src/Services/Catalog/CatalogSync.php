<?php

namespace App\Services\Catalog;

use App\Cache\Cache;
use App\Config;
use App\Db\Db;
use App\Enums\Game;
use App\Http\Fetcher;
use App\Repositories\CatalogRows;
use App\Support\Clock;
use App\Support\Key;
use DateTimeImmutable;

/**
 * Downloads the character / weapon / set lists of the game data provider (config/app.php "catalog") and
 * upserts the catalog.
 *
 * Each list is one download and one transaction, so the Builds page refreshes one list per request and an
 * import can refresh just the list it needs. When a list was last refreshed, and how many entries that wrote, is
 * kept in the cache table (catalog-state:{game}:{list}), which a restore leaves as it is.
 */
class CatalogSync
{
    /** @var list<string> the lists of one game, in the order the Builds page refreshes them */
    public const LISTS = [Catalog::CHARACTER, Catalog::WEAPON, Catalog::SET];

    /** How long a list's state is kept: for good, but for the cache table's rule that everything expires. */
    private const STATE_SECONDS = 10 * 365 * 86400;

    private const GENSHIN_ELEMENTS = [
        'Fire' => 'pyro',
        'Water' => 'hydro',
        'Wind' => 'anemo',
        'Electric' => 'electro',
        'Grass' => 'dendro',
        'Ice' => 'cryo',
        'Rock' => 'geo',
    ];

    private const GENSHIN_WEAPONS = [
        'WEAPON_SWORD_ONE_HAND' => 'sword',
        'WEAPON_CLAYMORE' => 'claymore',
        'WEAPON_POLE' => 'polearm',
        'WEAPON_BOW' => 'bow',
        'WEAPON_CATALYST' => 'catalyst',
    ];

    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly Catalog $catalog,
        private readonly CatalogRows $rows,
        private readonly Db $db,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    /**
     * All three lists of a game in one go.
     *
     * @return array{characters: int, weapons: int, sets: int}
     */
    public function sync(Game $game): array
    {
        $counts = [];
        foreach (self::LISTS as $type) {
            $counts[$type] = $this->syncList($game, $type);
        }

        /** @var array{characters: int, weapons: int, sets: int} */
        return $counts;
    }

    /**
     * Download one list (characters, weapons or sets) and upsert it.
     *
     * @return int rows written
     */
    public function syncList(Game $game, string $type): int
    {
        $method = match ($type) {
            Catalog::CHARACTER => 'Characters',
            Catalog::WEAPON => 'Weapons',
            Catalog::SET => 'Sets',
            default => throw new \InvalidArgumentException("Unknown catalog list [{$type}]"),
        };
        $method =
            match ($game) {
                Game::Genshin => 'genshin',
            } . $method;

        // Downloaded before the transaction starts, so none is held open across a request.
        $items = $this->download($game, $type);
        $count = $this->db->transaction(fn() => $this->{$method}($items));
        $this->catalog->flush();
        $this->cache->put(self::stateKey($game, $type), ['refreshed_at' => Clock::timestamp(), 'count' => $count], self::STATE_SECONDS);

        return $count;
    }

    /**
     * Each list of a game as the Builds page shows it: how many entries the catalog has, and when the list was last
     * refreshed (null: not since this was kept).
     *
     * @return list<array{list: string, entries: int, refreshed_at: string|null}>
     */
    public function lists(Game $game): array
    {
        return array_map(
            fn(string $type) => [
                'list' => $type,
                'entries' => (int) $this->db->value('SELECT count(*) FROM ' . CatalogRows::table($type) . ' WHERE game = ?', [$game->value]),
                'refreshed_at' => Clock::iso($this->refreshedAt($game, $type)),
            ],
            self::LISTS,
        );
    }

    /**
     * When the catalog of a game was last refreshed: when its stalest list was, or null while one has not been.
     */
    public function lastSyncedAt(Game $game): ?DateTimeImmutable
    {
        $times = array_map(fn(string $type) => $this->refreshedAt($game, $type), self::LISTS);

        return in_array(null, $times, true) ? null : min($times);
    }

    private function refreshedAt(Game $game, string $type): ?DateTimeImmutable
    {
        $state = $this->cache->get(self::stateKey($game, $type));

        return is_array($state) && is_int($state['refreshed_at'] ?? null) ? Clock::fromTimestamp($state['refreshed_at']) : null;
    }

    private static function stateKey(Game $game, string $type): string
    {
        return "catalog-state:{$game->value}:{$type}";
    }

    /**
     * True when the catalog for a game is empty or older than the configured refresh interval.
     */
    public function isStale(Game $game): bool
    {
        if (!$this->hasCatalog($game)) {
            return true;
        }
        $last = $this->lastSyncedAt($game);
        $days = (int) $this->config->get('catalog.refresh_days', 3);

        return !$last || $last->getTimestamp() < Clock::timestamp() - $days * 86400;
    }

    /** Whether a catalog sync has run for this game at all (rows with in-game IDs exist). */
    public function hasCatalog(Game $game): bool
    {
        return (bool) $this->db->value('SELECT EXISTS (SELECT 1 FROM game_characters WHERE game = ? AND external_id IS NOT NULL)', [$game->value]);
    }

    // ── the lists ─────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string|int, array<string, mixed>>
     */
    private function download(Game $game, string $type): array
    {
        $api = $this->config->get("catalog.{$game->value}.api");
        $path = [
            Game::Genshin->value => [Catalog::CHARACTER => 'avatar', Catalog::WEAPON => 'weapon', Catalog::SET => 'reliquary'],
        ][$game->value][$type];

        $json = $this->fetcher->getJson("{$api}/{$path}", ['delay_ms' => 300]);
        $items = $json['data']['items'] ?? [];

        // Names can contain in-game markup ("<unbreak>" ...).
        foreach ($items as &$item) {
            if (isset($item['name'])) {
                $item['name'] = trim(html_entity_decode(strip_tags((string) $item['name']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return $items;
    }

    private function genshinCharacters(array $items): int
    {
        $assets = $this->config->get('catalog.genshin.assets');
        $game = Game::Genshin;

        $characters = 0;
        $travelers = [];
        foreach ($items as $id => $item) {
            $element = self::GENSHIN_ELEMENTS[$item['element'] ?? ''] ?? null;
            $row = [
                'name' => $item['name'],
                'element' => $element,
                'rarity' => $this->rarity($item['rank'] ?? null),
                'weapon_type' => self::GENSHIN_WEAPONS[$item['weaponType'] ?? ''] ?? null,
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/{$item['icon']}.png" : null,
                'released_at' => isset($item['release']) ? Clock::fromTimestamp((int) $item['release']) : null,
            ];

            if ($item['name'] === 'Traveler' && $element) {
                // Aether (10000005) and Lumine (10000007) share one entry per element.
                $key = 'traveler' . $element;
                $travelers[$key]['ids'][] = (string) $id;
                $travelers[$key]['row'] ??= ['name' => 'Traveler (' . ucfirst($element) . ')'] + $row;

                continue;
            }

            $this->upsert(Catalog::CHARACTER, $game, Key::from($item['name']), $row);
            $characters++;
        }

        foreach ($travelers as $key => $traveler) {
            $this->upsert(Catalog::CHARACTER, $game, $key, $traveler['row'] + ['meta' => ['ids' => $traveler['ids']]]);
            $characters++;
        }

        return $characters;
    }

    private function genshinWeapons(array $items): int
    {
        $assets = $this->config->get('catalog.genshin.assets');

        $weapons = 0;
        $seen = [];
        ksort($items);
        foreach ($items as $id => $item) {
            $key = Key::from($item['name']);
            // A few weapons exist twice (quest / trial copies); keep the lowest ID.
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->upsert(Catalog::WEAPON, Game::Genshin, $key, [
                'name' => $item['name'],
                'rarity' => $this->rarity($item['rank'] ?? null),
                'weapon_type' => self::GENSHIN_WEAPONS[$item['type'] ?? ''] ?? null,
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/{$item['icon']}.png" : null,
            ]);
            $weapons++;
        }

        return $weapons;
    }

    private function genshinSets(array $items): int
    {
        $assets = $this->config->get('catalog.genshin.assets');

        $sets = 0;
        foreach ($items as $id => $item) {
            $this->upsert(Catalog::SET, Game::Genshin, Key::from($item['name']), [
                'name' => $item['name'],
                'kind' => 'artifact',
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/reliquary/{$item['icon']}.png" : null,
                'meta' => ['rarities' => $item['levelList'] ?? [], 'bonuses' => array_values($item['affixList'] ?? [])],
            ]);
            $sets++;
        }

        return $sets;
    }

    private function rarity(mixed $rank): ?int
    {
        if (!is_numeric($rank)) {
            return null;
        }

        // Aloy is flagged as a special rarity (105) in game data.
        return (int) $rank > 5 ? 5 : (int) $rank;
    }

    /**
     * Match by in-game ID first so a renamed entry (new English name, fixed markup) keeps its row and
     * everything linked to it, unless another row already owns the new key.
     *
     * @param  array<string, mixed>  $row
     */
    private function upsert(string $type, Game $game, string $key, array $row): void
    {
        if ($key === '') {
            return;
        }

        $existing = null;
        if (!empty($row['external_id'])) {
            $existing = $this->rows->findByExternalId($game, $type, (string) $row['external_id']);
        }
        if ($existing && $existing->key !== $key && $this->rows->findByKey($game, $type, $key)) {
            $existing = null;
        }

        if ($existing) {
            $this->rows->update($type, $existing, ['key' => $key] + $row);

            return;
        }

        $byKey = $this->rows->findByKey($game, $type, $key);
        if ($byKey) {
            $this->rows->update($type, $byKey, $row);
        } else {
            $this->rows->insert($game, $type, $key, $row);
        }
    }
}
