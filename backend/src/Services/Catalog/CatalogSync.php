<?php

namespace App\Services\Catalog;

use App\Config;
use App\Db\Db;
use App\Enums\Game;
use App\Http\FetchDeferred;
use App\Http\Fetcher;
use App\Models\SyncRun;
use App\Repositories\CatalogRows;
use App\Repositories\SyncRuns;
use App\Services\Sync\RunHandler;
use App\Services\Sync\RunLog;
use App\Support\Clock;
use App\Support\Key;
use App\Support\Log;
use App\Support\Text;
use DateTimeImmutable;
use Throwable;

/**
 * Downloads the character / weapon / set lists of the game data provider (config/app.php "catalog") and
 * upserts the catalog.
 *
 * Each list is one download and one transaction, so a catalog run (kind "catalog", one per game) takes a
 * list per unit of work, and an import can refresh just the list it needs.
 */
class CatalogSync implements RunHandler
{
    /** @var list<string> the lists of one game, in the order a run takes them */
    public const LISTS = [Catalog::CHARACTER, Catalog::WEAPON, Catalog::SET];

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

    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly Catalog $catalog,
        private readonly CatalogRows $rows,
        private readonly SyncRuns $runs,
        private readonly Db $db,
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
        $method = ($game === Game::Genshin ? 'genshin' : 'hsr').$method;

        // Downloaded before the transaction starts, so none is held open across a request.
        $items = $this->download($game, $type);
        $count = $this->db->transaction(fn () => $this->{$method}($items));
        $this->catalog->flush();

        return $count;
    }

    /**
     * When the catalog of a game was last refreshed: the last successful catalog run, or for catalogs
     * that predate catalog runs, the newest row written by a sync.
     */
    public function lastSyncedAt(Game $game): ?DateTimeImmutable
    {
        $times = array_filter([
            $this->runs->lastCatalogSuccess($game),
            Db::time($this->db->value('SELECT max(updated_at) FROM game_characters WHERE game = ? AND external_id IS NOT NULL', [$game->value])),
        ]);

        return $times === [] ? null : max($times);
    }

    /**
     * True when the catalog for a game is empty or older than the configured refresh interval.
     */
    public function isStale(Game $game): bool
    {
        if (! $this->hasCatalog($game)) {
            return true;
        }
        $last = $this->lastSyncedAt($game);
        $days = (int) $this->config->get('catalog.refresh_days', 3);

        return ! $last || $last->getTimestamp() < Clock::timestamp() - $days * 86400;
    }

    /** Whether a catalog sync has run for this game at all (rows with in-game IDs exist). */
    public function hasCatalog(Game $game): bool
    {
        return (bool) $this->db->value('SELECT EXISTS (SELECT 1 FROM game_characters WHERE game = ? AND external_id IS NOT NULL)', [$game->value]);
    }

    // ── catalog runs ──────────────────────────────────────────────────────────────────────────────

    public function begin(SyncRun $run): void
    {
        $run->state = ['lists' => self::LISTS, 'done' => 0, 'total' => count(self::LISTS)];
        $run->stats = ['characters' => 0, 'weapons' => 0, 'sets' => 0, 'errors' => 0];
    }

    public function step(SyncRun $run, float $deadline, RunLog $log): void
    {
        $game = $run->game;
        $state = $run->state;
        $stats = $run->stats;

        while ($state['done'] < $state['total']) {
            // A list takes a second or two; leave room for one before the step's deadline.
            if (Clock::microtime() > $deadline - 5) {
                return;
            }

            $type = $state['lists'][$state['done']];
            try {
                $stats[$type] = $this->syncList($game, $type);
                $log->add("{$game->label()}: {$stats[$type]} {$type}.");
            } catch (FetchDeferred $e) {
                throw $e;
            } catch (Throwable $e) {
                $stats['errors']++;
                $log->add("{$game->label()} {$type}: ERROR ".Text::limit($e->getMessage(), 300));
                Log::exception($e, "Catalog sync of {$game->value} {$type}");
            }

            $state['done']++;
            $run->state = $state;
            $run->stats = $stats;
            $this->runs->save($run);
        }

        $status = match (true) {
            $stats['errors'] === 0 => 'success',
            $stats['errors'] < $state['total'] => 'partial',
            default => 'failed',
        };
        $log->add("Done: {$status}.");
        $run->status = $status;
        $run->finished_at = Clock::now();
        $run->state = null;
        $this->runs->save($run);
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
            Game::Hsr->value => [Catalog::CHARACTER => 'avatar', Catalog::WEAPON => 'equipment', Catalog::SET => 'relic'],
        ][$game->value][$type];

        $json = $this->fetcher->getJson("{$api}/{$path}", ['delay_ms' => 300]);
        $items = $json['data']['items'] ?? [];

        // Names can contain in-game markup such as "Silver Wolf LV.<unbreak>999</unbreak>".
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
                $key = 'traveler'.$element;
                $travelers[$key]['ids'][] = (string) $id;
                $travelers[$key]['row'] ??= ['name' => 'Traveler ('.ucfirst($element).')'] + $row;

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

    private function hsrCharacters(array $items): int
    {
        $assets = $this->config->get('catalog.hsr.assets');
        $game = Game::Hsr;
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
                'released_at' => isset($item['release']) ? Clock::fromTimestamp((int) $item['release']) : null,
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

            $this->upsert(Catalog::CHARACTER, $game, $key, $row);
            $characters++;
        }

        foreach ($trailblazers as $key => $trailblazer) {
            $this->upsert(Catalog::CHARACTER, $game, $key, $trailblazer['row'] + ['meta' => ['ids' => $trailblazer['ids']]]);
            $characters++;
        }

        return $characters;
    }

    private function hsrWeapons(array $items): int
    {
        $assets = $this->config->get('catalog.hsr.assets');

        $weapons = 0;
        foreach ($items as $id => $item) {
            $this->upsert(Catalog::WEAPON, Game::Hsr, Key::from($item['name']), [
                'name' => $item['name'],
                'rarity' => $this->rarity($item['rank'] ?? null),
                'weapon_type' => self::HSR_PATHS[$item['types']['pathType'] ?? ''] ?? null,
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/equipment/medium/{$item['icon']}.png" : null,
            ]);
            $weapons++;
        }

        return $weapons;
    }

    private function hsrSets(array $items): int
    {
        $assets = $this->config->get('catalog.hsr.assets');

        $sets = 0;
        foreach ($items as $id => $item) {
            $this->upsert(Catalog::SET, Game::Hsr, Key::from($item['name']), [
                'name' => $item['name'],
                'kind' => ($item['isPlanarSuit'] ?? false) ? 'planar' : 'relic',
                'external_id' => (string) $id,
                'icon_url' => isset($item['icon']) ? "{$assets}/relic/{$item['icon']}.png" : null,
            ]);
            $sets++;
        }

        return $sets;
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
        if (! empty($row['external_id'])) {
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
