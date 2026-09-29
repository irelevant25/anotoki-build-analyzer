<?php

namespace App\Services\Builds;

use App\Cache\LockTimeout;
use App\Db\Db;
use App\Models\Build;
use App\Models\BuildSource as BuildSourceModel;
use App\Models\SyncRun;
use App\Models\User;
use App\Repositories\Builds;
use App\Repositories\CatalogRows;
use App\Repositories\SyncRuns;
use App\Services\Catalog\Catalog;
use App\Services\Sync\RunLog;
use App\Services\Sync\SyncRunner;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * A source's builds exported to a file and imported from one (BuildFile), on the Sources page.
 *
 * The owner's reason: some sites refuse the hosting's servers (bot protection that turns away datacenter
 * addresses) but answer a computer at home. Synced there and exported, a source's builds can be imported on
 * the site; for any source the file is also a backup.
 *
 * An import makes the source's builds exactly the file's. It is written the way a sync writes (BuildSync:
 * the same content, content hash, created / updated / unchanged, changed_at), recorded as a builds run of
 * the source, and it leaves the source as a successful full sync does - synced when the file's builds were
 * synced, so "Sync all" leaves it alone for fetch.stale_hours like any fresh source.
 */
final class BuildTransfer
{
    /**
     * The route's time limit for an import. The step lock it holds lasts a minute longer, so in practice no
     * step starts while an import still writes - not a guarantee, since on Linux the limit counts the
     * script's own time and not its waits for the database. An import of a whole source takes seconds.
     */
    public const MAX_SECONDS = 240;

    public function __construct(
        private readonly BuildSourceRegistry $registry,
        private readonly BuildSync $sync,
        private readonly Builds $builds,
        private readonly CatalogRows $catalog,
        private readonly SyncRuns $runs,
        private readonly SyncRunner $runner,
        private readonly Db $db,
    ) {}

    /**
     * The file of a source's builds, ordered by character key, position and variant - compared as bytes,
     * whatever the database's collation - so two exports of the same builds differ in exported_at only.
     *
     * @return array<string, mixed>
     */
    public function export(BuildSource $source): array
    {
        $model = $this->registry->model($source);
        $builds = $this->builds->ofSource($model->id);
        usort($builds, fn (Build $a, Build $b) => strcmp($a->character->key, $b->character->key)
            ?: $a->position <=> $b->position
            ?: strcmp($a->variant, $b->variant));

        // Neutral words can make two variants of a character alike ("Prydwen DPS", "Game8 DPS"), and a file
        // holds each (character, variant) once: the later one becomes "… #2", as sources name a repeated one.
        $entries = [];
        $taken = [];
        foreach ($builds as $build) {
            $entry = BuildFile::entry($build);
            $variant = $entry['variant'];
            for ($n = 2; isset($taken[$entry['character']][$variant]); $n++) {
                $variant = BuildFile::neutral($entry['variant'], BuildFile::MAX_VARIANT - strlen(" #{$n}"))." #{$n}";
            }
            $taken[$entry['character']][$variant] = true;
            $entry['variant'] = $variant;
            $entries[] = $entry;
        }

        return [
            'format' => BuildFile::FORMAT,
            'format_version' => BuildFile::VERSION,
            'source' => $this->registry->publicKey($source->key()),
            'game' => $source->game()->value,
            'exported_at' => BuildFile::time(Clock::now()),
            // When the builds were last synced in full; a failed or partial sync says nothing of that.
            'synced_at' => $model->last_status === 'success' ? BuildFile::time($model->last_synced_at) : null,
            'builds' => $entries,
        ];
    }

    /**
     * Makes the source's builds exactly the file's, in one transaction: each build is created, updated or
     * kept as a sync would, and every build of the source the file does not have is removed. Characters this
     * installation's catalog lacks are skipped (an import never adds to the catalog) and named in the answer.
     *
     * @param  mixed  $file  the decoded JSON of the file
     * @return array{created: int, updated: int, unchanged: int, removed: int, characters: int, skipped: list<array{key: string, name: string}>}
     *
     * @throws BuildFileException the file does not fit this source; nothing was written
     * @throws SourceSyncing a sync of the source is going
     * @throws LockTimeout a step is still running after a few seconds' wait
     */
    public function import(BuildSource $source, mixed $file, User $by): array
    {
        $label = $this->registry->label($source->key());
        $read = BuildFile::read($file, $source->game(), (string) $this->registry->publicKey($source->key()), $label);
        $model = $this->registry->model($source);

        // Under the step lock no step writes meanwhile, and a sync of this source that is going (even one
        // paused in a closed tab) would carry on over the file's builds at its next step, so it must end first.
        return $this->runner->exclusively(function () use ($source, $model, $read, $by, $label) {
            if ($this->runs->activeRun(SyncRun::BUILDS, $source->game(), $model->id)) {
                throw new SourceSyncing("{$label} is being synced. Let that sync finish or cancel it, then import.");
            }

            return $this->db->transaction(fn () => $this->replace($source, $model, $read, $by));
        }, self::MAX_SECONDS + 60);
    }

