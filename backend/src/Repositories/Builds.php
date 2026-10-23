<?php

namespace App\Repositories;

use App\Db\Db;
use App\Enums\Game;
use App\Models\Build;

/**
 * Stored builds, with their character. A character's builds come in their order (position): the first is the
 * main one.
 */
final class Builds
{
    // Every column (b.*): a database before migration 016, which the site gate keeps closed, still reads its builds
    // through it (tests/before_apply.test.php: the second line) - what the file said of a build (file_build, 022) comes
    // along, unread here.
    private const SELECT = 'SELECT b.*,
            c.id AS c_id, c.game AS c_game, c.key AS c_key, c.name AS c_name, c.element AS c_element,
            c.rarity AS c_rarity, c.weapon_type AS c_weapon_type, c.external_id AS c_external_id,
            c.icon_url AS c_icon_url, c.released_at AS c_released_at, c.meta AS c_meta, c.updated_at AS c_updated_at
        FROM builds b
        JOIN game_characters c ON c.id = b.game_character_id';

    public function __construct(private readonly Db $db) {}

    /**
     * The builds of these characters, grouped by character, in their order.
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
        foreach ($this->db->all(self::SELECT . " WHERE b.game_character_id IN ({$in}) ORDER BY b.position, b.id", $params) as $row) {
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
        foreach ($this->db->all(self::SELECT . " WHERE b.id IN ({$in})", $params) as $row) {
            $byId[(int) $row['id']] = Build::fromRow($row);
        }

        return array_values(array_filter(array_map(fn(int $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * Every build of a game, with its character, by character key (compared as bytes, whatever the database's
     * collation) and then in each character's order: what an export holds, and what an import compares with.
     *
     * @return list<Build>
     */
    public function ofGame(Game $game): array
    {
        return array_map(fn(array $row) => Build::fromRow($row), $this->db->all(self::SELECT . ' WHERE c.game = ? ORDER BY c.key COLLATE "C", b.position, b.id', [$game->value]));
    }

    /**
     * Which of these characters of a game have builds - by key: a teammate links to its builds page only then.
     *
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    public function keysWithBuilds(Game $game, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        [$in, $params] = Db::in(array_values(array_unique($keys)));
        $rows = $this->db->all("SELECT DISTINCT c.key FROM builds b JOIN game_characters c ON c.id = b.game_character_id WHERE c.game = ? AND c.key IN ({$in})", [$game->value, ...$params]);

        return array_fill_keys(array_column($rows, 'key'), true);
    }

    /**
     * Characters of a game that have builds, a line per build: the builds browser.
     *
     * @return list<array{id: int|string, game_character_id: int|string, role: string, min_rank: int|string, checked_at: string|null, changed_at: string|null}>
     */
    public function summaries(Game $game): array
    {
        return $this->db->all(
            'SELECT b.id, b.game_character_id, b.role, b.min_rank, b.checked_at, b.changed_at
               FROM builds b JOIN game_characters c ON c.id = b.game_character_id
              WHERE c.game = ?
              ORDER BY b.game_character_id, b.position, b.id',
            [$game->value],
        );
    }

    /**
     * Per game: how many characters have builds, how many builds there are, when the newest file carrying them was
     * made and when one last changed - for every game, none with zeros.
     *
     * @return array<string, array{characters: int, builds: int, checked_at: string|null, changed_at: string|null}> game => counts
     */
    public function counts(): array
    {
        $counts = [];
        foreach (Game::cases() as $game) {
            $counts[$game->value] = ['characters' => 0, 'builds' => 0, 'checked_at' => null, 'changed_at' => null];
        }
        $rows = $this->db->all(
            'SELECT c.game, count(DISTINCT b.game_character_id) AS characters, count(*) AS builds, max(b.checked_at) AS checked_at, max(b.changed_at) AS changed_at
               FROM builds b JOIN game_characters c ON c.id = b.game_character_id
              GROUP BY c.game',
        );
        foreach ($rows as $row) {
            if (isset($counts[$row['game']])) {
                $counts[$row['game']] = ['characters' => (int) $row['characters'], 'builds' => (int) $row['builds'], 'checked_at' => $row['checked_at'], 'changed_at' => $row['changed_at']];
            }
        }

        return $counts;
    }
}
