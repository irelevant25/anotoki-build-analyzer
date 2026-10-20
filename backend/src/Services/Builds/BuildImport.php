<?php

namespace App\Services\Builds;

use App\Db\Db;
use App\Enums\Game;
use App\Models\Build;
use App\Models\User;
use App\Repositories\Builds;
use App\Repositories\CatalogRows;
use App\Services\Catalog\Catalog;
use App\Support\Clock;
use App\Support\Json;
use Closure;
use DateTimeImmutable;

/**
 * A game's builds brought in from a file (BuildFile) on the admin panel's Builds page, and written out to one.
 *
 * The file is the whole truth for its game: after an import the game's builds are exactly the file's - but for
 * characters this installation's catalog lacks, which are skipped (an import never adds to the catalog) and named
 * in the answer. A build is found again by its identity (BuildFile::identity()): one the file has again keeps its
 * id - a link to it stays -, and is updated when its content or its place among the character's builds changed,
 * kept otherwise; one the file lacks is removed - every build of a character the file does not have -; one new to
 * it is created. A dry run says all that and writes nothing.
 *
 * An import is one transaction under a lock of its own (a second import meanwhile is refused: busy, nothing
 * written), and is recorded in it: a row of build_imports - the game, when its file was made, what it did, who
 * imported it - and whatever the caller records (the audit log's builds.imported). checked_at of every build the
 * file has is when the file was made, changed_at of every build it created or changed too; a time from another
 * computer's clock is never later than now here.
 */
final class BuildImport
{
    /** The route's time limit for an import; one takes seconds. */
    public const MAX_SECONDS = 240;

    /**
     * The advisory lock an import holds for its transaction (pg_try_advisory_xact_lock): given back when it commits
     * or rolls back, so a request that dies holds it no longer than its transaction - a pooled connection's next
     * request rolls that back first (App\Db\Db::pdo()).
     */
    public const LOCK = 4_270_031_016;

    /** How many changes an answer lists; the counts count them all. */
    public const MAX_CHANGES = 500;

    /** How many imports of a game the Builds page lists. */
    public const RECENT = 10;

    public function __construct(private readonly Db $db, private readonly Builds $builds, private readonly CatalogRows $catalog) {}

    /**
     * The file of a game's builds, by character key and then in each character's order - so two exports of the
     * same builds differ in exported_at only. Neutral words can make two qualifiers of a character's alike, and a
     * file holds each identity once: the later one becomes "… #2".
     *
     * @return array<string, mixed>
     */
    public function export(Game $game): array
    {
        $entries = [];
        $taken = [];
        foreach ($this->builds->ofGame($game) as $build) {
            $entry = BuildFile::entry($build);
            $qualifier = $entry['qualifier'];
            for ($n = 2; isset($taken[BuildFile::identity($entry['character'], $entry['role'], $qualifier, $entry['min_rank'])]); $n++) {
                $qualifier = BuildFile::qualifierText(mb_substr($entry['qualifier'], 0, BuildFile::MAX_QUALIFIER - strlen(" #{$n}")) . " #{$n}");
            }
            $taken[BuildFile::identity($entry['character'], $entry['role'], $qualifier, $entry['min_rank'])] = true;
            $entry['qualifier'] = $qualifier;
            $entries[] = $entry;
        }

        return [
            'format' => BuildFile::FORMAT,
            'format_version' => BuildFile::VERSION,
            'game' => $game->value,
            'exported_at' => BuildFile::time(Clock::now()),
            'builds' => $entries,
        ];
    }

