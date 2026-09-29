<?php

namespace App\Models;

use App\Db\Db;
use App\Enums\Game;
use DateTimeImmutable;

/**
 * The database row of a build source (one scraper). `key` is internal ("prydwen.hsr"); what the API and the
 * UI call a source comes from BuildSourceRegistry (label(), publicKey()).
 */
final class BuildSource
{
    public function __construct(
        public int $id,
        public string $key,
        public Game $game,
        public string $name,
        public string $base_url,
        public bool $enabled = true,
        public ?DateTimeImmutable $last_synced_at = null,
        public ?string $last_status = null,
        public ?string $last_error = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            key: (string) $row['key'],
            game: Game::from((string) $row['game']),
            name: (string) $row['name'],
            base_url: (string) $row['base_url'],
            enabled: (bool) $row['enabled'],
            last_synced_at: Db::time($row['last_synced_at'] ?? null),
            last_status: $row['last_status'] ?? null,
            last_error: $row['last_error'] ?? null,
        );
    }
}
