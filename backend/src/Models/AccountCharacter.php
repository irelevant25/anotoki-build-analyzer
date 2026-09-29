<?php

namespace App\Models;

use App\Db\Db;
use App\Support\Json;
use DateTimeImmutable;

/**
 * A character of a game account, with its catalog entry.
 */
final class AccountCharacter
{
    public function __construct(
        public int $id,
        public int $account_id,
        public int $game_character_id,
        public GameCharacter $character,
        public int $level = 1,
        public ?int $ascension = null,
        /** Constellation / Eidolon. */
        public int $rank = 0,
        /** @var array<string, mixed>|null */
        public ?array $talents = null,
        /** @var array<string, float|int>|null in-game totals, from showcase imports only */
        public ?array $final_stats = null,
        public string $source = 'enka',
        public ?DateTimeImmutable $synced_at = null,
    ) {}

    /**
     * A row of account_characters joined with its game_characters row (columns prefixed "c_").
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            account_id: (int) $row['account_id'],
            game_character_id: (int) $row['game_character_id'],
            character: GameCharacter::fromRow(self::prefixed($row, 'c_')),
            level: (int) $row['level'],
            ascension: isset($row['ascension']) ? (int) $row['ascension'] : null,
            rank: (int) $row['rank'],
            talents: Json::decode($row['talents'] ?? null),
            final_stats: Json::decode($row['final_stats'] ?? null),
            source: (string) $row['source'],
            synced_at: Db::time($row['synced_at'] ?? null),
        );
    }

    /**
     * The columns of a joined table, without their prefix.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function prefixed(array $row, string $prefix): array
    {
        $result = [];
        foreach ($row as $column => $value) {
            if (str_starts_with($column, $prefix)) {
                $result[substr($column, strlen($prefix))] = $value;
            }
        }

        return $result;
    }
}
