<?php

namespace App\Services\Builds;

use App\Config;
use App\Db\Db;
use App\Enums\Game;
use App\Http\FetchDeferred;
use App\Models\BuildSource as BuildSourceModel;
use App\Models\GameCharacter;
use App\Models\SyncRun;
use App\Repositories\BuildSources;
use App\Repositories\SyncRuns;
use App\Services\Catalog\Catalog;
use App\Services\Sync\RunHandler;
use App\Services\Sync\RunLog;
use App\Support\Clock;
use App\Support\Json;
use App\Support\Key;
use App\Support\Log;
use App\Support\Text;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Scrapes one build source and stores the normalized builds, as a run (kind "builds") worked through in
 * steps: first the site's character list, then one character after another, saving after each, so a
 * run can pause between any two characters and continue in a later request (see App\Services\Sync).
 *
 * A build is identified by (source, character, variant). Unchanged builds only get fetched_at bumped;
 * changed ones also get changed_at, so the UI can show "updated recently". Variants that disappear
 * from the site are deleted.
 *
 * Builds imported from a file (BuildTransfer) are stored by the same content(), write() and finish(), so
 * an import creates, updates and hashes builds exactly as a sync does and ends its run the same way.
 */
class BuildSync implements RunHandler
{
    /** @var list<string> "! unknown weapon/set" lines from buildData() for the character in hand */
    private array $unknown = [];

    public function __construct(
        private readonly Catalog $catalog,
        private readonly BuildSourceRegistry $registry,
        private readonly Db $db,
        private readonly SyncRuns $runs,
        private readonly BuildSources $sources,
        private readonly Config $config,
    ) {}

    public function isFresh(BuildSourceModel $model): bool
    {
        $hours = (int) $this->config->get('fetch.stale_hours', 20);

        return $model->last_status === 'success'
            && $model->last_synced_at !== null
            && $model->last_synced_at->getTimestamp() > Clock::timestamp() - $hours * 3600;
    }

    /**
     * state.options: force, only (character slugs or names; others are left untouched), limit (stop after
     * this many characters with builds). A run with only / limit never marks the source as synced.
     */
    public function begin(SyncRun $run): void
    {
        $run->state = [
            'options' => $run->state['options'] ?? [],
            'phase' => 'list',
            'todo' => [],
            'done' => 0,
            'total' => null,
        ];
        $run->stats = [
            'characters_listed' => 0, 'characters_synced' => 0, 'characters_skipped' => 0, 'characters_without_builds' => 0,
            'builds_created' => 0, 'builds_updated' => 0, 'builds_unchanged' => 0, 'builds_removed' => 0, 'errors' => 0,
            'unmatched' => [],
        ];
    }

    public function step(SyncRun $run, float $deadline, RunLog $log): void
    {
        $model = $run->source ?? $this->sources->find((int) $run->build_source_id)
            ?? throw new \RuntimeException('The run has no build source');
        $source = $this->registry->get($model->key);
        $this->catalog->resetUnmatched();

        if ($run->state['phase'] === 'list' && ! $this->listCharacters($run, $source, $model, $log)) {
            return;
        }

        $state = $run->state;
        $stats = $run->stats;
        $limit = $state['options']['limit'] ?? null;

        while ($state['done'] < $state['total'] && ($limit === null || $stats['characters_synced'] < $limit)) {
            if (Clock::microtime() > $deadline - 1) {
                return;
            }

            $ref = CharacterRef::fromArray($state['todo'][$state['done']]);
            try {
                $builds = $source->fetchBuilds($ref);
                if ($builds === []) {
                    $stats['characters_without_builds']++;
                    $log->add("  {$ref->name}: no builds on this site.");
                } else {
                    $this->unknown = [];
                    $result = $this->store($source, $model, $ref, $builds);
                    // "! unknown ..." lines come before the line of the character they belong to.
                    foreach ($this->unknown as $line) {
                        $log->add($line);
                    }
                    foreach ($result as $key => $count) {
                        $stats[$key] += $count;
                    }
                    $stats['characters_synced']++;
                    $log->add(sprintf('  %s: %d build(s)%s', $ref->name, count($builds),
                        $result['builds_created'] + $result['builds_updated'] > 0 ? ' - changed' : ''));
                }
            } catch (FetchDeferred $e) {
                // Nothing of this character was written; the next step starts with it.
                throw $e;
            } catch (Throwable $e) {
                $stats['errors']++;
                $log->add("  {$ref->name}: ERROR ".Text::limit($e->getMessage(), 300));
                Log::exception($e, "Build sync of {$model->key}, {$ref->name}");
            }

            $stats['unmatched'] = $this->mergeUnmatched($stats['unmatched'], $this->catalog->unmatched());
            $this->catalog->resetUnmatched();
            $state['done']++;
            $run->state = $state;
            $run->stats = $stats;
            $this->runs->save($run);
        }

        $this->finish($run, $model, $log);
    }

