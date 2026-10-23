<?php

namespace App\Services\Builds;

use App\Db\Db;
use App\Enums\Game;
use App\Models\Build;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Presenters\Icons;
use App\Repositories\CatalogRows;
use App\Services\Catalog\Catalog;
use App\Services\Translations\SiteTranslations;
use App\Support\Clock;
use App\Support\Json;
use App\Support\Neutral;
use App\Support\Stats;
use App\Support\Talents;
use Closure;

/**
 * The admin panel's builds editor (docs/builds.md, "The editor"): a character's builds as an editor changes them by
 * hand - every part of each, the site's notes on it and its teams, builds of the editor's own, builds removed and
 * brought back, their order -, saved in one go.
 *
 * Loading gives each build in the editor's shape - the builds file's (BuildFile), without its character and without
 * the texts the format carries but the site no longer shows - with what the file said of it (for "use the file's"),
 * the parts that differ from that, its notes, and the builds an editor removed; and the version a save is checked
 * against: a hash of what an editor could see change - never of when a file last carried a build, which every import
 * moves.
 *
 * A save is the character's builds as the editor leaves them, in their order. Each build is read as a file's build is
 * (BuildFile::readEntry(): every field, shape and cap), its notes as BuildNotes reads them; a typed text (a qualifier,
 * a game version, a team's name, a set piece's label) naming another website or holding a web address is refused, as
 * are a build of the file's that changes its role, qualifier or constellation (its name in the file: copy it into a
 * build of the editor's own instead), a new build taking a removed build's name (bring that one back instead) and two
 * builds of one name. A stored build the save lacks is removed - one of the file's remembered among the removals with
 * what the file said of it and its notes (build_removals), so no import brings it back unasked; one of the editor's
 * own deleted. A removed build sent back (`removal`) is brought back with what the file said of it. An order other than
 * the one an import would give is said to be the editor's (build_orders); `order: "file"` gives it back to the file.
 * Nothing changed, nothing written.
 *
 * One save at a time and never during an import: it takes the import's lock (BuildImportBusy, nothing written) and is
 * checked inside it against the version the editor loaded (BuildEditConflict, nothing written). What it did is
 * recorded in its transaction by the caller (the audit log's builds.edited).
 */
final class BuildEditor
{
    /** The most builds a character can have: a few at most; more is no character's. */
    public const MAX_BUILDS = 30;

    /** A save's body: a character's builds and their notes - far under a builds file's. */
    public const MAX_BYTES = 1024 * 1024;

    /** The fields of a build of a save. */
    private const ITEM_FIELDS = ['id', 'removal', 'build', 'notes'];

    public function __construct(private readonly Db $db, private readonly CatalogRows $catalog) {}

