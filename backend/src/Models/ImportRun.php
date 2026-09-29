<?php

namespace App\Models;

use App\Db\Db;
use App\Enums\Game;
use App\Support\Json;
use DateTimeImmutable;

/**
 * One import attempt by a user; a failed attempt may have no account.
 */
final class ImportRun
{
    public function __construct(
        public int $id,
        public int $user_id,
        public ?int $account_id,
        public Game $game,
        /** enka | good | hsr_scanner | file */
        public string $source,
        /** success | partial | failed */
        public string $status,
        public ?string $file_name = null,
        /** @var array<string, mixed>|null */
        public ?array $summary = null,
        public ?string $error = null,
        public ?DateTimeImmutable $created_at = null,
        public ?Account $account = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            user_id: (int) $row['user_id'],
            account_id: isset($row['account_id']) ? (int) $row['account_id'] : null,
            game: Game::from((string) $row['game']),
            source: (string) $row['source'],
            status: (string) $row['status'],
            file_name: $row['file_name'] ?? null,
            summary: Json::decode($row['summary'] ?? null),
            error: $row['error'] ?? null,
            created_at: Db::time($row['created_at'] ?? null),
        );
    }
}
