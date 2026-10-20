<?php

namespace App\Models;

use App\Db\Db;
use App\Enums\Game;
use App\Support\Json;
use DateTimeImmutable;

/**
 * A row of the game catalog: a character, a weapon, or an artifact set. The three
 * tables share their columns, apart from the character's element and release and the set's kind.
 */
abstract class CatalogEntry
{
    public function __construct(
        public int $id,
        public Game $game,
        public string $key,
        public string $name,
        public ?string $external_id = null,
        public ?string $icon_url = null,
        /** @var array<string, mixed> */
        public array $meta = [],
        public ?DateTimeImmutable $updated_at = null,
    ) {}

    /** The table the entry lives in. */
    abstract public static function table(): string;

    /** @param array<string, mixed> $row */
    abstract public static function fromRow(array $row): static;

    /**
     * Every in-game ID the entry answers to (the Travelers have several).
     *
     * @return list<string>
     */
    public function externalIds(): array
    {
        return array_values(array_filter(array_map('strval', [$this->external_id, ...$this->meta['ids'] ?? []]), fn(string $id) => $id !== ''));
    }

    /** @param array<string, mixed> $row */
    protected static function common(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'game' => Game::from((string) $row['game']),
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'external_id' => isset($row['external_id']) ? (string) $row['external_id'] : null,
            'icon_url' => $row['icon_url'] ?? null,
            'meta' => Json::decode($row['meta'] ?? null) ?? [],
            'updated_at' => Db::time($row['updated_at'] ?? null),
        ];
    }
}
