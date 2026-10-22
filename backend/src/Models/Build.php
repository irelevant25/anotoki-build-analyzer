<?php

namespace App\Models;

use App\Db\Db;
use App\Support\Json;
use DateTimeImmutable;

/**
 * A recommended build as stored: a variation of a character's - its role, a qualifier telling two builds of one
 * role apart, the constellation it needs (min_rank) - with its normalized details (docs/builds.md). Its position
 * is its place among the character's builds: the first is the main one.
 */
final class Build
{
    public function __construct(
        public int $id,
        public int $game_character_id,
        public string $role,
        public string $qualifier = '',
        public int $min_rank = 0,
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
        /** @var list<array{stat: string, min: int|float|null, max: int|float|null, text: string, from_rank: int}>|null */
        public ?array $stat_targets = null,
        /**
         * The teams the build is played in, best first (BuildFile, format version 3); [] for none.
         *
         * @var list<array{name: string, members: list<array{key: string, name: string, role: string|null, min_rank: int, alternatives: list<array{key: string, name: string}>}>, note: string|null}>
         */
        public array $teams = [],
        public ?string $notes = null,
        public ?string $game_version = null,
        public string $content_hash = '',
        public ?DateTimeImmutable $checked_at = null,
        public ?DateTimeImmutable $changed_at = null,
        public ?GameCharacter $character = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $build = new self(
            id: (int) $row['id'],
            game_character_id: (int) $row['game_character_id'],
            role: (string) $row['role'],
            qualifier: (string) ($row['qualifier'] ?? ''),
            min_rank: (int) ($row['min_rank'] ?? 0),
            position: (int) $row['position'],
            weapons: Json::decode($row['weapons'] ?? null) ?? [],
            sets: Json::decode($row['sets'] ?? null) ?? [],
            main_stats: Json::decode($row['main_stats'] ?? null) ?? [],
            substats: Json::decode($row['substats'] ?? null) ?? [],
            talent_priority: Json::decode($row['talent_priority'] ?? null),
            stat_targets: Json::decode($row['stat_targets'] ?? null),
            // NULL for none (migration 020), never '[]'; a build stored before 0.6 has none.
            teams: Json::decode($row['teams'] ?? null) ?? [],
            notes: $row['notes'] ?? null,
            game_version: $row['game_version'] ?? null,
            content_hash: (string) ($row['content_hash'] ?? ''),
            checked_at: Db::time($row['checked_at'] ?? null),
            changed_at: Db::time($row['changed_at'] ?? null),
        );

        // The joined character (prefixed "c_") comes along when the query selected it.
        if (isset($row['c_id'])) {
            $build->character = GameCharacter::fromRow(AccountCharacter::prefixed($row, 'c_'));
        }

        return $build;
    }

    /** Whether a character of this rank (constellation) has what the build needs. */
    public function appliesTo(int $rank): bool
    {
        return $rank >= $this->min_rank;
    }
}