    /**
     * The list phase: fetch the site's characters and keep the released ones this run covers.
     *
     * @return bool false when the run ended here (the list could not be read)
     */
    private function listCharacters(SyncRun $run, BuildSource $source, BuildSourceModel $model, RunLog $log): bool
    {
        $state = $run->state;
        $stats = $run->stats;

        try {
            $refs = $source->listCharacters();
        } catch (FetchDeferred $e) {
            throw $e;
        } catch (Throwable $e) {
            $stats['errors']++;
            $log->add('Sync failed: '.$e->getMessage());
            Log::exception($e, "Build sync of {$model->key}, character list");
            $run->stats = $stats;
            $this->finish($run, $model, $log, 'failed');

            return false;
        }

        $only = array_map(fn ($value) => Key::from((string) $value), $state['options']['only'] ?? []);
        $todo = [];
        foreach ($refs as $ref) {
            if ($only !== [] && ! in_array(Key::from($ref->slug), $only, true) && ! in_array(Key::from($ref->name), $only, true)) {
                continue;
            }
            if (! $ref->released) {
                $stats['characters_skipped']++;

                continue;
            }
            $todo[] = $ref->toArray();
        }

        $stats['characters_listed'] = count($refs);
        $log->add("{$this->registry->title($source)}: {$stats['characters_listed']} characters listed.");
        $run->state = ['phase' => 'characters', 'todo' => $todo, 'done' => 0, 'total' => count($todo)] + $state;
        $run->stats = $stats;
        $this->runs->save($run);

        return true;
    }

    /**
     * Close the run and, for a full run, record the outcome on the source: synced now - or, for builds
     * imported from a file, when the file's builds were synced, so "Sync all" judges their age and not the
     * import's.
     */
    public function finish(SyncRun $run, BuildSourceModel $model, RunLog $log, ?string $status = null, ?DateTimeImmutable $syncedAt = null): void
    {
        $stats = $run->stats;
        $status ??= match (true) {
            $stats['errors'] === 0 => 'success',
            $stats['characters_synced'] > 0 => 'partial',
            default => 'failed',
        };

        $log->add(sprintf('Done: %s. %d created, %d updated, %d unchanged, %d removed, %d errors.',
            $status, $stats['builds_created'], $stats['builds_updated'], $stats['builds_unchanged'], $stats['builds_removed'], $stats['errors']));

        $options = $run->state['options'] ?? [];
        $partialRun = ! empty($options['only']) || ($options['limit'] ?? null) !== null;
        $stats['partial_run'] = $partialRun;

        $run->status = $status;
        $run->finished_at = Clock::now();
        $run->stats = $stats;
        $run->state = null;
        $this->runs->save($run);

        // A run restricted to a few characters must not make a full sync think it is done.
        if ($partialRun) {
            return;
        }

        $errors = array_filter(explode("\n", (string) $run->log), fn ($line) => str_contains($line, 'ERROR') || str_contains($line, 'failed'));
        $this->sources->update($model, [
            'last_synced_at' => $syncedAt ?? Clock::now(),
            'last_status' => $status,
            'last_error' => $status === 'success' ? null : Text::limit(implode("\n", $errors), 2000),
        ]);
    }

    /**
     * @param  array<string, list<string>>  $into
     * @param  array<string, list<string>>  $more
     * @return array<string, list<string>>
     */
    private function mergeUnmatched(array $into, array $more): array
    {
        foreach ($more as $bucket => $names) {
            $into[$bucket] = array_values(array_unique([...($into[$bucket] ?? []), ...$names]));
        }

        return $into;
    }

    /**
     * @param  list<ScrapedBuild>  $builds
     * @return array{builds_created: int, builds_updated: int, builds_unchanged: int, builds_removed: int}
     */
    private function store(BuildSource $source, BuildSourceModel $model, CharacterRef $ref, array $builds): array
    {
        $game = $source->game();
        $character = $this->catalog->character($game, $ref->name, array_filter([
            'element' => $ref->meta['element'] ?? null,
        ]));
        if (! $character instanceof GameCharacter) {
            throw new \RuntimeException("Could not resolve character {$ref->name}");
        }

        $rows = [];
        foreach ($builds as $position => $scraped) {
            $rows[] = ['variant' => $scraped->variant, 'data' => $this->buildData($game, $scraped, $position)];
        }

        return $this->write($model, $character->id, $rows);
    }

