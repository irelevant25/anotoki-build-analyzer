<?php

namespace App\Services\Catalog;

use App\Cache\Cache;
use App\Config;
use App\Db\Db;
use App\Enums\Game;
use App\Repositories\CatalogRows;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The game catalog kept up to date from each game's provider (CatalogProviders; config/app.php "catalog"), one list
 * at a time: the admin panel's Builds page refreshes the three one after another, the upkeep after an answer one
 * stale list (CatalogUpkeep), an import the list that names what the catalog lacks.
 *
 * A refresh is a conditional request - the version tag the last read kept - answered "unchanged" while the list is
 * as it was; a changed list is written in one transaction, each row found again by its in-game ID, then by its key
 * (a renamed entry keeps its row and everything linked to it, unless another row holds the new key already); no row
 * is ever deleted. Where each list stands - its version tag, when the provider last confirmed it, when it last
 * changed, the last failure and why - is kept in the cache table (catalog-state:{game}:{list}), which a restore
 * leaves as it is; and one refresh of a list at a time (a claim of two minutes: busy).
 */
class CatalogSync
{
    /** @var list<string> the lists of one game, in the order the Builds page refreshes them */
    public const LISTS = [Catalog::CHARACTER, Catalog::WEAPON, Catalog::SET];

    /** The outcome of a refresh that found another one of the same list going. */
    public const BUSY = 'busy';

    /** How long a list's state is kept: for good, but for the cache table's rule that everything expires. */
    private const STATE_SECONDS = 10 * 365 * 86400;

    /** How long a refresh holds its list's claim at most - a request that dies frees it so late. */
    private const CLAIM_SECONDS = 120;

