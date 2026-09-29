<?php

namespace App\Repositories;

use App\Db\Db;
use App\Enums\Game;
use App\Models\Build;

/**
 * Stored builds, with their source and character.
 */
final class Builds
{
    private const SELECT = 'SELECT b.*,
            s.id AS src_id, s.key AS src_key, s.game AS src_game, s.name AS src_name, s.base_url AS src_base_url,
            s.enabled AS src_enabled, s.last_synced_at AS src_last_synced_at, s.last_status AS src_last_status,
            s.last_error AS src_last_error,
            c.id AS c_id, c.game AS c_game, c.key AS c_key, c.name AS c_name, c.element AS c_element,
            c.rarity AS c_rarity, c.weapon_type AS c_weapon_type, c.external_id AS c_external_id,
            c.icon_url AS c_icon_url, c.released_at AS c_released_at, c.meta AS c_meta, c.updated_at AS c_updated_at
        FROM builds b
        JOIN build_sources s ON s.id = b.build_source_id
        JOIN game_characters c ON c.id = b.game_character_id';

    public function __construct(private readonly Db $db) {}

    /**
     * The builds of switched-on sources for these characters, grouped by character, in source and page order.
     *
     * @param  list<int>  $characterIds
     * @return array<int, list<Build>> game character id => builds
     */
    public function forCharacters(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }
        [$in, $params] = Db::in(array_values(array_unique($characterIds)));
        $grouped = [];
        foreach ($this->db->all(self::SELECT." WHERE b.game_character_id IN ({$in}) AND s.enabled ORDER BY b.build_source_id, b.position, b.id", $params) as $row) {
            $build = Build::fromRow($row);
            $grouped[$build->game_character_id][] = $build;
        }

        return $grouped;
    }

    /**
     * These builds, in the order of $ids.
     *
     * @param  list<int>  $ids
     * @return list<Build>
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = Db::in($ids);
        $byId = [];
        foreach ($this->db->all(self::SELECT." WHERE b.id IN ({$in})", $params) as $row) {
            $byId[(int) $row['id']] = Build::fromRow($row);
        }

        return array_values(array_filter(array_map(fn (int $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * Characters of a game that have builds, with a line per build (source and times): the builds browser.
     *
     * @return list<array<string, mixed>>
     */
    public function summaries(Game $game): array
    {
        return $this->db->all(
            'SELECT b.id, b.game_character_id, b.changed_at, b.source_updated_at, s.key AS source_key
               FROM builds b JOIN build_sources s ON s.id = b.build_source_id
               JOIN game_characters c ON c.id = b.game_character_id
              WHERE c.game = ?
              ORDER BY b.build_source_id, b.position',
            [$game->value],
        );
    }
}