    /**
     * Makes the game's builds exactly the file's - or, as a dry run, says what that would do and writes nothing.
     *
     * @param  mixed  $file  the decoded JSON of the file
     * @param  (Closure(array<string, mixed>): void)|null  $record  inside the import's transaction, with its answer: what else records it
     * @return array{dry_run: bool, game: string, exported_at: string, older_than_current: bool, characters: int, builds: int, created: int, updated: int, unchanged: int, removed: int, skipped: list<array{key: string, name: string, builds: int}>, changes: list<array<string, mixed>>, changes_listed: int}
     *
     * @throws BuildFileException the file does not fit this game; nothing was written
     * @throws BuildImportBusy another import is writing; nothing was written
     */
    public function import(mixed $file, Game $game, User $by, bool $dryRun = false, ?Closure $record = null): array
    {
        $prepared = $this->prepare($file, $game);
        if ($dryRun) {
            return $this->answer($game, $prepared, $this->diff($game, $prepared), dryRun: true);
        }

        return $this->db->transaction(function () use ($game, $prepared, $by, $record) {
            if (!$this->db->value('SELECT pg_try_advisory_xact_lock(CAST(? AS bigint))', [self::LOCK])) {
                throw new BuildImportBusy('Another import of builds is being written. Try again in a moment.');
            }
            // A big file is written in seconds; nothing here may hold the builds' rows for minutes.
            $this->db->run("SET LOCAL statement_timeout = '60s'");
            $this->db->run("SET LOCAL idle_in_transaction_session_timeout = '60s'");

            // Compared inside the lock: an import that committed meanwhile is what this one changes.
            $diff = $this->diff($game, $prepared);
            $this->write($game, $prepared, $diff, $by);
            $answer = $this->answer($game, $prepared, $diff, dryRun: false);
            if ($record !== null) {
                $record($answer);
            }

            return $answer;
        });
    }