    public function __construct(
        private readonly CatalogProviders $providers,
        private readonly Catalog $catalog,
        private readonly CatalogRows $rows,
        private readonly Db $db,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    /**
     * One list refreshed from the game's provider: a conditional request, unless $force; written when it changed.
     *
     * @return array{outcome: string, written: int, problem: string|null} outcome: a CatalogAnswer outcome, or BUSY
     */
    public function refresh(Game $game, string $list, bool $force = false): array
    {
        if (!in_array($list, self::LISTS, true)) {
            throw new \InvalidArgumentException("Unknown catalog list [{$list}]");
        }
        $claim = "catalog-refresh:{$game->value}:{$list}";
        if (!$this->cache->add($claim, Clock::timestamp(), self::CLAIM_SECONDS)) {
            return ['outcome' => self::BUSY, 'written' => 0, 'problem' => 'This list is being refreshed. Try again in a moment.'];
        }

        try {
            $state = $this->state($game, $list);
            // Asked before any transaction starts, so none is held open across a request.
            $answer = $this->providers->for($game)->fetch($game, $list, $force ? null : $state['etag']);
            $now = Clock::timestamp();
            $written = 0;
            if ($answer->outcome === CatalogAnswer::CHANGED) {
                $written = $this->db->transaction(fn() => $this->write($game, $list, $answer->rows));
                $this->catalog->flush();
                $state = ['etag' => $answer->etag, 'checked_at' => $now, 'changed_at' => $now, 'failed_at' => null, 'problem' => null, 'count' => $written] + $state;
            } elseif ($answer->outcome === CatalogAnswer::UNCHANGED) {
                $state = ['checked_at' => $now, 'failed_at' => null, 'problem' => null] + $state;
            } else {
                $state = ['failed_at' => $now, 'problem' => $answer->problem] + $state;
            }
            $this->cache->put(self::stateKey($game, $list), $state, self::STATE_SECONDS);

            return ['outcome' => $answer->outcome, 'written' => $written, 'problem' => $answer->problem];
        } finally {
            $this->cache->forget($claim);
        }
    }

    /**
     * Each list of a game as the Builds page shows it: how many entries the catalog has, when the provider last
     * confirmed the list (null: not since this was kept), and why the last refresh failed, when it did.
     *
     * @return list<array{list: string, entries: int, refreshed_at: string|null, problem: string|null}>
     */
    public function lists(Game $game): array
    {
        return array_map(function (string $list) use ($game) {
            $state = $this->state($game, $list);

            return [
                'list' => $list,
                'entries' => (int) $this->db->value('SELECT count(*) FROM ' . CatalogRows::table($list) . ' WHERE game = ?', [$game->value]),
                'refreshed_at' => Clock::iso($state['checked_at'] === null ? null : Clock::fromTimestamp($state['checked_at'])),
                'problem' => $state['problem'],
            ];
        }, self::LISTS);
    }

    /**
     * When the catalog of a game was last refreshed: when its stalest list was, or null while one has not been.
     */
    public function lastSyncedAt(Game $game): ?DateTimeImmutable
    {
        $times = array_map(fn(string $list) => $this->state($game, $list)['checked_at'], self::LISTS);

        return in_array(null, $times, true) ? null : Clock::fromTimestamp(min($times));
    }

    /**
     * True when the catalog for a game is empty, or a list of it has not been confirmed within refresh_days.
     */
    public function isStale(Game $game): bool
    {
        return !$this->hasCatalog($game) || $this->staleLists($game) !== [];
    }

    /**
     * The lists of a game not confirmed within refresh_days, in LISTS' order. With $due, only those the upkeep may
     * try now: a list whose refresh failed waits retry_minutes before the next try.
     *
     * @return list<string>
     */
    public function staleLists(Game $game, bool $due = false): array
    {
        $now = Clock::timestamp();
        $days = max(1, (int) $this->config->get('catalog.refresh_days', 3));
        $retry = max(1, (int) $this->config->get('catalog.retry_minutes', 60));

        return array_values(
            array_filter(self::LISTS, function (string $list) use ($game, $now, $days, $retry, $due) {
                $state = $this->state($game, $list);
                $stale = $state['checked_at'] === null || $state['checked_at'] < $now - $days * 86400;

                return $stale && (!$due || $state['failed_at'] === null || $state['failed_at'] < $now - $retry * 60);
            }),
        );
    }

    /** Whether the game has a catalog at all (rows with in-game IDs exist). */
    public function hasCatalog(Game $game): bool
    {
        return (bool) $this->db->value('SELECT EXISTS (SELECT 1 FROM game_characters WHERE game = ? AND external_id IS NOT NULL)', [$game->value]);
    }

    /**
     * Where a list stands, as the cache keeps it; what is not kept is null.
     *
     * @return array{etag: string|null, checked_at: int|null, changed_at: int|null, failed_at: int|null, problem: string|null, count: int|null}
     */
    private function state(Game $game, string $list): array
    {
        $kept = $this->cache->get(self::stateKey($game, $list));
        $kept = is_array($kept) ? $kept : [];
        $int = fn(string $name): ?int => is_int($kept[$name] ?? null) ? $kept[$name] : null;
        $text = fn(string $name): ?string => is_string($kept[$name] ?? null) ? $kept[$name] : null;

        return [
            'etag' => $text('etag'),
            'checked_at' => $int('checked_at'),
            'changed_at' => $int('changed_at'),
            'failed_at' => $int('failed_at'),
            'problem' => $text('problem'),
            'count' => $int('count'),
        ];
    }

    private static function stateKey(Game $game, string $list): string
    {
        return "catalog-state:{$game->value}:{$list}";
    }

    /**
     * The provider's rows into the list, in one transaction: each found again by its in-game ID, then by its key;
     * nothing deleted.
     *
     * @param  list<array{key: string, row: array<string, mixed>}>  $rows
     * @return int rows written (found again or added)
     */
    private function write(Game $game, string $list, array $rows): int
    {
        $byExternal = [];
        $byKey = [];
        foreach ($this->rows->all($game, $list) as $entry) {
            if ($entry->external_id !== null) {
                $byExternal[$entry->external_id] = $entry;
            }
            $byKey[$entry->key] = $entry;
        }

        $written = 0;
        $seen = [];
        foreach ($rows as ['key' => $key, 'row' => $row]) {
            // A key once: a second row of it would be a second entry of one name.
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $found = isset($row['external_id']) ? $byExternal[$row['external_id']] ?? null : null;
            // Renamed: its row keeps it - unless another row holds the new key already.
            if ($found !== null && $found->key !== $key && isset($byKey[$key]) && $byKey[$key]->id !== $found->id) {
                $found = null;
            }
            $found ??= $byKey[$key] ?? null;
            if ($found !== null) {
                $this->rows->update($list, $found, ['key' => $key] + $row);
            } else {
                $this->rows->insert($game, $list, $key, $row);
            }
            $written++;
        }

        return $written;
    }
}
