<?php

namespace App\Repositories;

use App\Db\Db;
use App\Enums\Game;
use App\Models\CatalogEntry;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Support\Clock;
use App\Support\Json;
use InvalidArgumentException;

/**
 * The three catalog tables, by list: "characters", "weapons", "sets".
 */
final class CatalogRows
{
    /** @var array<string, class-string<CatalogEntry>> */
    public const CLASSES = [
        'characters' => GameCharacter::class,
        'weapons' => GameWeapon::class,
        'sets' => GameSet::class,
    ];

    /** The columns a row may be written with, per list (anything else is refused). */
    private const COLUMNS = [
        'characters' => ['name', 'element', 'rarity', 'weapon_type', 'external_id', 'icon_url', 'released_at', 'meta'],
        'weapons' => ['name', 'rarity', 'weapon_type', 'external_id', 'icon_url', 'meta'],
        'sets' => ['name', 'kind', 'external_id', 'icon_url', 'meta'],
    ];

    public function __construct(private readonly Db $db) {}

    public static function table(string $type): string
    {
        return (self::CLASSES[$type] ?? throw new InvalidArgumentException("Unknown catalog list [{$type}]"))::table();
    }

    /**
     * @return list<CatalogEntry>
     */
    public function all(Game $game, string $type): array
    {
        $class = self::CLASSES[$type];

        return array_map(fn(array $row) => $class::fromRow($row), $this->db->all('SELECT * FROM ' . self::table($type) . ' WHERE game = ? ORDER BY id', [$game->value]));
    }

    public function find(string $type, int $id): ?CatalogEntry
    {
        $row = $this->db->one('SELECT * FROM ' . self::table($type) . ' WHERE id = ?', [$id]);

        return $row === null ? null : self::CLASSES[$type]::fromRow($row);
    }

    /**
     * @param  list<int>  $ids
     * @return list<CatalogEntry>
     */
    public function byIds(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = Db::in($ids);

        return array_map(fn(array $row) => self::CLASSES[$type]::fromRow($row), $this->db->all('SELECT * FROM ' . self::table($type) . " WHERE id IN ({$in})", $params));
    }

    public function findByKey(Game $game, string $type, string $key): ?CatalogEntry
    {
        $row = $this->db->one('SELECT * FROM ' . self::table($type) . ' WHERE game = ? AND key = ?', [$game->value, $key]);

        return $row === null ? null : self::CLASSES[$type]::fromRow($row);
    }

    public function findByExternalId(Game $game, string $type, string $externalId): ?CatalogEntry
    {
        $row = $this->db->one('SELECT * FROM ' . self::table($type) . ' WHERE game = ? AND external_id = ? ORDER BY id LIMIT 1', [$game->value, $externalId]);

        return $row === null ? null : self::CLASSES[$type]::fromRow($row);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, CatalogEntry> key => entry
     */
    public function byKeys(Game $game, string $type, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        [$in, $params] = Db::in(array_values(array_unique($keys)));
        $result = [];
        foreach ($this->db->all('SELECT * FROM ' . self::table($type) . " WHERE game = ? AND key IN ({$in})", [$game->value, ...$params]) as $row) {
            $entry = self::CLASSES[$type]::fromRow($row);
            $result[$entry->key] = $entry;
        }

        return $result;
    }

    /**
     * The row with this key, made from $attributes when there is none. Two syncs racing for the same new
     * name cannot make it twice: the unique (game, key) turns the second insert into a no-op.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function firstOrCreate(Game $game, string $type, string $key, array $attributes): CatalogEntry
    {
        $existing = $this->findByKey($game, $type, $key);
        if ($existing) {
            return $existing;
        }

        $now = Clock::now();
        $row = ['game' => $game->value, 'key' => $key] + $this->columns($type, $attributes) + ['created_at' => $now, 'updated_at' => $now];
        $columns = array_keys($row);
        $this->db->run(
            'INSERT INTO ' .
                self::table($type) .
                ' ("' .
                implode('", "', $columns) .
                '") VALUES (' .
                implode(', ', array_fill(0, count($columns), '?')) .
                ')
            ON CONFLICT (game, key) DO NOTHING',
            array_values($row),
        );

        return $this->findByKey($game, $type, $key) ?? throw new \RuntimeException("Could not store {$type} {$key}");
    }

    /**
     * Writes these columns of a row; updated_at moves only when something changed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $type, CatalogEntry $entry, array $attributes): void
    {
        $current = $this->db->one('SELECT * FROM ' . self::table($type) . ' WHERE id = ?', [$entry->id]) ?? [];
        $changes = [];
        foreach ($this->columns($type, $attributes) + (array_key_exists('key', $attributes) ? ['key' => $attributes['key']] : []) as $column => $value) {
            if (!self::same($current[$column] ?? null, $value, $column)) {
                $changes[$column] = $value;
            }
        }
        if ($changes !== []) {
            $this->db->update(self::table($type), $changes + ['updated_at' => Clock::now()], ['id' => $entry->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insert(Game $game, string $type, string $key, array $attributes): void
    {
        $now = Clock::now();
        $this->db->insert(self::table($type), ['game' => $game->value, 'key' => $key] + $this->columns($type, $attributes) + ['created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Only this list's columns, with meta encoded.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function columns(string $type, array $attributes): array
    {
        $row = array_intersect_key($attributes, array_flip(self::COLUMNS[$type]));
        if (array_key_exists('meta', $row)) {
            $row['meta'] = Json::column($row['meta']);
        }

        return $row;
    }

    /** A stored value and a new one mean the same (times compared as instants, JSON as data). */
    private static function same(mixed $stored, mixed $new, string $column): bool
    {
        if ($column === 'meta') {
            return Json::decode($stored) == Json::decode($new);
        }
        if ($new instanceof \DateTimeInterface) {
            return is_string($stored) && Db::time($stored)?->getTimestamp() === $new->getTimestamp();
        }
        if ($stored === null || $new === null) {
            return $stored === $new;
        }

        return (string) $stored === (string) $new;
    }
}