    /**
     * Stores the builds of one character of a source, in place of what the source had for that character: a
     * new variant is created, a stored one is updated when its content hash differs (changed_at moves) or
     * kept (only fetched_at moves), and a variant missing from $builds is deleted. A sync passes what it
     * read just now; an import passes each build with the times its file gives.
     *
     * @param  list<array{variant: string, data: array<string, mixed>, fetched_at?: DateTimeImmutable, changed_at?: DateTimeImmutable}>  $builds  data as content() makes it
     * @return array{builds_created: int, builds_updated: int, builds_unchanged: int, builds_removed: int}
     */
    public function write(BuildSourceModel $model, int $characterId, array $builds): array
    {
        $result = ['builds_created' => 0, 'builds_updated' => 0, 'builds_unchanged' => 0, 'builds_removed' => 0];

        $this->db->transaction(function () use ($builds, $model, $characterId, &$result) {
            $variants = [];
            $now = Clock::now();
            foreach ($builds as $build) {
                $variants[] = $build['variant'];

                $hash = $this->contentHash($build['data']);
                $columns = $this->columns($build['data']) + ['content_hash' => $hash, 'fetched_at' => $build['fetched_at'] ?? $now, 'updated_at' => $now];

                $existing = $this->db->one(
                    'SELECT id, content_hash FROM builds WHERE build_source_id = ? AND game_character_id = ? AND variant = ?',
                    [$model->id, $characterId, $build['variant']],
                );

                if ($existing === null) {
                    $result['builds_created']++;
                    $this->db->insert('builds', [
                        'build_source_id' => $model->id,
                        'game_character_id' => $characterId,
                        'variant' => $build['variant'],
                        'changed_at' => $build['changed_at'] ?? $now,
                        'created_at' => $now,
                    ] + $columns);

                    continue;
                }

                if ($existing['content_hash'] !== $hash) {
                    $result['builds_updated']++;
                    $columns['changed_at'] = $build['changed_at'] ?? $now;
                } else {
                    $result['builds_unchanged']++;
                }
                $this->db->update('builds', $columns, ['id' => (int) $existing['id']]);
            }

            [$in, $params] = Db::in($variants);
            $result['builds_removed'] = $this->db->run(
                "DELETE FROM builds WHERE build_source_id = ? AND game_character_id = ? AND variant NOT IN ({$in})",
                [$model->id, $characterId, ...$params],
            );
        });

        return $result;
    }

