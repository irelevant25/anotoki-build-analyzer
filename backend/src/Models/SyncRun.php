<?php

namespace App\Models;

use App\Db\Db;
use App\Enums\Game;
use App\Support\Json;
use DateTimeImmutable;

/**
 * One build sync of a source (kind "builds") or one catalog refresh of a game (kind "catalog").
 *
 * A run is "running" from its start until its last step; nothing drives it but step requests (the
 * Sources page, or the command line), so a run nobody steps simply waits, and "state" keeps its place.
 * Finished runs are "success", "partial", "failed" or "cancelled". The step handlers change the public
 * fields and SyncRuns::save() writes them.
 */
final class SyncRun
{
    public const BUILDS = 'builds';

    public const CATALOG = 'catalog';

    public const RUNNING = 'running';

    public function __construct(
        public ?int $id,
        public string $kind,
        public ?Game $game,
        public ?int $build_source_id,
        public string $status,
        public DateTimeImmutable $started_at,
        public ?DateTimeImmutable $finished_at = null,
        /** @var array<string, mixed>|null */
        public ?array $stats = null,
        /** @var array<string, mixed>|null */
        public ?array $state = null,
        public ?string $log = null,
        public ?DateTimeImmutable $next_step_at = null,
        public ?DateTimeImmutable $last_step_at = null,
        public ?int $started_by = null,
        public ?BuildSource $source = null,
        public ?User $starter = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === self::RUNNING;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $run = new self(
            id: (int) $row['id'],
            kind: (string) $row['kind'],
            game: isset($row['game']) ? Game::from((string) $row['game']) : null,
            build_source_id: isset($row['build_source_id']) ? (int) $row['build_source_id'] : null,
            status: (string) $row['status'],
            started_at: Db::time($row['started_at']) ?? new DateTimeImmutable('@0'),
            finished_at: Db::time($row['finished_at'] ?? null),
            stats: Json::decode($row['stats'] ?? null),
            state: Json::decode($row['state'] ?? null),
            log: $row['log'] ?? null,
            next_step_at: Db::time($row['next_step_at'] ?? null),
            last_step_at: Db::time($row['last_step_at'] ?? null),
            started_by: isset($row['started_by']) ? (int) $row['started_by'] : null,
        );
        if (isset($row['src_id'])) {
            $run->source = BuildSource::fromRow(AccountCharacter::prefixed($row, 'src_'));
        }
        if (isset($row['u_id'])) {
            $run->starter = User::fromRow(AccountCharacter::prefixed($row, 'u_'));
        }

        return $run;
    }
}