    /**
     * What the editor picks from and holds a build to: the game's facts (roles, constellations, each slot's main
     * stats, substats, stats, talents, kinds of sets and their pieces, a team's size, the caps - and their English
     * names, the admin panel's), and the catalog's characters, weapons and sets with their pictures, by name.
     *
     * @return array<string, mixed>
     */
    public function catalog(Game $game): array
    {
        $byName = function (array $entries): array {
            usort($entries, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']) ?: strcmp($a['key'], $b['key']));

            return $entries;
        };

        return [
            'game' => $game->value,
            'facts' => self::facts($game),
            'characters' => $byName(array_map(fn(GameCharacter $character) => self::characterOf($character), $this->catalog->all($game, Catalog::CHARACTER))),
            'weapons' => $byName(
                array_map(
                    fn(GameWeapon $weapon) => [
                        'key' => $weapon->key,
                        'name' => (string) Neutral::text($weapon->name),
                        'weapon_type' => $weapon->weapon_type,
                        'rarity' => $weapon->rarity,
                        'icon_url' => Icons::of($weapon),
                    ],
                    $this->catalog->all($game, Catalog::WEAPON),
                ),
            ),
            'sets' => $byName(
                array_map(
                    fn(GameSet $set) => ['key' => $set->key, 'name' => (string) Neutral::text($set->name), 'kind' => $set->kind, 'icon_url' => Icons::of($set)],
                    $this->catalog->all($game, Catalog::SET),
                ),
            ),
        ];
    }

    /**
     * The characters with builds or with builds an editor removed, by name, each with what the editors did: how many
     * builds, how many of the file's with an edit, made here, removed, with notes, and whether their order is the
     * editor's - the Builds page's list.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Game $game): array
    {
        $counts = [];
        $count = function (int $id) use (&$counts): void {
            $counts[$id] ??= ['builds' => 0, 'edited' => 0, 'made_here' => 0, 'removed' => 0, 'notes' => 0, 'ordered' => false];
        };
        foreach (
            $this->db->all(
                'SELECT b.game_character_id, b.role, b.qualifier, b.min_rank, b."position", b.weapons, b.sets, b.main_stats, b.substats, b.talent_priority,
                        b.stat_targets, b.teams, b.notes, b.game_version, b.file_build, b.site_notes
                   FROM builds b JOIN game_characters c ON c.id = b.game_character_id
                  WHERE c.game = ?',
                [$game->value],
            )
            as $row
        ) {
            $id = (int) $row['game_character_id'];
            $count($id);
            $counts[$id]['builds']++;
            $base = BuildRows::fromStored($row['file_build']);
            if ($base === null) {
                $counts[$id]['made_here']++;
            } elseif (BuildParts::differing(BuildParts::of(BuildRows::decoded($row)), BuildParts::of($base)) !== []) {
                $counts[$id]['edited']++;
            }
            $counts[$id]['notes'] += BuildNotes::any(BuildNotes::read($row['site_notes'])) ? 1 : 0;
        }
        foreach ($this->db->all('SELECT r.game_character_id FROM build_removals r JOIN game_characters c ON c.id = r.game_character_id WHERE c.game = ?', [$game->value]) as $row) {
            $count((int) $row['game_character_id']);
            $counts[(int) $row['game_character_id']]['removed']++;
        }
        foreach ($this->db->all('SELECT o.game_character_id FROM build_orders o JOIN game_characters c ON c.id = o.game_character_id WHERE c.game = ?', [$game->value]) as $row) {
            $count((int) $row['game_character_id']);
            $counts[(int) $row['game_character_id']]['ordered'] = true;
        }

        $list = [];
        foreach ($this->catalog->byIds(Catalog::CHARACTER, array_keys($counts)) as $character) {
            $list[] = ['key' => $character->key, 'name' => (string) Neutral::text($character->name), 'icon_url' => Icons::of($character)] + $counts[$character->id];
        }
        usort($list, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']) ?: strcmp($a['key'], $b['key']));

        return $list;
    }

    /**
     * A character's builds as the editor's page shows them (see the class comment), in their order.
     *
     * @return array<string, mixed>
     */
    public function load(Game $game, GameCharacter $character): array
    {
        $state = $this->state($character);
        $teammates = [];
        foreach ([...array_column($state['rows'], 'notes'), ...array_column($state['removals'], 'notes')] as $notes) {
            foreach (array_keys((array) ($notes['teams'] ?? [])) as $key) {
                array_push($teammates, ...explode(' ', (string) $key));
            }
        }
        $names = array_map(fn(GameCharacter $teammate) => $teammate->name, $teammates === [] ? [] : $this->catalog->byKeys($game, Catalog::CHARACTER, array_values(array_unique($teammates))));

        return [
            'game' => $game->value,
            'character' => self::characterOf($character),
            'version' => $state['version'],
            'order' => $state['ordered'] ? 'editors' : 'file',
            'builds' => array_map(function (array $row) use ($character, $names) {
                $build = self::shape($row['build'], $character);

                return [
                    'id' => $row['id'],
                    'origin' => $row['base'] === null ? 'made_here' : 'file',
                    'build' => $build,
                    'file' => $row['base'] === null ? null : self::shape($row['base'], $character),
                    // Where the file puts it among the character's builds: where a page puts a build brought back.
                    'file_position' => $row['base']['position'] ?? null,
                    'edited' => $row['base'] === null ? [] : BuildParts::differing(BuildParts::of($row['build']), BuildParts::of($row['base'])),
                    'notes' => BuildNotes::forEditor($row['notes'], $build['teams'], $names),
                    'checked_at' => Clock::iso($row['checked_at']),
                    'changed_at' => Clock::iso($row['changed_at']),
                    'edited_at' => Clock::iso($row['edited_at']),
                ];
            }, $state['rows']),
            'removed' => array_map(function (array $removal) use ($character, $names) {
                $build = self::shape($removal['base'], $character);

                return [
                    'id' => $removal['id'],
                    'build' => $build,
                    'file_position' => $removal['base']['position'],
                    'notes' => BuildNotes::forEditor($removal['notes'], $build['teams'], $names),
                    'removed_at' => Clock::iso($removal['removed_at']),
                ];
            }, $state['removals']),
        ];
    }

    /**
     * Saves a character's builds as the editor leaves them (see the class comment) and gives them back as load() does.
     *
     * @param  mixed  $payload  {version, builds: [{id, removal, build, notes}], order?: "file"}
     * @param  (Closure(array<string, mixed>): void)|null  $record  inside the save's transaction, with what it did: what else records it
     * @return array<string, mixed>
     *
     * @throws BuildEditException the save does not fit; nothing was written
     * @throws BuildFileException a build of it is not a build; nothing was written
     * @throws BuildImportBusy an import or another save is being written; nothing was written
     * @throws BuildEditConflict the builds changed since the editor loaded them; nothing was written
     */
    public function save(Game $game, GameCharacter $character, mixed $payload, ?Closure $record = null): array
    {
        [$version, $items, $fileOrder] = self::payload($payload);

        return $this->db->transaction(function () use ($game, $character, $version, $items, $fileOrder, $record) {
            if (!$this->db->value('SELECT pg_try_advisory_xact_lock(CAST(? AS bigint))', [BuildImport::LOCK])) {
                throw new BuildImportBusy('An import of builds or another save is being written. Try again in a moment.');
            }
            $this->db->run("SET LOCAL statement_timeout = '30s'");

            $state = $this->state($character);
            if (!hash_equals($state['version'], $version)) {
                throw new BuildEditConflict('These builds changed since they were opened - an import, or another editor\'s save. Reload them: what was typed here stays on the page until then.');
            }
            $results = $this->read($game, $character, $state, $items);
            $plan = $this->plan($character, $state, $results, $fileOrder);
            if ($plan['changes'] === ['created' => [], 'updated' => [], 'removed' => [], 'restored' => []] && $plan['order'] === null) {
                return $this->load($game, $character);
            }
            $this->write($character, $plan);
            if ($record !== null) {
                $record(['game' => $game->value, 'character' => $character->key] + $plan['changes'] + ['order' => $plan['order']]);
            }

            return $this->load($game, $character);
        });
    }

    /**
     * The builds of a character as stored - each decoded, with what the file said of it (null: made here) and its
     * notes -, the builds an editor removed, whether the order is the editor's, and their version.
     *
     * @return array{rows: list<array<string, mixed>>, removals: list<array<string, mixed>>, ordered: bool, version: string}
     */
    private function state(GameCharacter $character): array
    {
        $rows = array_map(
            fn(array $row) => [
                'id' => (int) $row['id'],
                'build' => BuildRows::decoded($row),
                'base' => BuildRows::fromStored($row['file_build']),
                'notes' => BuildNotes::read($row['site_notes']),
                'checked_at' => Db::time($row['checked_at']),
                'changed_at' => Db::time($row['changed_at']),
                'edited_at' => Db::time($row['edited_at']),
            ],
            $this->db->all(
                'SELECT id, role, qualifier, min_rank, "position", weapons, sets, main_stats, substats, talent_priority, stat_targets, teams, notes, game_version,
                        file_build, site_notes, checked_at, changed_at, edited_at
                   FROM builds WHERE game_character_id = ? ORDER BY "position", id',
                [$character->id],
            ),
        );
        $removals = array_map(
            fn(array $row) => [
                'id' => (int) $row['id'],
                'base' => BuildRows::fromStored($row['file_build']),
                'notes' => BuildNotes::read($row['site_notes']),
                'removed_at' => Db::time($row['removed_at']),
            ],
            $this->db->all('SELECT id, file_build, site_notes, removed_at FROM build_removals WHERE game_character_id = ? ORDER BY removed_at, id', [$character->id]),
        );
        $ordered = (bool) $this->db->value('SELECT count(*) FROM build_orders WHERE game_character_id = ?', [$character->id]);

        return ['rows' => $rows, 'removals' => $removals, 'ordered' => $ordered, 'version' => self::version($rows, $removals, $ordered)];
    }

    /**
     * The version a save is checked against: of what an editor could see change - each build's variation, place,
     * parts (BuildParts: not whether the catalog knows a weapon), what the file said of it and its notes; the removed
     * builds; whose order it is. Never a time: every import moves when a file last carried a build.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $removals
     */
    private static function version(array $rows, array $removals, bool $ordered): string
    {
        return hash(
            'sha256',
            Json::encode([
                array_map(
                    fn(array $row) => [
                        $row['id'],
                        $row['build']['role'],
                        $row['build']['qualifier'],
                        $row['build']['min_rank'],
                        $row['build']['position'],
                        array_values(BuildParts::of($row['build'])),
                        $row['base'] === null ? null : [$row['base']['position'], array_values(BuildParts::of($row['base']))],
                        $row['notes'],
                    ],
                    $rows,
                ),
                array_map(fn(array $removal) => [$removal['id'], array_values(BuildParts::of($removal['base'])), $removal['base']['qualifier'], $removal['notes']], $removals),
                $ordered,
            ]),
        );
    }

    /**
     * A save's body, its own shape checked: [version, its builds, whether it gives the order back to the file].
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: bool}
     *
     * @throws BuildEditException
     */
    private static function payload(mixed $payload): array
    {
        $fields = is_array($payload) && !array_is_list($payload) ? array_keys($payload) : null;
        if ($fields === null || array_diff($fields, ['version', 'builds', 'order']) !== [] || !isset($payload['version'], $payload['builds'])) {
            throw new BuildEditException('A save is an object of its version, its builds and - to give the order back to the file - order: "file".', 'invalid_body');
        }
        if (!is_string($payload['version']) || !preg_match('/^[0-9a-f]{64}\z/', $payload['version'])) {
            throw new BuildEditException('The save\'s version is not the one its builds were loaded with.', 'invalid_body');
        }
        $order = $payload['order'] ?? null;
        if ($order !== null && $order !== 'file') {
            throw new BuildEditException('The save\'s order is "file" (the file\'s order) or none (the order sent).', 'invalid_body');
        }
        if (!is_array($payload['builds']) || !array_is_list($payload['builds']) || count($payload['builds']) > self::MAX_BUILDS) {
            throw new BuildEditException('The save\'s builds are a list of at most ' . self::MAX_BUILDS . '.', 'invalid_body');
        }
        foreach ($payload['builds'] as $i => $item) {
            if (!BuildNotes::hasExactly($item, self::ITEM_FIELDS)) {
                throw new BuildEditException('Build ' . ($i + 1) . ': a build of a save is an object of exactly ' . implode(', ', self::ITEM_FIELDS) . '.', 'invalid_body', $i);
            }
            foreach (['id', 'removal'] as $field) {
                if ($item[$field] !== null && (!is_int($item[$field]) || $item[$field] < 1)) {
                    throw new BuildEditException('Build ' . ($i + 1) . ": its {$field} is a stored one's or null.", 'invalid_body', $i, $field);
                }
            }
        }

        return [$payload['version'], $payload['builds'], $order === 'file'];
    }

    /**
     * Each build of a save read and checked (see the class comment): the stored build it is or the removed one it brings
     * back, the build as BuildFile reads it, its notes as stored, its identity.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     *
     * @throws BuildEditException
     * @throws BuildFileException
     */
    private function read(Game $game, GameCharacter $character, array $state, array $items): array
    {
        $rows = array_column($state['rows'], null, 'id');
        $removals = array_column($state['removals'], null, 'id');
        $identity = fn(array $build) => BuildFile::identity($character->key, $build['role'], $build['qualifier'], $build['min_rank']);
        $results = [];
        $seen = ['rows' => [], 'removals' => [], 'identities' => []];
        foreach ($items as $i => $item) {
            $row = null;
            $removal = null;
            if ($item['id'] !== null) {
                $row = $rows[$item['id']] ?? throw self::refused('it is no build of this character\'s', $i, 'id');
                if (isset($seen['rows'][$row['id']])) {
                    throw self::refused('it is in the save twice', $i, 'id');
                }
                $seen['rows'][$row['id']] = true;
            }
            if ($item['removal'] !== null) {
                if ($row !== null) {
                    throw self::refused('a build is a stored one (id) or one brought back (removal), never both', $i, 'removal');
                }
                $removal = $removals[$item['removal']] ?? throw self::refused('it is no removed build of this character\'s', $i, 'removal');
                if (isset($seen['removals'][$removal['id']])) {
                    throw self::refused('it is brought back twice', $i, 'removal');
                }
                $seen['removals'][$removal['id']] = true;
            }

            self::refuseFileFields($item['build'], $i);
            self::refuseOtherSites($item['build'], $i);
            $build = BuildFile::readEntry(self::fileEntry($item['build'], $character), $game, $i);

            // A name as load() sent it (neutral): the stored spelling kept where only that differs.
            $was = $row['base'] ?? null;
            if ($was !== null) {
                if (!self::sameName($build, $row['build'])) {
                    throw self::refused(
                        'a build of the file\'s keeps its role, qualifier and constellation - its name in the file. Copy it into a build of your own to change them',
                        $i,
                        self::identityField($build, $row['build']),
                    );
                }
                $build['qualifier'] = $row['build']['qualifier'];
            } elseif ($row !== null && self::sameName($build, $row['build'])) {
                // One of the editor's own, not renamed.
                $build['qualifier'] = $row['build']['qualifier'];
            }
            if ($removal !== null) {
                if (!self::sameName($build, $removal['base'])) {
                    throw self::refused('a build brought back keeps its role, qualifier and constellation', $i, self::identityField($build, $removal['base']));
                }
                $build['qualifier'] = $removal['base']['qualifier'];
            }
            if (isset($seen['identities'][$identity($build)])) {
                throw self::refused('its role, qualifier and constellation are those of build ' . ($seen['identities'][$identity($build)] + 1) . ' too', $i, 'qualifier');
            }
            $seen['identities'][$identity($build)] = $i;

            $results[] = [
                'row' => $row,
                'removal' => $removal,
                'build' => $build,
                'notes' => BuildNotes::fromEditor($item['notes'], $build['teams'], $i, $game->teamSize(), BuildFile::neutralKey($character->key)),
                'identity' => $identity($build),
            ];
        }

        // The file's builds removed - before (and not brought back now), or by this save: no other build takes their name.
        $removed = [];
        foreach ($state['removals'] as $removal) {
            if (!isset($seen['removals'][$removal['id']])) {
                $removed[$identity($removal['base'])] = true;
            }
        }
        foreach ($state['rows'] as $row) {
            if (!isset($seen['rows'][$row['id']]) && $row['base'] !== null) {
                $removed[$identity($row['build'])] = true;
            }
        }
        foreach ($results as $i => $result) {
            if ($result['removal'] === null && ($result['row']['base'] ?? null) === null && isset($removed[$result['identity']])) {
                throw self::refused('its role, qualifier and constellation are those of a build of the file\'s that was removed: bring that one back instead', $i, 'qualifier');
            }
        }

        return $results;
    }

    /**
     * What a save writes: each build's columns in its place, the builds it removes, whose order it is - and what it
     * changed, as the audit log records it.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function plan(GameCharacter $character, array $state, array $results, bool $fileOrder): array
    {
        $game = $character->game;
        $name = fn(array $build) => BuildFile::variation($game, $build['role'], $build['qualifier'], $build['min_rank']);
        $baseOf = fn(array $result) => $result['row']['base'] ?? ($result['removal']['base'] ?? null);

        // The order an import would give: the file's builds by the place the file gave them, the editor's own after them
        // as sent. The save's own order unless it gives the order back to the file: one other than that is the editor's.
        $indexes = array_keys($results);
        $fileBuilds = array_values(array_filter($indexes, fn(int $i) => $baseOf($results[$i]) !== null));
        usort($fileBuilds, fn(int $a, int $b) => $baseOf($results[$a])['position'] <=> $baseOf($results[$b])['position'] ?: $a <=> $b);
        $canonical = [...$fileBuilds, ...array_values(array_filter($indexes, fn(int $i) => $baseOf($results[$i]) === null))];
        $final = $fileOrder ? $canonical : $indexes;
        $order = match (true) {
            $fileOrder && $state['ordered'] => 'file',
            !$fileOrder && !$state['ordered'] && $final !== $canonical => 'editors',
            default => null,
        };

        $weaponKeys = [];
        foreach ($results as $result) {
            array_push($weaponKeys, ...array_column($result['build']['weapons'], 'key'));
        }
        $weapons = $weaponKeys === [] ? [] : $this->catalog->byKeys($game, Catalog::WEAPON, array_values(array_unique($weaponKeys)));

        $changes = ['created' => [], 'updated' => [], 'removed' => [], 'restored' => []];
        $updates = [];
        $creates = [];
        foreach ($final as $position => $i) {
            $result = $results[$i];
            $row = $result['row'];
            $build = $result['build'];
            // A part sent back as load() sent it stays as stored - its texts as they were, whatever the names list
            // has learned since -, so it is no edit; only what the editor changed is the editor's.
            if ($row !== null) {
                $build = self::untouchedAsStored($build, $row['build'], $character);
            }
            $build['position'] = $position;
            $build['weapons'] = BuildRows::known($build['weapons'], $weapons);
            $columns = BuildRows::columns($build, $baseOf($result)) + ['site_notes' => $result['notes'] === null ? null : Json::column($result['notes'])];
            if ($row === null) {
                $creates[] = ['columns' => $columns, 'removal' => $result['removal']['id'] ?? null];
                $changes[$result['removal'] === null ? 'created' : 'restored'][] = $name($build);

                continue;
            }
            $old = BuildRows::columns($row['build'], $row['base']) + ['site_notes' => $row['notes'] === [] ? null : Json::column($row['notes'])];
            $parts = BuildParts::differing(BuildParts::of($row['build']), BuildParts::of($build));
            $renamed = [$build['role'], $build['qualifier'], $build['min_rank']] !== [$row['build']['role'], $row['build']['qualifier'], $row['build']['min_rank']];
            $moved = $row['build']['position'] !== $position;
            $noted = self::sortedNotes($row['notes']) !== self::sortedNotes($result['notes'] ?? []);
            $visible = $parts !== [] || $renamed || $moved || $noted;
            if (!$visible && $columns === $old) {
                continue;
            }
            if ($visible) {
                $changes['updated'][] = ['build' => $name($build), 'parts' => $parts, 'renamed' => $renamed, 'moved' => $moved, 'notes' => $noted];
            }
            $updates[] = ['id' => $row['id'], 'columns' => $columns, 'visible' => $visible, 'renamed' => $renamed];
        }
        $deletes = [];
        $sent = array_flip(array_filter(array_map(fn(array $result) => $result['row']['id'] ?? null, $results)));
        foreach ($state['rows'] as $row) {
            if (!isset($sent[$row['id']])) {
                $deletes[] = $row;
                $changes['removed'][] = $name($row['build']);
            }
        }

        return ['changes' => $changes, 'order' => $order, 'updates' => $updates, 'creates' => $creates, 'deletes' => $deletes];
    }

    /**
     * @param  array<string, mixed>  $plan  plan()
     */
    private function write(GameCharacter $character, array $plan): void
    {
        $now = Clock::now();

        // Removed first: a build of the file's remembered with what the file said of it and its notes, so no import
        // brings it back unasked; one of the editor's own deleted.
        foreach ($plan['deletes'] as $row) {
            if ($row['base'] !== null) {
                $this->db->insert('build_removals', [
                    'game_character_id' => $character->id,
                    'role' => $row['build']['role'],
                    'qualifier' => $row['build']['qualifier'],
                    'min_rank' => $row['build']['min_rank'],
                    'file_build' => Json::column(BuildRows::stored($row['base'])),
                    'site_notes' => $row['notes'] === [] ? null : Json::column($row['notes']),
                    'removed_at' => $now,
                ]);
            }
            $this->db->run('DELETE FROM builds WHERE id = ?', [$row['id']]);
        }
        // A build renamed is first set aside - so two builds of the editor's trading names never meet on the way.
        foreach ($plan['updates'] as $update) {
            if ($update['renamed']) {
                $this->db->run('UPDATE builds SET qualifier = ? WHERE id = ?', ["\u{1}{$update['id']}", $update['id']]);
            }
        }
        foreach ($plan['updates'] as $update) {
            $times = ['updated_at' => $now] + ($update['visible'] ? ['changed_at' => $now, 'edited_at' => $now] : []);
            $this->db->update('builds', $update['columns'] + $times, ['id' => $update['id']]);
        }
        foreach ($plan['creates'] as $create) {
            if ($create['removal'] !== null) {
                $this->db->run('DELETE FROM build_removals WHERE id = ?', [$create['removal']]);
            }
            // No file carried it since it was made or brought back: checked by the next file that has it.
            $this->db->insert(
                'builds',
                ['game_character_id' => $character->id] + $create['columns'] + ['checked_at' => null, 'changed_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'edited_at' => $now],
            );
        }
        if ($plan['order'] === 'editors') {
            $this->db->run('INSERT INTO build_orders (game_character_id, ordered_at) VALUES (?, ?) ON CONFLICT (game_character_id) DO NOTHING', [$character->id, $now]);
        } elseif ($plan['order'] === 'file') {
            $this->db->run('DELETE FROM build_orders WHERE game_character_id = ?', [$character->id]);
        }
    }

    /**
     * A build of a save as the file holds one: its character, and the texts the format carries but the site no longer
     * shows (the notes of the build, of each weapon, set option and team, a target's text) as none. What is not even an
     * object is left as it is, for the reader to refuse.
     */
    private static function fileEntry(mixed $build, GameCharacter $character): mixed
    {
        if (!is_array($build) || ($build !== [] && array_is_list($build))) {
            return $build;
        }
        $none = function (array $entry, string $list, string $field, mixed $value): array {
            if (array_key_exists($list, $entry) && is_array($entry[$list]) && array_is_list($entry[$list])) {
                $entry[$list] = array_map(fn($item) => is_array($item) && !array_is_list($item) ? array_replace($item, [$field => $value]) : $item, $entry[$list]);
            }

            return $entry;
        };
        $entry = ['character' => $character->key, 'character_name' => $character->name] + $build;
        $entry = $none($entry, 'weapons', 'note', null);
        $entry = $none($entry, 'sets', 'note', null);
        $entry = $none($entry, 'teams', 'note', null);
        $entry = $none($entry, 'stat_targets', 'text', '');
        $entry['notes'] = null;

        return $entry;
    }

    /**
     * A build in the editor's shape: the builds file's (BuildFile::entry(): every text neutral, the order the rank),
     * without its character and the texts the format carries but the site no longer shows.
     *
     * @param  array<string, mixed>  $build  BuildRows::decoded() or fromStored()
     * @return array<string, mixed>
     */
    private static function shape(array $build, GameCharacter $character): array
    {
        $entry = BuildFile::entry(
            new Build(
                id: 0,
                game_character_id: $character->id,
                role: $build['role'],
                qualifier: $build['qualifier'],
                min_rank: $build['min_rank'],
                position: $build['position'],
                weapons: $build['weapons'],
                sets: $build['sets'],
                main_stats: $build['main_stats'],
                substats: $build['substats'],
                talent_priority: $build['talent_priority'],
                stat_targets: $build['stat_targets'],
                teams: $build['teams'],
                notes: $build['notes'],
                game_version: $build['game_version'],
                character: $character,
            ),
        );
        $without = fn(?array $list, string $field) => $list === null ? null : array_map(fn(array $item) => array_diff_key($item, [$field => true]), $list);

        return [
            'role' => $entry['role'],
            'qualifier' => $entry['qualifier'],
            'min_rank' => $entry['min_rank'],
            'weapons' => $without($entry['weapons'], 'note'),
            'sets' => $without($entry['sets'], 'note'),
            'main_stats' => $entry['main_stats'],
            'substats' => $entry['substats'],
            'talent_priority' => $entry['talent_priority'],
            'stat_targets' => $without($entry['stat_targets'], 'text'),
            'teams' => $without($entry['teams'], 'note'),
            'game_version' => $entry['game_version'],
        ];
    }

    /**
     * The texts an editor types into a build - its qualifier, its game version, its teams' names, its set pieces'
     * labels -, none naming another website or holding a web address: refused, never reworded behind the editor's
     * back (the reader would make them neutral).
     *
     * @throws BuildEditException
     */
    private static function refuseOtherSites(mixed $build, int $index): void
    {
        if (!is_array($build)) {
            return;
        }
        $texts = ['qualifier' => $build['qualifier'] ?? null, 'game_version' => $build['game_version'] ?? null];
        foreach (is_array($build['teams'] ?? null) ? $build['teams'] : [] as $t => $team) {
            $texts["teams[{$t}].name"] = is_array($team) ? $team['name'] ?? null : null;
        }
        foreach (is_array($build['sets'] ?? null) ? $build['sets'] : [] as $s => $option) {
            foreach (is_array($option) && is_array($option['pieces'] ?? null) ? $option['pieces'] : [] as $p => $piece) {
                $texts["sets[{$s}].pieces[{$p}].label"] = is_array($piece) ? $piece['label'] ?? null : null;
            }
        }
        foreach ($texts as $path => $text) {
            if (is_string($text) && ($found = SiteTranslations::otherSites($text)) !== []) {
                throw new BuildEditException(
                    'Build ' . ($index + 1) . ": {$path} " . BuildNotes::naming($found) . ' Nothing the site shows names or links another website, service or tool.',
                    'names_another_site',
                    $index,
                    $path,
                );
            }
        }
    }

    /**
     * A save's body as arrays: objects (stdClass, JsonBody's) as arrays of their members - never through JSON again, so
     * a number too large for a float (1e400, INF) reaches the reader, which refuses it at its path.
     */
    public static function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }

    /**
     * The fields a builds file's build has but an editor's has not - its character, its notes, the notes of its
     * weapons, set options and teams, its targets' texts -: refused, never dropped behind the editor's back (a note
     * goes in the build's notes).
     *
     * @throws BuildEditException
     */
    private static function refuseFileFields(mixed $build, int $index): void
    {
        if (!is_array($build)) {
            return;
        }
        $fields = [];
        foreach (['character', 'character_name', 'notes'] as $field) {
            $fields[] = array_key_exists($field, $build) ? $field : null;
        }
        foreach (['weapons' => 'note', 'sets' => 'note', 'teams' => 'note', 'stat_targets' => 'text'] as $list => $field) {
            foreach (is_array($build[$list] ?? null) ? $build[$list] : [] as $i => $entry) {
                $fields[] = is_array($entry) && array_key_exists($field, $entry) ? "{$list}[{$i}].{$field}" : null;
            }
        }
        if (($path = array_values(array_filter($fields))[0] ?? null) !== null) {
            throw self::refused("{$path} is no field of an editor's build (the notes go in the build's notes)", $index, $path);
        }
    }

    /** Whether a build read from a save has the name of $was as load() sent it: its role, constellation, neutral qualifier. */
    private static function sameName(array $build, array $was): bool
    {
        return $build['role'] === $was['role'] && $build['min_rank'] === $was['min_rank'] && $build['qualifier'] === BuildFile::qualifierText($was['qualifier']);
    }

    /**
     * $build with each part load() would have sent back as it is ($stored, read back as a save's build) as stored.
     *
     * @param  array<string, mixed>  $build  read from a save
     * @param  array<string, mixed>  $stored  BuildRows::decoded()
     * @return array<string, mixed>
     */
    private static function untouchedAsStored(array $build, array $stored, GameCharacter $character): array
    {
        try {
            // As arrays, as a save arrives: the shape's main stats are an object, for the JSON it is sent as.
            $sent = BuildFile::readEntry(self::fileEntry(self::arrays(self::shape($stored, $character)), $character), $character->game, 0);
        } catch (BuildFileException) {
            // What is stored no longer reads as a build (a reader stricter since): every part as the save sends it.
            return $build;
        }
        $parts = BuildParts::of($build);
        $sentParts = BuildParts::of($sent);
        foreach (BuildParts::PARTS as $part) {
            if ($parts[$part] === $sentParts[$part]) {
                $build[$part] = $stored[$part];
            }
        }

        return $build;
    }

    /** The first of a build's role, qualifier and constellation that is not as $was has it. */
    private static function identityField(array $build, array $was): string
    {
        return match (true) {
            $build['role'] !== $was['role'] => 'role',
            $build['qualifier'] !== $was['qualifier'] => 'qualifier',
            default => 'min_rank',
        };
    }

    /** Notes as compared: their teams' in one order, whatever order they were written in. */
    private static function sortedNotes(array $notes): array
    {
        if (isset($notes['teams']) && is_array($notes['teams'])) {
            ksort($notes['teams'], SORT_STRING);
        }
        ksort($notes, SORT_STRING);

        return $notes;
    }

    /**
     * A character as the editor names it.
     *
     * @return array{key: string, name: string, element: ?string, weapon_type: ?string, rarity: ?int, icon_url: ?string}
     */
    private static function characterOf(GameCharacter $character): array
    {
        return [
            'key' => $character->key,
            'name' => (string) Neutral::text($character->name),
            'element' => $character->element,
            'weapon_type' => $character->weapon_type,
            'rarity' => $character->rarity,
            'icon_url' => Icons::of($character),
        ];
    }

    /**
     * The game's facts the editor holds a build to (see catalog()).
     *
     * @return array<string, mixed>
     */
    private static function facts(Game $game): array
    {
        $slots = BuildFile::mainStatChoices($game);
        $stats = Stats::keys($game);
        $named = array_values(array_unique([...$stats, ...Stats::substatKeys($game), ...array_merge(...array_values($slots))]));

        return [
            'roles' => $game->roles(),
            'max_rank' => $game->maxRank(),
            'rank_prefix' => $game->rankPrefix(),
            'refinement_prefix' => $game->refinementPrefix(),
            'slots' => $slots,
            'substats' => Stats::substatKeys($game),
            'stats' => $stats,
            'talents' => Talents::keys($game),
            'set_kinds' => array_combine($game->setKinds(), array_map($game->setPieceCounts(...), $game->setKinds())),
            'team_size' => $game->teamSize(),
            'labels' => [
                'stats' => array_combine($named, array_map(fn(string $key) => Stats::label($key, $game), $named)),
                'talents' => array_combine(Talents::keys($game), array_map(fn(string $key) => Talents::label($game, $key), Talents::keys($game))),
                'slots' => array_combine(array_keys($slots), array_map($game->slotLabel(...), array_keys($slots))),
                'set_kinds' => array_combine($game->setKinds(), array_map($game->setLabel(...), $game->setKinds())),
            ],
            'limits' => BuildFile::limits() + ['note_build' => BuildNotes::MAX_BUILD, 'note_team' => BuildNotes::MAX_TEAM, 'builds' => self::MAX_BUILDS],
        ];
    }

    private static function refused(string $problem, int $index, string $path): BuildEditException
    {
        return new BuildEditException('Build ' . ($index + 1) . ": {$problem}.", 'invalid_build', $index, $path);
    }
}
