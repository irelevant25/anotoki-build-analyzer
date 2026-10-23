<?php

namespace App\Services\Builds;

use App\Cache\Cache;
use App\Config;
use App\Db\Db;
use App\Enums\Game;
use App\Models\Build;
use App\Models\User;
use App\Repositories\Builds;
use App\Repositories\CatalogRows;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogAnswer;
use App\Services\Catalog\CatalogSync;
use App\Support\Clock;
use App\Support\Json;
use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * A game's builds brought in from a file (BuildFile) on the admin panel's Builds page, and written out to one.
 *
 * The file is the truth for its game - but for what an editor changed by hand (docs/builds.md, "Hand edits"). Every
 * build keeps what the last file said of it beside it (builds.file_build), and an import compares the file's new
 * version with that and with what is stored, part by part (BuildParts): a part nobody edited takes the file's version;
 * a part the file did not change keeps the edit - so does one the file now says the same of, still the edit; a part
 * both changed is a question for that build, the edit standing unless the file is chosen. A build found again by its
 * identity (BuildFile::identity()) keeps its id. One new to the file is created - unless an editor removed it
 * (build_removals): then it stays removed, asked about only when the file changed it. One the file lacks is removed -
 * unless an editor made it here (kept) or edited it or wrote notes on it (asked about: kept, the editor's from then
 * on, unless removing it is chosen). Characters this installation's catalog lacks are skipped (an import never adds
 * to the catalog) and named in the answer. A character's builds come in the file's order, its builds made here after
 * them - unless an editor ordered them (build_orders: said, never guessed from the builds' places) or every build of the
 * character was the editor's: then in theirs, the file's new builds after them. The site's notes are never
 * touched. A dry run says all that, every edit standing, and writes nothing.
 *
 * The questions come with a plan: a hash of each question and what it is about. An import that answers questions
 * sends the plan of the preview it answers, and is refused - nothing written - when the questions are no longer those
 * (BuildImportPlanChanged): an editor saved a build, or another import came, in between. One that answers none needs
 * no plan: every edit stands, and what the file says lands beside it all the same.
 *
 * An import is one transaction under a lock of its own (a second import meanwhile is refused: busy, nothing
 * written), and is recorded in it: a row of build_imports - the game, when its file was made, what it did, who
 * imported it - and whatever the caller records (the audit log's builds.imported). checked_at of every build the
 * file has is when the file was made; changed_at of every build whose content or place it changed too - never earlier
 * than it was; a time from another computer's clock is never later than now here.
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

    /** How many teammates the catalog lacks an answer names; the editor needs a few to see what is missing. */
    public const MAX_UNKNOWN_TEAMMATES = 50;

    /** R1: the file and an editor changed the same part of a build. */
    public const EDITED = 'edited';

    /** R2: the file brings a build an editor made here, and not as it is. */
    public const MADE_HERE = 'made_here';

    /** R3: the file brings a build an editor removed, and changed it. */
    public const REMOVED_HERE = 'removed_here';

    /** R4: the file lacks a build an editor edited or wrote notes on. */
    public const REMOVED_BY_FILE = 'removed_by_file';

    /**
     * @param  ?CatalogSync  $sync  with $cache and $config: a character or a weapon the catalog lacks has its list
     *                              refreshed first, at most once per game and list every
     *                              catalog.import_refresh_minutes (an account's import shares the interval); none of
     *                              the three (a tool's own import): no refresh
     */
    public function __construct(
        private readonly Db $db,
        private readonly Builds $builds,
        private readonly CatalogRows $catalog,
        private readonly ?CatalogSync $sync = null,
        private readonly ?Cache $cache = null,
        private readonly ?Config $config = null,
    ) {}

    /**
     * The file of a game's builds, by character key and then in each character's order - so two exports of the
     * same builds differ in exported_at only. Neutral words can make two qualifiers of a character's alike, and a
     * file holds each identity once: the later one becomes "… #2". The builds as they are here - hand edits and
     * builds made here with them -, without the site's notes, which never travel in a file.
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
     * Brings the file's builds in, keeping what editors did - or, as a dry run, says what that would do (every edit
     * standing) and writes nothing.
     *
     * @param  mixed  $file  the decoded JSON of the file
     * @param  (Closure(array<string, mixed>): void)|null  $record  inside the import's transaction, with its answer: what else records it
     * @param  list<string>|true  $fileWins  the questions answered with the file - their ids, or true for every one -;
     *                                       every other is answered with the edit
     * @param  ?string  $plan  the plan of the preview whose questions $fileWins answers: required with any
     * @return array<string, mixed>  the answer (answer())
     *
     * @throws BuildFileException the file does not fit this game, or the choices name no question; nothing was written
     * @throws BuildImportBusy another import is writing; nothing was written
     * @throws BuildImportPlanChanged the questions are not the preview's any more; nothing was written
     */
    public function import(mixed $file, Game $game, User $by, bool $dryRun = false, ?Closure $record = null, array|true $fileWins = [], ?string $plan = null): array
    {
        $prepared = $this->prepare($file, $game);
        if ($dryRun) {
            $merge = $this->merge($game, $prepared);

            return $this->answer($game, $prepared, $merge, self::resolve($merge, []), dryRun: true);
        }
        if ($fileWins !== [] && $plan === null) {
            throw new BuildFileException('Choices come with the plan of the preview they answer: check the file again.');
        }

        return $this->db->transaction(function () use ($game, $prepared, $by, $record, $fileWins, $plan) {
            if (!$this->db->value('SELECT pg_try_advisory_xact_lock(CAST(? AS bigint))', [self::LOCK])) {
                throw new BuildImportBusy('Another import of builds, or a save in the builds editor, is being written. Try again in a moment.');
            }
            // A big file is written in seconds; nothing here may hold the builds' rows for minutes.
            $this->db->run("SET LOCAL statement_timeout = '60s'");
            $this->db->run("SET LOCAL idle_in_transaction_session_timeout = '60s'");

            // Compared inside the lock: an import that committed meanwhile, or an editor's save, is what this one changes.
            $merge = $this->merge($game, $prepared);
            $asked = array_column($merge['questions'], 'id');
            if ($fileWins !== []) {
                if (!hash_equals($merge['plan'], (string) $plan)) {
                    throw new BuildImportPlanChanged('The builds changed since this file was checked - a build was edited, or another import came. Check the file again.');
                }
                $unknown = $fileWins === true ? [] : array_diff($fileWins, $asked);
                if ($unknown !== []) {
                    throw new BuildFileException('The choices name a build this import asks nothing about: ' . implode(', ', array_slice($unknown, 0, 5)) . '.');
                }
            }

            $resolved = self::resolve($merge, $fileWins === true ? $asked : $fileWins);
            $this->write($game, $prepared, $resolved, $by);
            $answer = $this->answer($game, $prepared, $merge, $resolved, dryRun: false);
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
     * character's id, every weapon with whether the catalog knows it), the characters it skips, the teammates of the
     * builds it keeps that the catalog lacks (a build is never skipped for one: the page shows such a teammate by its
     * name) - the first MAX_UNKNOWN_TEAMMATES, and how many -, when it was made.
     *
     * @return array{exported_at: DateTimeImmutable, builds: list<array<string, mixed>>, skipped: list<array{key: string, name: string, builds: int}>, teammates_unknown: list<array{key: string, name: string}>, teammates_unknown_total: int}
     */
    private function prepare(mixed $file, Game $game): array
    {
        $read = BuildFile::read($file, $game);
        $characterKeys = array_values(array_unique(array_column($read['builds'], 'character')));
        // The builds' characters and their teammates looked up together, so one refresh serves both.
        $teammates = self::teammates($read['builds']);
        $lookup = array_values(array_unique([...$characterKeys, ...array_keys($teammates)]));
        $found = $this->catalog->byKeys($game, Catalog::CHARACTER, $lookup);
        // A character newer than the catalog: its list refreshed first - then skipped only if it is still not there.
        if (count($found) < count($lookup) && $this->refreshed($game, Catalog::CHARACTER)) {
            $found = $this->catalog->byKeys($game, Catalog::CHARACTER, $lookup);
        }
        // Skipped and refused by the builds' own characters alone.
        $characters = array_intersect_key($found, array_flip($characterKeys));
        if ($read['builds'] !== [] && $characters === []) {
            // Most likely a catalog nobody refreshed yet; "importing" nothing would still remove every build.
            throw new BuildFileException("None of the file's characters is in this installation's catalog. Refresh the catalog on the Builds page, then import again.");
        }
        $weaponKeys = [];
        foreach ($read['builds'] as $build) {
            array_push($weaponKeys, ...array_column($build['weapons'], 'key'));
        }
        $weaponKeys = array_values(array_unique($weaponKeys));
        $weapons = $this->catalog->byKeys($game, Catalog::WEAPON, $weaponKeys);
        if (count($weapons) < count($weaponKeys) && $this->refreshed($game, Catalog::WEAPON)) {
            $weapons = $this->catalog->byKeys($game, Catalog::WEAPON, $weaponKeys);
        }

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
            $build['weapons'] = BuildRows::known($build['weapons'], $weapons);
            $builds[] = ['character_id' => $character->id, 'character_name' => $character->name] + $build;
        }
        // The teammates of the builds kept: a skipped build's teams say nothing of what is imported (its character is
        // named among the skipped). Keys and names are neutral already (BuildFile reads them so).
        $unknown = array_values(array_diff_key(self::teammates($builds), $found));

        return [
            'exported_at' => min($read['exported_at'], Clock::now()),
            'builds' => $builds,
            'skipped' => array_values($skipped),
            'teammates_unknown' => array_slice($unknown, 0, self::MAX_UNKNOWN_TEAMMATES),
            'teammates_unknown_total' => count($unknown),
        ];
    }

    /**
     * Every teammate the builds' teams name - a member or a stand-in - by key, with the first name it goes by.
     *
     * @param  list<array<string, mixed>>  $builds
     * @return array<string, array{key: string, name: string}>
     */
    private static function teammates(array $builds): array
    {
        $teammates = [];
        foreach ($builds as $build) {
            foreach ($build['teams'] as $team) {
                foreach ($team['members'] as $member) {
                    foreach ([$member, ...$member['alternatives']] as $one) {
                        $teammates[$one['key']] ??= ['key' => $one['key'], 'name' => $one['name']];
                    }
                }
            }
        }

        return $teammates;
    }

    /**
     * A list refreshed for this import, if none was within the interval: true when it changed.
     */
    private function refreshed(Game $game, string $list): bool
    {
        if ($this->sync === null || $this->cache === null || $this->config === null) {
            return false;
        }
        $minutes = max(1, (int) $this->config->get('catalog.import_refresh_minutes', 60));
        if (!$this->cache->add("catalog-import-refresh:{$game->value}:{$list}", Clock::timestamp(), $minutes * 60)) {
            return false;
        }
        try {
            return $this->sync->refresh($game, $list)['outcome'] === CatalogAnswer::CHANGED;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * What the file does to every build of the game, before any choice - an item per build the file has, found again
     * (R1, R2), removed by an editor (R3) or new (R5), and per stored build it lacks (R4) -, with the questions that
     * need an editor's choice and their plan; the stored builds of each character in their order (R7); the catalog's
     * weapons of every build, for whether it knows each.
     *
     * @return array{items: list<array<string, mixed>>, questions: list<array<string, mixed>>, plan: string, before: array<int, list<array<string, mixed>>>, ordered: array<int, true>, weapons: array<string, mixed>}
     */
    private function merge(Game $game, array $prepared): array
    {
        $rows = [];
        $before = [];
        $weaponKeys = [];
        foreach (
            $this->db->all(
                'SELECT b.id, b.game_character_id, c.key AS character_key, c.name AS character_name, b.role, b.qualifier, b.min_rank, b."position",
                        b.weapons, b.sets, b.main_stats, b.substats, b.talent_priority, b.stat_targets, b.teams, b.notes, b.game_version,
                        b.file_build, b.site_notes, b.changed_at
                   FROM builds b JOIN game_characters c ON c.id = b.game_character_id
                  WHERE c.game = ?
                  ORDER BY c.key COLLATE "C", b."position", b.id',
                [$game->value],
            )
            as $row
        ) {
            $stored = [
                'id' => (int) $row['id'],
                'character_id' => (int) $row['game_character_id'],
                'character' => (string) $row['character_key'],
                'character_name' => (string) $row['character_name'],
                'build' => BuildRows::decoded($row),
                'base' => BuildRows::fromStored($row['file_build']),
                'notes' => BuildNotes::read($row['site_notes']),
                'changed_at' => Db::time($row['changed_at']),
            ];
            $rows[BuildFile::identity($stored['character'], $stored['build']['role'], $stored['build']['qualifier'], $stored['build']['min_rank'])] = $stored;
            $before[$stored['character_id']][] = $stored;
            array_push($weaponKeys, ...array_column($stored['build']['weapons'], 'key'));
        }
        $removals = [];
        foreach (
            $this->db->all(
                'SELECT r.id, r.game_character_id, c.key AS character_key, c.name AS character_name, r.role, r.qualifier, r.min_rank, r.file_build, r.site_notes
                   FROM build_removals r JOIN game_characters c ON c.id = r.game_character_id
                  WHERE c.game = ?',
                [$game->value],
            )
            as $row
        ) {
            $removals[BuildFile::identity((string) $row['character_key'], (string) $row['role'], (string) $row['qualifier'], (int) $row['min_rank'])] = [
                'id' => (int) $row['id'],
                'character' => (string) $row['character_key'],
                'character_name' => (string) $row['character_name'],
                'base' => BuildRows::fromStored($row['file_build']),
                // As stored: a build brought back gets them as they were.
                'site_notes' => $row['site_notes'],
            ];
        }
        foreach ($prepared['builds'] as $theirs) {
            array_push($weaponKeys, ...array_column($theirs['weapons'], 'key'));
        }
        // The characters whose builds an editor put in an order of their own (R7): said, never guessed.
        $ordered = array_fill_keys(
            array_map(
                'intval',
                array_column(
                    $this->db->all('SELECT o.game_character_id FROM build_orders o JOIN game_characters c ON c.id = o.game_character_id WHERE c.game = ?', [$game->value]),
                    'game_character_id',
                ),
            ),
            true,
        );

        $items = [];
        foreach ($prepared['builds'] as $theirs) {
            $identity = BuildFile::identity($theirs['character'], $theirs['role'], $theirs['qualifier'], $theirs['min_rank']);
            if (isset($rows[$identity])) {
                $row = $rows[$identity];
                unset($rows[$identity]);
                $items[] = $row['base'] === null ? self::madeHere($row, $theirs) : self::found($row, $theirs);
            } elseif (isset($removals[$identity])) {
                $items[] = self::removedHere($removals[$identity], $theirs);
            } else {
                $items[] = ['type' => 'create', 'theirs' => $theirs];
            }
        }
        foreach ($rows as $row) {
            $items[] = self::lacked($row);
        }

        $asking = array_values(array_filter($items, fn(array $item) => isset($item['question'])));
        $fingerprints = array_column($asking, 'fingerprint', 'id');
        ksort($fingerprints, SORT_STRING);
        $questions = array_column($asking, 'question');
        // By character, then as they came: the file's order, the builds it lacks after.
        usort($questions, fn(array $a, array $b) => strcmp($a['character']['name'], $b['character']['name']) ?: strcmp($a['character']['key'], $b['character']['key']));

        return [
            'items' => $items,
            'questions' => $questions,
            'plan' => hash('sha256', Json::encode(array_values($fingerprints))),
            'before' => $before,
            'ordered' => $ordered,
            'weapons' => $this->catalog->byKeys($game, Catalog::WEAPON, array_values(array_unique($weaponKeys))),
        ];
    }

    /**
     * R1: a build of the file's found again. Per part: nobody edited it - the file's; the file did not change it - the
     * edit; the file says what the edit says - the edit, and what the file said before stays beside it, so the edit
     * stays one (an export of this site imported again changes nothing); both changed it - a question.
     *
     * @return array<string, mixed>
     */
    private static function found(array $row, array $theirs): array
    {
        $mine = BuildParts::of($row['build']);
        $base = BuildParts::of($row['base']);
        $file = BuildParts::of($theirs);
        $edited = BuildParts::differing($mine, $base);
        $changed = BuildParts::differing($file, $base);
        $both = array_values(array_intersect($edited, $changed));
        $conflicts = array_values(array_filter($both, fn(string $part) => $file[$part] !== $mine[$part]));
        $item = [
            'type' => 'found',
            'row' => $row,
            'theirs' => $theirs,
            'edited' => $edited,
            'changed' => $changed,
            'repeats' => array_values(array_diff($both, $conflicts)),
            'conflicts' => $conflicts,
            'taken' => array_values(array_diff($changed, $edited)),
        ];
        if ($conflicts !== []) {
            $item['id'] = 'b' . $row['id'];
            $item['question'] = self::question($item['id'], self::EDITED, $row['character'], $row['character_name'], $theirs, $conflicts, $item['taken']);
            $item['fingerprint'] = [$item['id'], self::EDITED, $conflicts, self::subset($mine, $conflicts), self::subset($file, $conflicts)];
        }

        return $item;
    }

    /**
     * R2: the file brings a build an editor made here. The same as it is: nothing changes - it stays the editor's.
     * Otherwise a question; either way the file's version stands beside it from then on.
     *
     * @return array<string, mixed>
     */
    private static function madeHere(array $row, array $theirs): array
    {
        $mine = BuildParts::of($row['build']);
        $file = BuildParts::of($theirs);
        $differing = BuildParts::differing($mine, $file);
        $item = ['type' => 'made_here', 'row' => $row, 'theirs' => $theirs, 'differing' => $differing];
        if ($differing !== []) {
            $item['id'] = 'b' . $row['id'];
            $item['question'] = self::question($item['id'], self::MADE_HERE, $row['character'], $row['character_name'], $theirs, $differing);
            $item['fingerprint'] = [$item['id'], self::MADE_HERE, $differing, self::subset($mine, $differing), self::subset($file, $differing)];
        }

        return $item;
    }

    /**
     * R3: the file brings a build an editor removed. As the file said it when it was removed: it stays removed, no
     * question. Changed: a question - kept removed unless bringing it back is chosen.
     *
     * @return array<string, mixed>
     */
    private static function removedHere(array $removal, array $theirs): array
    {
        $base = BuildParts::of($removal['base']);
        $file = BuildParts::of($theirs);
        $changed = BuildParts::differing($file, $base);
        $item = ['type' => 'removed_here', 'removal' => $removal, 'theirs' => $theirs, 'changed' => $changed];
        if ($changed !== []) {
            $item['id'] = 'r' . $removal['id'];
            $item['question'] = self::question($item['id'], self::REMOVED_HERE, $removal['character'], $removal['character_name'], $theirs, $changed);
            $item['fingerprint'] = [$item['id'], self::REMOVED_HERE, $changed, self::subset($file, $changed), self::subset($base, $changed)];
        }

        return $item;
    }

    /**
     * R4: a stored build the file lacks. Made here: kept. Edited, or with the site's notes: a question - kept, the
     * editor's from then on, unless removing it is chosen. Else removed.
     *
     * @return array<string, mixed>
     */
    private static function lacked(array $row): array
    {
        if ($row['base'] === null) {
            return ['type' => 'kept_here', 'row' => $row];
        }
        $mine = BuildParts::of($row['build']);
        $edited = BuildParts::differing($mine, BuildParts::of($row['base']));
        $notes = BuildNotes::any($row['notes']);
        if ($edited === [] && !$notes) {
            return ['type' => 'remove', 'row' => $row];
        }
        $id = 'b' . $row['id'];

        return [
            'type' => 'lacked',
            'row' => $row,
            'id' => $id,
            'question' => self::question($id, self::REMOVED_BY_FILE, $row['character'], $row['character_name'], $row['build'], $edited, notes: $notes),
            // The notes themselves, not only whether there are any: removing the build removes them, so one rewritten
            // after the preview makes another plan.
            'fingerprint' => [$id, self::REMOVED_BY_FILE, $edited, $notes ? hash('sha256', Json::encode($row['notes'])) : null, self::subset($mine, $edited)],
        ];
    }

    /**
     * A question as the answer lists it: which build, what kind, the parts it is about - and for an edited build the
     * parts the file's version is taken of without asking, for a build the file lacks whether it has notes.
     *
     * @param  array<string, mixed>  $build  a build with its variation
     * @param  list<string>  $parts
     * @param  list<string>  $taken
     * @return array<string, mixed>
     */
    private static function question(string $id, string $kind, string $key, string $name, array $build, array $parts, array $taken = [], bool $notes = false): array
    {
        return ['id' => $id, 'kind' => $kind] + self::variationOf($key, $name, $build) + ['parts' => $parts, 'taken' => $taken, 'notes' => $notes];
    }

    /**
     * @param  array<string, string>  $parts
     * @param  list<string>  $which
     * @return list<string>
     */
    private static function subset(array $parts, array $which): array
    {
        return array_map(fn(string $part) => $parts[$part], $which);
    }

    /**
     * The import's outcome for these choices - the questions whose ids are in $fileWins answered with the file, every
     * other with the edit: each build's new state and place (R7), and what the answer counts and lists.
     *
     * @param  list<string>  $fileWins
     * @return array<string, mixed>
     */
    private static function resolve(array $merge, array $fileWins): array
    {
        $wins = array_flip($fileWins);
        $ops = [];
        $removals = [];
        $merged = [];
        $taken = 0;
        foreach ($merge['items'] as $item) {
            $file = isset($item['id']) && isset($wins[$item['id']]);
            $taken += $file ? 1 : 0;
            switch ($item['type']) {
                case 'found':
                    $ops[] = self::foundOp($item, $file);
                    // The builds the file and an edit made one of without asking.
                    if (!isset($item['question']) && $item['edited'] !== [] && $item['taken'] !== []) {
                        $merged[] = self::variationOf($item['row']['character'], $item['row']['character_name'], $item['theirs']) + ['kept' => $item['edited'], 'taken' => $item['taken']];
                    }
                    break;
                case 'made_here':
                    $ops[] = self::madeHereOp($item, $file);
                    break;
                case 'removed_here':
                    if ($item['changed'] !== [] && $file) {
                        $ops[] = self::newOp($item['theirs'], $item['removal']);
                    } else {
                        // Kept removed - beside what the file says of it now, so the same file asks nothing again.
                        $removals[] = ['removal' => $item['removal'], 'theirs' => $item['theirs'], 'write' => $item['changed'] !== []];
                    }
                    break;
                case 'create':
                    $ops[] = self::newOp($item['theirs']);
                    break;
                case 'kept_here':
                    $ops[] = self::rowOp($item['row'], $item['row']['build'], null, carried: false);
                    break;
                case 'lacked':
                    $ops[] = $file ? ['op' => 'delete', 'row' => $item['row']] : self::rowOp($item['row'], $item['row']['build'], null, carried: false);
                    break;
                default:
                    $ops[] = ['op' => 'delete', 'row' => $item['row']];
            }
        }

        // R7: each character's builds in their order, then their places.
        $byCharacter = [];
        foreach ($ops as $op) {
            if ($op['op'] !== 'delete') {
                $byCharacter[$op['character_id']][] = $op;
            }
        }
        $positions = [];
        $orderKept = [];
        $orderSaid = [];
        foreach ($byCharacter as $characterId => $after) {
            $before = $merge['before'][$characterId] ?? [];
            // The editor's order: said so (build_orders) - or every build of the character the editor's: then the file's
            // first builds of it go after theirs, and the order is said to be theirs from then on, so the next file
            // cannot put its builds first.
            $own = $before !== [] && array_filter($before, fn(array $row) => $row['base'] !== null) === [];
            [$order, $kept] = self::order($before, $after, isset($merge['ordered'][$characterId]) || $own);
            foreach ($order as $i => $op) {
                $positions[$op['key']] = $i;
            }
            if ($kept) {
                $orderKept[] = ['key' => BuildFile::neutralKey($after[0]['character']), 'name' => (string) BuildFile::neutral($after[0]['character_name'], 160)];
            }
            if ($own && !isset($merge['ordered'][$characterId]) && array_filter($after, fn(array $op) => $op['carried']) !== []) {
                $orderSaid[] = $characterId;
            }
        }

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'edits_kept' => 0, 'made_here' => 0, 'kept_removed' => count($removals), 'file_taken' => $taken];
        $changes = [];
        $dormant = [];
        foreach ($ops as $i => $op) {
            if ($op['op'] === 'delete') {
                $counts['removed']++;
                $changes[] = self::change('removed', $op['row']['character'], $op['row']['character_name'], $op['row']['build']);

                continue;
            }
            $build = $op['build'];
            $build['position'] = $positions[$op['key']];
            $build['weapons'] = BuildRows::known($build['weapons'], $merge['weapons']);
            $ops[$i]['build'] = $build;
            $ops[$i]['columns'] = BuildRows::columns($build, $op['base']);
            if ($op['op'] === 'create') {
                $counts['created']++;
                $changes[] = self::change('created', $op['character'], $op['character_name'], $build);
                // A build brought back comes with its notes: a team's whose team the file no longer has waits, named.
                if ($op['removal'] !== null && ($waiting = BuildNotes::dormant(BuildNotes::read($op['removal']['site_notes']), $build['teams'])) !== []) {
                    $dormant[] = self::variationOf($op['character'], $op['character_name'], $build) + ['notes' => count($waiting)];
                }

                continue;
            }

            $row = $op['row'];
            $ops[$i]['write'] = $ops[$i]['columns'] !== BuildRows::columns($row['build'], $row['base']);
            $ops[$i]['visible'] =
                BuildParts::differing(BuildParts::of($row['build']), BuildParts::of($build)) !== [] ||
                $row['build']['qualifier'] !== $build['qualifier'] ||
                $row['build']['position'] !== $build['position'];
            if ($ops[$i]['visible']) {
                $counts['updated']++;
                $changes[] = self::change('updated', $row['character'], $row['character_name'], $build);
            } elseif ($op['carried']) {
                $counts['unchanged']++;
            }
            if (!$op['carried']) {
                $counts['made_here']++;
            } elseif (BuildParts::differing(BuildParts::of($build), BuildParts::of($op['base'])) !== []) {
                $counts['edits_kept']++;
            }
            // R8: notes are never touched - but a team's note whose team the import took away waits for one, and is named.
            $waiting = array_diff(BuildNotes::dormant($row['notes'], $build['teams']), BuildNotes::dormant($row['notes'], $row['build']['teams']));
            if ($waiting !== []) {
                $dormant[] = self::variationOf($row['character'], $row['character_name'], $build) + ['notes' => count($waiting)];
            }
        }
        // By character, then as they came: the file's order, the removed builds after.
        usort($changes, fn(array $a, array $b) => strcmp($a['character']['name'], $b['character']['name']) ?: strcmp($a['character']['key'], $b['character']['key']));

        return [
            'ops' => $ops,
            'removals' => $removals,
            'order_said' => $orderSaid,
            'counts' => $counts,
            'changes' => $changes,
            'merged' => $merged,
            'order_kept' => $orderKept,
            'notes_dormant' => $dormant,
        ];
    }

    /**
     * R1 resolved: each part from the edit or the file, and what the file said of it to keep beside it. The file's
     * spelling of the qualifier and its texts the site does not show (the build's notes) always.
     *
     * @return array<string, mixed>
     */
    private static function foundOp(array $item, bool $file): array
    {
        $build = $item['row']['build'];
        $base = $item['row']['base'];
        $theirs = $item['theirs'];
        foreach (BuildParts::PARTS as $part) {
            $edited = in_array($part, $item['edited'], true);
            if (!$edited || ($file && in_array($part, $item['conflicts'], true))) {
                $build[$part] = $theirs[$part];
            }
            // What the file said stays beside an edit the file repeats; any other part takes what it says now.
            if (!in_array($part, $item['repeats'], true)) {
                $base[$part] = $theirs[$part];
            }
        }
        $build['qualifier'] = $base['qualifier'] = $theirs['qualifier'];
        $build['notes'] = $base['notes'] = $theirs['notes'];
        $base['position'] = $theirs['position'];

        return self::rowOp($item['row'], $build, $base, carried: true, filePosition: $theirs['position']);
    }

    /**
     * R2 resolved: the editor's build or the file's, the file's version beside it from then on - unless the two are
     * the same: then nothing changes, and it stays the editor's.
     *
     * @return array<string, mixed>
     */
    private static function madeHereOp(array $item, bool $file): array
    {
        $row = $item['row'];
        if ($item['differing'] === []) {
            return self::rowOp($row, $row['build'], null, carried: false);
        }
        $theirs = $item['theirs'];
        $build = $row['build'];
        if ($file) {
            foreach (BuildParts::PARTS as $part) {
                $build[$part] = $theirs[$part];
            }
        }
        $build['qualifier'] = $theirs['qualifier'];
        $build['notes'] = $theirs['notes'];

        return self::rowOp($row, $build, self::stripped($theirs), carried: true, filePosition: $theirs['position']);
    }

    /**
     * A stored build as the import leaves it: its new state, the file's version beside it (null: made here), whether
     * the file carries it - and so where the file puts it.
     *
     * @return array<string, mixed>
     */
    private static function rowOp(array $row, array $build, ?array $base, bool $carried, int $filePosition = 0): array
    {
        return [
            'op' => 'keep',
            'key' => 'b' . $row['id'],
            'row' => $row,
            'character_id' => $row['character_id'],
            'character' => $row['character'],
            'character_name' => $row['character_name'],
            'build' => $build,
            'base' => $base,
            'carried' => $carried,
            'file_position' => $filePosition,
        ];
    }

    /**
     * A build the file brings that is not here: new (R5), or one an editor removed, brought back (R3) - with its notes.
     *
     * @param  ?array<string, mixed>  $removal
     * @return array<string, mixed>
     */
    private static function newOp(array $theirs, ?array $removal = null): array
    {
        return [
            'op' => 'create',
            'key' => 'n' . BuildFile::identity($theirs['character'], $theirs['role'], $theirs['qualifier'], $theirs['min_rank']),
            'removal' => $removal,
            'character_id' => $theirs['character_id'],
            'character' => $theirs['character'],
            'character_name' => $theirs['character_name'],
            'build' => self::stripped($theirs),
            'base' => self::stripped($theirs),
            'carried' => true,
            'file_position' => $theirs['position'],
        ];
    }

    /**
     * R7: the builds a character has after the import, in their order - the file's (its builds by the place it gives
     * them, then those made here, kept as they stood) unless the order is the editor's: then the editor's, the file's
     * new builds after them. Whether the editor's order was kept where the file's differs.
     *
     * @param  list<array<string, mixed>>  $before  the character's stored builds, in their order
     * @param  list<array<string, mixed>>  $after  the ops of the builds it keeps and gets
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private static function order(array $before, array $after, bool $editors): array
    {
        $place = [];
        foreach ($before as $i => $row) {
            $place['b' . $row['id']] = $i;
        }
        $carried = array_values(array_filter($after, fn(array $op) => $op['carried']));
        usort($carried, fn(array $a, array $b) => $a['file_position'] <=> $b['file_position']);
        $rest = array_values(array_filter($after, fn(array $op) => !$op['carried']));
        usort($rest, fn(array $a, array $b) => ($place[$a['key']] ?? PHP_INT_MAX) <=> ($place[$b['key']] ?? PHP_INT_MAX));
        $fileOrder = [...$carried, ...$rest];
        if (!$editors) {
            return [$fileOrder, false];
        }

        $existing = array_values(array_filter($after, fn(array $op) => isset($place[$op['key']])));
        usort($existing, fn(array $a, array $b) => $place[$a['key']] <=> $place[$b['key']]);
        $new = array_values(array_filter($after, fn(array $op) => !isset($place[$op['key']])));
        usort($new, fn(array $a, array $b) => $a['file_position'] <=> $b['file_position']);
        $order = [...$existing, ...$new];

        return [$order, array_column($order, 'key') !== array_column($fileOrder, 'key')];
    }

    /**
     * @param  array{exported_at: DateTimeImmutable, builds: list<array<string, mixed>>, skipped: list<array<string, mixed>>}  $prepared
     * @param  array<string, mixed>  $resolved  resolve()
     */
    private function write(Game $game, array $prepared, array $resolved, User $by): void
    {
        $now = Clock::now();
        $made = $prepared['exported_at'];

        // Removed first and created last: no identity is ever there twice, even for a moment - nor a removed build's
        // beside one brought back.
        $gone = array_map(fn(array $op) => $op['row']['id'], array_filter($resolved['ops'], fn(array $op) => $op['op'] === 'delete'));
        if ($gone !== []) {
            [$in, $params] = Db::in($gone);
            $this->db->run("DELETE FROM builds WHERE id IN ({$in})", $params);
        }
        $back = array_values(array_filter(array_map(fn(array $op) => $op['op'] === 'create' ? $op['removal']['id'] ?? null : null, $resolved['ops'])));
        if ($back !== []) {
            [$in, $params] = Db::in($back);
            $this->db->run("DELETE FROM build_removals WHERE id IN ({$in})", $params);
        }

        $checked = [];
        foreach ($resolved['ops'] as $op) {
            if ($op['op'] !== 'keep') {
                continue;
            }
            if (!$op['write']) {
                if ($op['carried']) {
                    $checked[] = $op['row']['id'];
                }

                continue;
            }
            $times = ['updated_at' => $now];
            if ($op['carried']) {
                $times['checked_at'] = $made;
            } elseif ($op['row']['base'] !== null) {
                // The editor's from now on: no file carries it.
                $times['checked_at'] = null;
            }
            if ($op['visible']) {
                $times['changed_at'] = max($op['row']['changed_at'] ?? $made, $made);
            }
            $this->db->update('builds', $op['columns'] + $times, ['id' => $op['row']['id']]);
        }
        if ($checked !== []) {
            [$in, $params] = Db::in($checked);
            $this->db->run("UPDATE builds SET checked_at = ?, updated_at = ? WHERE id IN ({$in})", [$made, $now, ...$params]);
        }
        foreach ($resolved['removals'] as $removal) {
            if ($removal['write']) {
                $this->db->update('build_removals', ['file_build' => Json::column(BuildRows::stored(self::stripped($removal['theirs'])))], ['id' => $removal['removal']['id']]);
            }
        }
        // A character whose builds were all the editor's, and which the file now brings builds of: their order, said.
        foreach ($resolved['order_said'] as $characterId) {
            $this->db->run('INSERT INTO build_orders (game_character_id, ordered_at) VALUES (?, ?) ON CONFLICT (game_character_id) DO NOTHING', [$characterId, $now]);
        }
        foreach ($resolved['ops'] as $op) {
            if ($op['op'] === 'create') {
                $this->db->insert(
                    'builds',
                    ['game_character_id' => $op['character_id']] +
                        $op['columns'] + [
                            // A build brought back comes with the notes it had.
                            'site_notes' => $op['removal']['site_notes'] ?? null,
                            'checked_at' => $made,
                            'changed_at' => $made,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                );
            }
        }

        $this->db->insert('build_imports', [
            'game' => $game->value,
            'file_exported_at' => $made,
            // What it did, and nobody's name: who imported it is imported_by, nobody's once their data is deleted.
            'summary' => Json::column(
                [
                    'characters' => count(array_unique(array_column($prepared['builds'], 'character_id'))),
                    'builds' => count($prepared['builds']),
                ] +
                    array_intersect_key($resolved['counts'], array_flip(['created', 'updated', 'unchanged', 'removed'])) + ['skipped' => count($prepared['skipped'])] +
                    array_intersect_key($resolved['counts'], array_flip(['edits_kept', 'made_here', 'kept_removed', 'file_taken'])),
            ),
            'imported_by' => $by->id > 0 ? $by->id : null,
            'created_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $merge  merge()
     * @param  array<string, mixed>  $resolved  resolve()
     * @return array<string, mixed>
     */
    private function answer(Game $game, array $prepared, array $merge, array $resolved, bool $dryRun): array
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
            'teams' => array_sum(array_map(fn(array $build) => count($build['teams']), $prepared['builds'])),
            ...$resolved['counts'],
            'skipped' => $prepared['skipped'],
            'teammates_unknown' => $prepared['teammates_unknown'],
            'teammates_unknown_total' => $prepared['teammates_unknown_total'],
            'questions' => $merge['questions'],
            'plan' => $merge['plan'],
            'merged' => $resolved['merged'],
            'order_kept' => $resolved['order_kept'],
            'notes_dormant' => $resolved['notes_dormant'],
            'changes' => array_slice($resolved['changes'], 0, self::MAX_CHANGES),
            'changes_listed' => min(count($resolved['changes']), self::MAX_CHANGES),
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
        return ['change' => $change] + self::variationOf($key, $name, $build);
    }

    /**
     * A build's character and variation as an answer names them.
     *
     * @param  array<string, mixed>  $build
     * @return array{character: array{key: string, name: string}, role: string, qualifier: string, min_rank: int}
     */
    private static function variationOf(string $key, string $name, array $build): array
    {
        return [
            'character' => ['key' => BuildFile::neutralKey($key), 'name' => (string) BuildFile::neutral($name, 160)],
            'role' => $build['role'],
            // A stored build's is the stored one, read before a name was added to the neutral list, maybe.
            'qualifier' => BuildFile::qualifierText($build['qualifier']),
            'min_rank' => $build['min_rank'],
        ];
    }

    /**
     * A file's build as it is stored: its variation, place and content, without its character's.
     *
     * @param  array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private static function stripped(array $build): array
    {
        return array_intersect_key($build, array_flip(['role', 'qualifier', 'min_rank', 'position', ...array_keys(BuildRows::content($build)), 'teams', 'notes']));
    }
}