    /**
     * @param  array{exported_at: DateTimeImmutable, synced_at: DateTimeImmutable|null, builds: list<array<string, mixed>>}  $read
     */
    private function replace(BuildSource $source, BuildSourceModel $model, array $read, User $by): array
    {
        $characters = $this->catalog->byKeys($source->game(), Catalog::CHARACTER, array_column($read['builds'], 'character'));
        if ($read['builds'] !== [] && $characters === []) {
            // Most likely a catalog nobody refreshed yet; "importing" nothing would still remove every build.
            throw new BuildFileException("None of the file's characters is in this installation's catalog. Refresh the catalog, then import again.");
        }

        // Times from another computer's clock are never later than now here.
        $now = Clock::now();
        $exported = min($read['exported_at'], $now);
        $synced = $read['synced_at'] === null ? null : min($read['synced_at'], $now);

        $run = new SyncRun(id: null, kind: SyncRun::BUILDS, game: $source->game(), build_source_id: $model->id,
            status: SyncRun::RUNNING, started_at: $now, started_by: $by->id, source: $model, starter: $by);
        $this->sync->begin($run);
        $log = new RunLog($run);
        $log->add("Started by {$by->name}.");
        $log->add(sprintf('%s: imported from a file exported %s%s, %d build(s).', $this->registry->title($source),
            self::when($exported), $synced ? ' (synced there '.self::when($synced).')' : '', count($read['builds'])));
        $this->runs->insert($run);

        $byCharacter = [];
        foreach ($read['builds'] as $build) {
            $byCharacter[$build['character']][] = $build;
        }

        // The file never carries a page's address. A build this installation has already keeps the one it
        // has, and is hashed with it as a sync hashes it: importing what a sync stored here changes nothing,
        // and the next sync does not count every imported build as changed.
        $addresses = [];
        foreach ($this->db->all('SELECT game_character_id, variant, source_url FROM builds WHERE build_source_id = ? AND source_url IS NOT NULL', [$model->id]) as $row) {
            $addresses[(int) $row['game_character_id']][$row['variant']] = $row['source_url'];
        }

        $stats = $run->stats;
        $stats['characters_listed'] = count($byCharacter);
        $skipped = [];
        $written = [];
        foreach ($byCharacter as $key => $builds) {
            $character = $characters[$key] ?? null;
            if ($character === null) {
                $stats['characters_skipped']++;
                // The name is neutral already (BuildFile reads every text so); the key is only matched.
                $skipped[] = ['key' => BuildFile::neutralKey((string) $key), 'name' => $builds[0]['name']];
                $log->add(sprintf('  ! %s (%s): not in the catalog, %d build(s) skipped. Refresh the catalog, then import again.', $builds[0]['name'], $key, count($builds)));

                continue;
            }

            $counts = $this->sync->write($model, $character->id, array_map(fn (array $build) => [
                'variant' => $build['variant'],
                'data' => BuildSync::content(
                    role: $build['role'],
                    recommended: $build['recommended'],
                    position: $build['position'],
                    weapons: $build['weapons'],
                    sets: $build['sets'],
                    mainStats: $build['main_stats'],
                    substats: $build['substats'],
                    talentPriority: $build['talent_priority'],
                    statTargets: $build['stat_targets'],
                    notes: $build['notes'],
                    sourceUrl: $addresses[$character->id][$build['variant']] ?? null,
                    sourceUpdatedAt: $build['source_updated_at'],
                    gameVersion: $build['game_version'],
                ),
                'fetched_at' => min($build['fetched_at'] ?? $now, $now),
                'changed_at' => min($build['changed_at'] ?? $now, $now),
            ], $builds));

            foreach ($counts as $name => $count) {
                $stats[$name] += $count;
            }
            $stats['characters_synced']++;
            $written[] = $character->id;
            $log->add(sprintf('  %s: %d build(s)%s', $character->name, count($builds),
                $counts['builds_created'] + $counts['builds_updated'] + $counts['builds_removed'] > 0 ? ' - changed' : ''));
        }

        // The file is the whole truth for the source: characters it does not have keep no builds of it.
        [$in, $params] = Db::in($written);
        $others = $written === [] ? '' : " AND b.game_character_id NOT IN ({$in})";
        $gone = $this->db->all("SELECT c.name, count(*) AS builds FROM builds b JOIN game_characters c ON c.id = b.game_character_id
            WHERE b.build_source_id = ?{$others} GROUP BY c.id, c.name ORDER BY c.name", [$model->id, ...$params]);
        foreach ($gone as $row) {
            $log->add("  {$row['name']}: not in the file, {$row['builds']} build(s) removed.");
        }
        $stats['builds_removed'] += $this->db->run("DELETE FROM builds b WHERE b.build_source_id = ?{$others}", [$model->id, ...$params]);

        if ($skipped !== []) {
            $stats['unmatched'] = [$source->game()->value.':'.Catalog::CHARACTER => array_column($skipped, 'name')];
        }
        $run->stats = $stats;
        $this->sync->finish($run, $model, $log, syncedAt: $synced ?? $exported);

        return [
            'created' => $stats['builds_created'],
            'updated' => $stats['builds_updated'],
            'unchanged' => $stats['builds_unchanged'],
            'removed' => $stats['builds_removed'],
            'characters' => $stats['characters_synced'],
            'skipped' => $skipped,
        ];
    }

    /** "2026-09-29 14:03 UTC", for the run's log. */
    private static function when(DateTimeImmutable $time): string
    {
        return $time->setTimezone(Clock::utc())->format('Y-m-d H:i').' UTC';
    }
}
