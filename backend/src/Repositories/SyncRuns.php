<?php

namespace App\Repositories;

use App\Db\Db;
use App\Enums\Game;
use App\Models\SyncRun;
use App\Support\Clock;
use App\Support\Json;

/**
 * Sync runs, with their source and the person who started them.
 */
final class SyncRuns
{
    private const SELECT = 'SELECT r.*,
            s.id AS src_id, s.key AS src_key, s.game AS src_game, s.name AS src_name, s.base_url AS src_base_url,
            s.enabled AS src_enabled, s.last_synced_at AS src_last_synced_at, s.last_status AS src_last_status,
            s.last_error AS src_last_error,
            u.id AS u_id, u.name AS u_name
        FROM sync_runs r
        LEFT JOIN build_sources s ON s.id = r.build_source_id
        LEFT JOIN users u ON u.id = r.started_by';

    public function __construct(private readonly Db $db) {}

    public function find(int $id): ?SyncRun
    {
        $row = $this->db->one(self::SELECT.' WHERE r.id = ?', [$id]);

        return $row === null ? null : SyncRun::fromRow($row);
    }

    /**
     * The runs still going, oldest first.
     *
     * @return list<SyncRun>
     */
    public function active(): array
    {
        return $this->many(self::SELECT." WHERE r.status = 'running' ORDER BY r.id");
    }

    public function activeRun(string $kind, Game $game, ?int $sourceId = null): ?SyncRun
    {
        $sql = self::SELECT." WHERE r.status = 'running' AND r.kind = ? AND r.game = ?";
        $params = [$kind, $game->value];
        if ($sourceId !== null) {
            $sql .= ' AND r.build_source_id = ?';
            $params[] = $sourceId;
        }

        $row = $this->db->one($sql.' ORDER BY r.id LIMIT 1', $params);

        return $row === null ? null : SyncRun::fromRow($row);
    }

    /** @param list<int> $ids */
    public function anyActive(array $ids): bool
    {
        [$in, $params] = Db::in($ids);

        return (bool) $this->db->value("SELECT EXISTS (SELECT 1 FROM sync_runs WHERE status = 'running' AND id IN ({$in}))", $params);
    }

    /** @param list<int> $ids */
    public function anyFailed(array $ids): bool
    {
        [$in, $params] = Db::in($ids);

        return (bool) $this->db->value("SELECT EXISTS (SELECT 1 FROM sync_runs WHERE status = 'failed' AND id IN ({$in}))", $params);
    }

    /**
     * Newest first; narrowed to one source (by its internal key) or one kind.
     *
     * @return list<SyncRun>
     */
    public function recent(?string $sourceKey, ?string $kind, int $limit): array
    {
        $conditions = [];
        $params = [];
        if ($sourceKey !== null) {
            $conditions[] = 's.key = ?';
            $params[] = $sourceKey;
        }
        if ($kind !== null) {
            $conditions[] = 'r.kind = ?';
            $params[] = $kind;
        }

        return $this->many(self::SELECT.($conditions ? ' WHERE '.implode(' AND ', $conditions) : '')
            .' ORDER BY r.started_at DESC, r.id DESC LIMIT '.max(1, min(100, $limit)), $params);
    }

    public function latestFor(int $sourceId): ?SyncRun
    {
        $row = $this->db->one(self::SELECT.' WHERE r.build_source_id = ? ORDER BY r.started_at DESC, r.id DESC LIMIT 1', [$sourceId]);

        return $row === null ? null : SyncRun::fromRow($row);
    }

    /**
     * The newest finish of a successful catalog run of a game.
     */
    public function lastCatalogSuccess(Game $game): ?\DateTimeImmutable
    {
        return Db::time($this->db->value("SELECT max(finished_at) FROM sync_runs WHERE kind = 'catalog' AND game = ? AND status = 'success'", [$game->value]));
    }

    public function insert(SyncRun $run): void
    {
        $now = Clock::now();
        $run->id = (int) $this->db->insert('sync_runs', [
            'kind' => $run->kind,
            'game' => $run->game?->value,
            'build_source_id' => $run->build_source_id,
            'status' => $run->status,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
            'started_by' => $run->started_by,
            'stats' => Json::column($run->stats),
            'state' => Json::column($run->state),
            'log' => $run->log,
            'next_step_at' => $run->next_step_at,
            'last_step_at' => $run->last_step_at,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Writes what a step (or a cancel) changes. */
    public function save(SyncRun $run): void
    {
        $this->db->update('sync_runs', [
            'status' => $run->status,
            'finished_at' => $run->finished_at,
            'stats' => Json::column($run->stats),
            'state' => Json::column($run->state),
            'log' => $run->log,
            'next_step_at' => $run->next_step_at,
            'last_step_at' => $run->last_step_at,
            'updated_at' => Clock::now(),
        ], ['id' => $run->id]);
    }

    /** @return list<SyncRun> */
    private function many(string $sql, array $params = []): array
    {
        return array_map(fn (array $row) => SyncRun::fromRow($row), $this->db->all($sql, $params));
    }
}
