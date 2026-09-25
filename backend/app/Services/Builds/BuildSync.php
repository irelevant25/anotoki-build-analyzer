<?php

namespace App\Services\Builds;

use App\Enums\Game;
use App\Models\Build;
use App\Models\BuildSource as BuildSourceModel;
use App\Models\GameCharacter;
use App\Models\SyncRun;
use App\Services\Catalog\Catalog;
use App\Support\Key;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scrapes one build source and stores the normalized builds.
 *
 * A build is identified by (source, character, variant). Unchanged builds only get fetched_at bumped;
 * changed ones also get changed_at, so the UI can show "updated recently". Variants that disappear
 * from the site are deleted.
 */
class BuildSync
{
    /** @var list<string> */
    private array $log = [];

    private ?Closure $output = null;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly BuildSourceRegistry $registry,
    ) {}

    /**
     * Receive log lines as they happen (used by the console command).
     */
    public function onOutput(?Closure $output): self
    {
        $this->output = $output;

        return $this;
    }

    public function isFresh(BuildSourceModel $model): bool
    {
        return $model->last_status === 'success'
            && $model->last_synced_at !== null
            && $model->last_synced_at->gt(now()->subHours((int) config('hoyo.sync.stale_hours')));
    }

    /**
     * @param  array{force?: bool, only?: list<string>, limit?: int|null}  $options
     *                                                                               only: character slugs or names to sync (others are left untouched)
     */
    public function sync(BuildSource $source, array $options = []): ?SyncRun
    {
        $model = $this->registry->model($source);

        if (! ($options['force'] ?? false) && empty($options['only']) && $this->isFresh($model)) {
            $this->say("{$source->name()} ({$source->game()->label()}) was synced {$model->last_synced_at->diffForHumans()}, skipping.");

            return null;
        }

        $lock = Cache::lock('builds-sync:'.$source->key(), 3 * 3600);
        if (! $lock->get()) {
            $this->say("{$source->name()} is already being synced by another process, skipping.");

            return null;
        }

        try {
            return $this->run($source, $model, $options);
        } finally {
            $lock->release();
        }
    }

    private function run(BuildSource $source, BuildSourceModel $model, array $options): SyncRun
    {
        $this->log = [];
        $this->catalog->resetUnmatched();

        $run = SyncRun::query()->create([
            'build_source_id' => $model->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $stats = [
            'characters_listed' => 0, 'characters_synced' => 0, 'characters_skipped' => 0, 'characters_without_builds' => 0,
            'builds_created' => 0, 'builds_updated' => 0, 'builds_unchanged' => 0, 'builds_removed' => 0, 'errors' => 0,
        ];

        try {
            $refs = $source->listCharacters();
            $stats['characters_listed'] = count($refs);
            $this->say("{$source->name()} ({$source->game()->label()}): {$stats['characters_listed']} characters listed.");

            $only = array_map(fn ($value) => Key::from($value), $options['only'] ?? []);
            $limit = $options['limit'] ?? null;

            foreach ($refs as $ref) {
                if ($only !== [] && ! in_array(Key::from($ref->slug), $only, true) && ! in_array(Key::from($ref->name), $only, true)) {
                    continue;
                }
                if (! $ref->released) {
                    $stats['characters_skipped']++;

                    continue;
                }
                if ($limit !== null && $stats['characters_synced'] >= $limit) {
                    break;
                }

                try {
                    $builds = $source->fetchBuilds($ref);
                    if ($builds === []) {
                        $stats['characters_without_builds']++;
                        $this->say("  {$ref->name}: no builds on this site.");

                        continue;
                    }

                    $result = $this->store($source, $model, $ref, $builds);
                    foreach ($result as $key => $count) {
                        $stats[$key] += $count;
                    }
                    $stats['characters_synced']++;
                    $this->say(sprintf('  %s: %d build(s)%s', $ref->name, count($builds),
                        $result['builds_created'] + $result['builds_updated'] > 0 ? ' - changed' : ''));
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $this->say("  {$ref->name}: ERROR ".Str::limit($e->getMessage(), 300));
                    report($e);
                }
            }

            $status = match (true) {
                $stats['errors'] === 0 => 'success',
                $stats['characters_synced'] > 0 => 'partial',
                default => 'failed',
            };
        } catch (Throwable $e) {
            $status = 'failed';
            $stats['errors']++;
            $this->say('Sync failed: '.$e->getMessage());
            report($e);
        }

        $unmatched = [];
        foreach ($this->catalog->unmatched() as $bucket => $names) {
            $unmatched[$bucket] = array_values(array_unique($names));
        }
        $stats['unmatched'] = $unmatched;

        $this->say(sprintf('Done: %s. %d created, %d updated, %d unchanged, %d removed, %d errors.',
            $status, $stats['builds_created'], $stats['builds_updated'], $stats['builds_unchanged'], $stats['builds_removed'], $stats['errors']));

        $partialRun = ! empty($options['only']) || ($options['limit'] ?? null) !== null;
        $stats['partial_run'] = $partialRun;

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'stats' => $stats,
            'log' => Str::limit(implode("\n", $this->log), 60000),
        ]);

        // A run restricted to a few characters must not make the daily full sync think it is done.
        if ($partialRun) {
            return $run;
        }

        $model->update([
            'last_synced_at' => now(),
            'last_status' => $status,
            'last_error' => $status === 'success' ? null : Str::limit(implode("\n", array_filter($this->log, fn ($line) => str_contains($line, 'ERROR') || str_contains($line, 'failed'))), 2000),
        ]);

        return $run;
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

        $result = ['builds_created' => 0, 'builds_updated' => 0, 'builds_unchanged' => 0, 'builds_removed' => 0];

        DB::transaction(function () use ($builds, $model, $character, $game, &$result) {
            $variants = [];
            foreach ($builds as $position => $scraped) {
                $variants[] = $scraped->variant;

                $data = $this->buildData($game, $scraped, $position);
                $hash = $this->contentHash($data);

                $build = Build::query()->firstOrNew([
                    'build_source_id' => $model->id,
                    'game_character_id' => $character->id,
                    'variant' => $scraped->variant,
                ]);

                if (! $build->exists) {
                    $result['builds_created']++;
                    $build->changed_at = now();
                } elseif ($build->content_hash !== $hash) {
                    $result['builds_updated']++;
                    $build->changed_at = now();
                } else {
                    $result['builds_unchanged']++;
                }

                $build->fill($data + ['content_hash' => $hash, 'fetched_at' => now()])->save();
            }

            $result['builds_removed'] = Build::query()
                ->where('build_source_id', $model->id)
                ->where('game_character_id', $character->id)
                ->whereNotIn('variant', $variants)
                ->delete();
        });

        return $result;
    }

    /**
     * Parse one character without writing anything and compare the result with the stored builds
     * (used by `builds:sync --dry-run` to check parsers against the page cache).
     *
     * @return array{character: string|null, builds: list<array{variant: string, status: string}>, removed: list<string>, unknown: list<string>}
     */
    public function preview(BuildSource $source, CharacterRef $ref): array
    {
        $game = $source->game();
        $model = BuildSourceModel::query()->where('key', $source->key())->first();
        $character = $this->catalog->find($game, Catalog::CHARACTER, $ref->name);

        $stored = $model && $character
            ? Build::query()->where('build_source_id', $model->id)->where('game_character_id', $character->id)->pluck('content_hash', 'variant')->all()
            : [];

        $logStart = count($this->log);
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

        // Unknown weapon / set names were appended to the log by buildData(); hand them back instead.
        $unknown = array_map(fn ($line) => trim(Str::after($line, '! ')), array_slice($this->log, $logStart));
        array_splice($this->log, $logStart);

        return [
            'character' => $character?->key,
            'builds' => $rows,
            'removed' => array_values(array_diff(array_keys($stored), array_column($rows, 'variant'))),
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * The normalized columns stored for one build (weapon / set names resolved against the catalog).
     */
    private function buildData(Game $game, ScrapedBuild $scraped, int $position): array
    {
        return [
            'role' => $scraped->role,
            'recommended' => $scraped->recommended,
            'position' => $position,
            'weapons' => $this->weapons($game, $scraped),
            'sets' => $this->sets($game, $scraped),
            'main_stats' => $scraped->mainStats,
            'substats' => $scraped->substats,
            'talent_priority' => $scraped->talents ?: null,
            'stat_targets' => $scraped->statTargets ?: null,
            'notes' => $scraped->notes,
            'source_url' => $scraped->sourceUrl,
            'source_updated_at' => $scraped->updatedAt,
            'game_version' => $scraped->gameVersion,
        ];
    }

    private function contentHash(array $data): string
    {
        return hash('sha256', json_encode($data));
    }

    private function weapons(Game $game, ScrapedBuild $scraped): array
    {
        $weapons = [];
        foreach (array_values($scraped->weapons) as $index => $weapon) {
            $found = $this->catalog->find($game, Catalog::WEAPON, $weapon['name']);
            if (! $found) {
                $this->log[] = "  ! unknown weapon: {$weapon['name']}";
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
                        $this->log[] = "  ! unknown set: {$name}";
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

    private function say(string $line): void
    {
        $this->log[] = $line;
        if ($this->output) {
            ($this->output)($line);
        }
    }
}