    /**
     * Parse one character without writing anything and compare the result with the stored builds
     * (used by `sync-builds.php --dry-run` to check parsers against the page cache).
     *
     * @return array{character: string|null, builds: list<array{variant: string, status: string}>, removed: list<string>, unknown: list<string>}
     */
    public function preview(BuildSource $source, CharacterRef $ref): array
    {
        $game = $source->game();
        $model = $this->sources->byKey($source->key());
        $character = $this->catalog->find($game, Catalog::CHARACTER, $ref->name);

        $stored = [];
        if ($model && $character) {
            foreach ($this->db->all('SELECT variant, content_hash FROM builds WHERE build_source_id = ? AND game_character_id = ?', [$model->id, $character->id]) as $row) {
                $stored[$row['variant']] = $row['content_hash'];
            }
        }

        $this->unknown = [];
        $rows = [];
        foreach (array_values($source->fetchBuilds($ref)) as $position => $scraped) {
            $hash = $this->contentHash($this->buildData($game, $scraped, $position));
            $rows[] = [
                'variant' => $scraped->variant,
                'status' => match (true) {
                    ! isset($stored[$scraped->variant]) => 'new',
                    $stored[$scraped->variant] === $hash => 'unchanged',
                    default => 'changed',
                },
            ];
        }

        // Unknown weapon / set names were collected by buildData(); hand them back.
        $unknown = array_map(fn ($line) => trim(Text::after($line, '! ')), $this->unknown);
        $this->unknown = [];

        return [
            'character' => $character?->key,
            'builds' => $rows,
            'removed' => array_values(array_diff(array_keys($stored), array_column($rows, 'variant'))),
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * The normalized columns stored for one build (weapon / set names resolved against the catalog).
     *
     * @return array<string, mixed>
     */
    private function buildData(Game $game, ScrapedBuild $scraped, int $position): array
    {
        return self::content(
            role: $scraped->role,
            recommended: $scraped->recommended,
            position: $position,
            weapons: $this->weapons($game, $scraped),
            sets: $this->sets($game, $scraped),
            mainStats: $scraped->mainStats,
            substats: $scraped->substats,
            talentPriority: $scraped->talents,
            statTargets: $scraped->statTargets,
            notes: $scraped->notes,
            sourceUrl: $scraped->sourceUrl,
            sourceUpdatedAt: $scraped->updatedAt,
            gameVersion: $scraped->gameVersion,
        );
    }

    /**
     * What is stored of a build, in the order its content hash reads it (the hash is of this array's JSON).
     * Syncs and imports both put a build together here, so the same content hashes alike whichever way it
     * came. A file never carries the page address: an import passes the one this installation has for the
     * build already, or none for a new one.
     *
     * @param  list<array<string, mixed>>  $weapons
     * @param  list<array<string, mixed>>  $sets
     * @param  array<string, list<array{stat: string, priority: int}>>  $mainStats
     * @param  list<array{stat: string, priority: int}>  $substats
     * @param  list<array{talent: string, priority: int}>  $talentPriority  stored as null when empty
     * @param  list<array{stat: string, min: int|float|null, max: int|float|null, text: string}>  $statTargets  stored as null when empty
     * @return array<string, mixed>
     */
    public static function content(
        ?string $role,
        bool $recommended,
        int $position,
        array $weapons,
        array $sets,
        array $mainStats,
        array $substats,
        array $talentPriority,
        array $statTargets,
        ?string $notes,
        ?string $sourceUrl,
        ?DateTimeInterface $sourceUpdatedAt,
        ?string $gameVersion,
    ): array {
        return [
            'role' => $role,
            'recommended' => $recommended,
            'position' => $position,
            'weapons' => $weapons,
            'sets' => $sets,
            'main_stats' => $mainStats,
            'substats' => $substats,
            'talent_priority' => $talentPriority ?: null,
            'stat_targets' => $statTargets ?: null,
            'notes' => $notes,
            'source_url' => $sourceUrl,
            'source_updated_at' => $sourceUpdatedAt,
            'game_version' => $gameVersion,
        ];
    }

    /**
     * The hash that tells a changed build from an unchanged one. It must stay what the first version of the
     * app computed, or every stored build would count as changed once: that version's dates were Carbon
     * objects, which JSON-encode as "2026-09-19T00:00:00.000000Z", so a date is written that way here.
     *
     * @param  array<string, mixed>  $data
     */
    private function contentHash(array $data): string
    {
        if ($data['source_updated_at'] instanceof DateTimeInterface) {
            $data['source_updated_at'] = \DateTimeImmutable::createFromInterface($data['source_updated_at'])
                ->setTimezone(Clock::utc())->format('Y-m-d\TH:i:s.u\Z');
        }

        return hash('sha256', json_encode($data));
    }

    /**
     * buildData() as table columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        foreach (['weapons', 'sets', 'main_stats', 'substats', 'talent_priority', 'stat_targets'] as $column) {
            $data[$column] = Json::column($data[$column]);
        }

        return $data;
    }

    private function weapons(Game $game, ScrapedBuild $scraped): array
    {
        $weapons = [];
        foreach (array_values($scraped->weapons) as $index => $weapon) {
            $found = $this->catalog->find($game, Catalog::WEAPON, $weapon['name']);
            if (! $found) {
                $this->unknown[] = "  ! unknown weapon: {$weapon['name']}";
            }
            $weapons[] = [
                'key' => $found?->key ?? Key::from($weapon['name']),
                'name' => $found?->name ?? $weapon['name'],
                'rank' => $index + 1,
                'refinement' => $weapon['refinement'] ?? null,
                'note' => $weapon['note'] ?? null,
                'known' => $found !== null,
            ];
        }

        return $weapons;
    }

    private function sets(Game $game, ScrapedBuild $scraped): array
    {
        $options = [];
        foreach (array_values($scraped->sets) as $index => $option) {
            $pieces = [];
            foreach ($option['pieces'] as $piece) {
                $keys = [];
                $names = [];
                foreach ($piece['names'] as $name) {
                    $found = $this->catalog->find($game, Catalog::SET, $name);
                    if (! $found) {
                        $this->unknown[] = "  ! unknown set: {$name}";
                    }
                    $keys[] = $found?->key ?? Key::from($name);
                    $names[] = $found?->name ?? $name;
                }
                $pieces[] = [
                    'label' => count($names) === 1 ? $names[0] : $piece['label'],
                    'count' => $piece['count'],
                    'keys' => array_values(array_unique($keys)),
                    'names' => array_values(array_unique($names)),
                ];
            }

            $options[] = [
                'rank' => $index + 1,
                'kind' => $option['kind'],
                'choose' => $option['choose'] ?? null,
                'pieces' => $pieces,
                'note' => $option['note'] ?? null,
            ];
        }

        return $options;
    }
}
