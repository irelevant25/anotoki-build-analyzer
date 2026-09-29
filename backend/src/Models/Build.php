<?php

namespace App\Models;

use App\Db\Db;
use App\Support\Json;
use DateTimeImmutable;

/**
 * A recommended build as stored: (source, character, variant) with its normalized details.
 */
final class Build
{
    public function __construct(
        public int $id,
        public int $build_source_id,
        public int $game_character_id,
        public string $variant,
        public ?string $role = null,
        public bool $recommended = false,
        public int $position = 0,
        /** @var list<array<string, mixed>> */
        public array $weapons = [],
        /** @var list<array<string, mixed>> */
        public array $sets = [],
        /** @var array<string, list<array{stat: string, priority: int}>> */
        public array $main_stats = [],
        /** @var list<array{stat: string, priority: int}> */
        public array $substats = [],
        /** @var list<array{talent: string, priority: int}>|null */
        public ?array $talent_priority = null,
        /** @var list<array{stat: string, min: float|null, max: float|null, text: string}>|null */
        public ?array $stat_targets = null,
        public ?string $notes = null,
        public ?string $source_url = null,
        public ?DateTimeImmutable $source_updated_at = null,
        public ?string $game_version = null,
        public string $content_hash = '',
        public ?DateTimeImmutable $fetched_at = null,
        public ?DateTimeImmutable $changed_at = null,
        public ?BuildSource $source = null,
        public ?GameCharacter $character = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $build = new self(
            id: (int) $row['id'],
            build_source_id: (int) $row['build_source_id'],
            game_character_id: (int) $row['game_character_id'],
            variant: (string) $row['variant'],
            role: $row['role'] ?? null,
            recommended: (bool) $row['recommended'],
            position: (int) $row['position'],
            weapons: Json::decode($row['weapons'] ?? null) ?? [],
            sets: Json::decode($row['sets'] ?? null) ?? [],
            main_stats: Json::decode($row['main_stats'] ?? null) ?? [],
            substats: Json::decode($row['substats'] ?? null) ?? [],
            talent_priority: Json::decode($row['talent_priority'] ?? null),
            stat_targets: Json::decode($row['stat_targets'] ?? null),
            notes: $row['notes'] ?? null,
            source_url: $row['source_url'] ?? null,
            source_updated_at: Db::time($row['source_updated_at'] ?? null),
            game_version: $row['game_version'] ?? null,
            content_hash: (string) ($row['content_hash'] ?? ''),
            fetched_at: Db::time($row['fetched_at'] ?? null),
            changed_at: Db::time($row['changed_at'] ?? null),
        );

        // Joined rows (prefixed "src_" and "c_") come along when the query selected them.
        if (isset($row['src_id'])) {
            $build->source = BuildSource::fromRow(AccountCharacter::prefixed($row, 'src_'));
        }
        if (isset($row['c_id'])) {
            $build->character = GameCharacter::fromRow(AccountCharacter::prefixed($row, 'c_'));
        }

        return $build;
    }
}