    /**
     * The imports of a game, newest first, with who made each (a deleted person's: nobody).
     *
     * @return list<array{id: int, created_at: string|null, file_exported_at: string|null, summary: array<string, mixed>, imported_by: int|null}>
     */
    public function recent(Game $game, int $limit = self::RECENT): array
    {
        return array_map(
            fn(array $row) => [
                'id' => (int) $row['id'],
                'created_at' => Clock::iso(Db::time($row['created_at'])),
                'file_exported_at' => Clock::iso(Db::time($row['file_exported_at'])),
                'summary' => Json::decode($row['summary']) ?? [],
                'imported_by' => $row['imported_by'] === null ? null : (int) $row['imported_by'],
            ],
            $this->db->all('SELECT id, created_at, file_exported_at, summary, imported_by FROM build_imports WHERE game = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit), [$game->value]),
        );
    }

    /**
     * The file read for $game and matched with the catalog: its builds of known characters (each with its
     * character's id, every weapon with whether the catalog knows it), the characters it skips, when it was made.
     *
     * @return array{exported_at: DateTimeImmutable, builds: list<array<string, mixed>>, skipped: list<array{key: string, name: string, builds: int}>}
     */
    private function prepare(mixed $file, Game $game): array
    {
        $read = BuildFile::read($file, $game);
        $characters = $this->catalog->byKeys($game, Catalog::CHARACTER, array_values(array_unique(array_column($read['builds'], 'character'))));
        if ($read['builds'] !== [] && $characters === []) {
            // Most likely a catalog nobody refreshed yet; "importing" nothing would still remove every build.
            throw new BuildFileException("None of the file's characters is in this installation's catalog. Refresh the catalog on the Builds page, then import again.");
        }
        $weaponKeys = [];
        foreach ($read['builds'] as $build) {
            array_push($weaponKeys, ...array_column($build['weapons'], 'key'));
        }
        $weapons = $this->catalog->byKeys($game, Catalog::WEAPON, array_values(array_unique($weaponKeys)));

        $builds = [];
        $skipped = [];
        foreach ($read['builds'] as $build) {
            $character = $characters[$build['character']] ?? null;
            if ($character === null) {
                // The name is neutral already (BuildFile reads every text so); the key is only matched.
                $skipped[$build['character']] ??= ['key' => BuildFile::neutralKey($build['character']), 'name' => (string) $build['name'], 'builds' => 0];
                $skipped[$build['character']]['builds']++;

                continue;
            }
            $build['weapons'] = array_map(fn(array $weapon) => $weapon + ['known' => isset($weapons[$weapon['key']])], $build['weapons']);
            $builds[] = ['character_id' => $character->id, 'character_name' => $character->name] + $build;
        }

        return ['exported_at' => min($read['exported_at'], Clock::now()), 'builds' => $builds, 'skipped' => array_values($skipped)];
    }

    /**
     * What making the game's builds the file's does, build by build: created, updated (with the stored id), kept
     * (ids) and removed (ids), with the changes as the answer lists them.
     *
     * @return array{create: list<array<string, mixed>>, update: list<array<string, mixed>>, keep: list<int>, remove: list<int>, changes: list<array<string, mixed>>}
     */
    private function diff(Game $game, array $prepared): array
    {
        $stored = [];
        $rows = $this->db->all(
            'SELECT b.id, c.key AS character_key, c.name AS character_name, b.role, b.qualifier, b.min_rank, b."position", b.content_hash
               FROM builds b JOIN game_characters c ON c.id = b.game_character_id
              WHERE c.game = ?
              ORDER BY c.key COLLATE "C", b."position", b.id',
            [$game->value],
        );
        foreach ($rows as $row) {
            $stored[BuildFile::identity($row['character_key'], $row['role'], $row['qualifier'], (int) $row['min_rank'])] = $row;
        }

        $diff = ['create' => [], 'update' => [], 'keep' => [], 'remove' => [], 'changes' => []];
        foreach ($prepared['builds'] as $build) {
            $identity = BuildFile::identity($build['character'], $build['role'], $build['qualifier'], $build['min_rank']);
            $build['content_hash'] = self::hash(self::content($build));
            $row = $stored[$identity] ?? null;
            unset($stored[$identity]);

            if ($row === null) {
                $diff['create'][] = $build;
                $diff['changes'][] = self::change('created', $build['character'], $build['character_name'], $build);
            } elseif ($row['content_hash'] !== $build['content_hash'] || (int) $row['position'] !== $build['position'] || $row['qualifier'] !== $build['qualifier']) {
                $diff['update'][] = ['id' => (int) $row['id']] + $build;
                $diff['changes'][] = self::change('updated', $build['character'], $build['character_name'], $build);
            } else {
                $diff['keep'][] = (int) $row['id'];
            }
        }
        foreach ($stored as $row) {
            $diff['remove'][] = (int) $row['id'];
            $diff['changes'][] = self::change('removed', $row['character_key'], $row['character_name'], [
                'role' => $row['role'],
                'qualifier' => $row['qualifier'],
                'min_rank' => (int) $row['min_rank'],
            ]);
        }
        // By character, then as they came: the file's order, the removed builds after.
        usort($diff['changes'], fn(array $a, array $b) => strcmp($a['character']['name'], $b['character']['name']) ?: strcmp($a['character']['key'], $b['character']['key']));

        return $diff;
    }

    /**
     * @param  array{exported_at: DateTimeImmutable, builds: list<array<string, mixed>>, skipped: list<array<string, mixed>>}  $prepared
     * @param  array{create: list<array<string, mixed>>, update: list<array<string, mixed>>, keep: list<int>, remove: list<int>, changes: list<array<string, mixed>>}  $diff
     */
    private function write(Game $game, array $prepared, array $diff, User $by): void
    {
        $now = Clock::now();
        $made = $prepared['exported_at'];

        // Removed first and created last: no identity is ever there twice, even for a moment.
        if ($diff['remove'] !== []) {
            [$in, $params] = Db::in($diff['remove']);
            $this->db->run("DELETE FROM builds WHERE id IN ({$in})", $params);
        }
        foreach ($diff['update'] as $build) {
            $this->db->update('builds', self::columns($build) + ['checked_at' => $made, 'changed_at' => $made, 'updated_at' => $now], ['id' => $build['id']]);
        }
        if ($diff['keep'] !== []) {
            [$in, $params] = Db::in($diff['keep']);
            $this->db->run("UPDATE builds SET checked_at = ?, updated_at = ? WHERE id IN ({$in})", [$made, $now, ...$params]);
        }
        foreach ($diff['create'] as $build) {
            $this->db->insert(
                'builds',
                ['game_character_id' => $build['character_id']] + self::columns($build) + ['checked_at' => $made, 'changed_at' => $made, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $this->db->insert('build_imports', [
            'game' => $game->value,
            'file_exported_at' => $made,
            // What it did, and nobody's name: who imported it is imported_by, nobody's once their data is deleted.
            'summary' => Json::column([
                'characters' => count(array_unique(array_column($prepared['builds'], 'character_id'))),
                'builds' => count($prepared['builds']),
                'created' => count($diff['create']),
                'updated' => count($diff['update']),
                'unchanged' => count($diff['keep']),
                'removed' => count($diff['remove']),
                'skipped' => count($prepared['skipped']),
            ]),
            'imported_by' => $by->id > 0 ? $by->id : null,
            'created_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(Game $game, array $prepared, array $diff, bool $dryRun): array
    {
        // The file of the builds there are now: a dry run's is the latest import's, an import's the one before it.
        $last = Db::time($this->db->value('SELECT file_exported_at FROM build_imports WHERE game = ? ORDER BY created_at DESC, id DESC OFFSET ? LIMIT 1', [$game->value, $dryRun ? 0 : 1]));

        return [
            'dry_run' => $dryRun,
            'game' => $game->value,
            'exported_at' => (string) Clock::iso($prepared['exported_at']),
            'older_than_current' => $last !== null && $prepared['exported_at'] < $last,
            'characters' => count(array_unique(array_column($prepared['builds'], 'character_id'))),
            'builds' => count($prepared['builds']),
            'created' => count($diff['create']),
            'updated' => count($diff['update']),
            'unchanged' => count($diff['keep']),
            'removed' => count($diff['remove']),
            'skipped' => $prepared['skipped'],
            'changes' => array_slice($diff['changes'], 0, self::MAX_CHANGES),
            'changes_listed' => min(count($diff['changes']), self::MAX_CHANGES),
        ];
    }

    /**
     * A change as the answer lists it: the character, the build's variation and what happens to it.
     *
     * @param  array<string, mixed>  $build
     * @return array{change: string, character: array{key: string, name: string}, role: string, qualifier: string, min_rank: int}
     */
    private static function change(string $change, string $key, string $name, array $build): array
    {
        return [
            'change' => $change,
            'character' => ['key' => BuildFile::neutralKey($key), 'name' => (string) BuildFile::neutral($name, 160)],
            'role' => $build['role'],
            // A removed build's is the stored one, read before a name was added to the neutral list, maybe.
            'qualifier' => BuildFile::qualifierText($build['qualifier']),
            'min_rank' => $build['min_rank'],
        ];
    }

    /**
     * What is stored of a build besides its character, identity and place, in the order its content hash reads it.
     *
     * @param  array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private static function content(array $build): array
    {
        return [
            'weapons' => $build['weapons'],
            'sets' => $build['sets'],
            'main_stats' => $build['main_stats'],
            'substats' => $build['substats'],
            'talent_priority' => $build['talent_priority'],
            'stat_targets' => $build['stat_targets'],
            'notes' => $build['notes'],
            'game_version' => $build['game_version'],
        ];
    }

    /** The hash that tells a changed build from an unchanged one: of its content's JSON (App\Support\Json). */
    private static function hash(array $content): string
    {
        return hash('sha256', Json::encode($content));
    }

    /**
     * A build as table columns, its identity and place with them.
     *
     * @param  array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private static function columns(array $build): array
    {
        $columns = ['role' => $build['role'], 'qualifier' => $build['qualifier'], 'min_rank' => $build['min_rank'], 'position' => $build['position']];
        foreach (self::content($build) as $column => $value) {
            $columns[$column] = in_array($column, ['notes', 'game_version'], true) ? $value : Json::column($value);
        }
        // An empty main stats object is {}, not [].
        $columns['main_stats'] = Json::column((object) $build['main_stats']);

        return $columns + ['content_hash' => $build['content_hash']];
    }
}
