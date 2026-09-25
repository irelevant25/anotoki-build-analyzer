<?php

namespace App\Console\Commands;

use App\Enums\Game;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Builds\BuildSync;
use App\Services\Builds\CharacterRef;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogSync;
use App\Support\Key;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('builds:sync
    {source? : Source key, e.g. prydwen.hsr (default: all enabled sources)}
    {--game= : Only sources of one game (genshin or hsr)}
    {--character=* : Only these characters (slug or name); can be repeated}
    {--limit= : Stop after this many characters per source}
    {--force : Sync even if the source was synced recently}
    {--check-names : Only list characters and report names that do not match the catalog}
    {--dry-run : Parse pages and compare with the stored builds without writing anything}')]
#[Description('Scrape recommended builds from the configured websites (runs daily via the scheduler)')]
class SyncBuilds extends Command
{
    public function handle(BuildSourceRegistry $registry, BuildSync $sync, CatalogSync $catalogSync, Catalog $catalog): int
    {
        $game = $this->option('game') ? Game::from($this->option('game')) : null;
        $sources = $this->argument('source') ? [$registry->get($this->argument('source'))] : array_values($registry->all($game));

        if ($this->option('check-names')) {
            return $this->checkNames($sources, $catalog);
        }
        if ($this->option('dry-run')) {
            return $this->dryRun($sources, $sync);
        }

        // Builds are matched to catalog characters, so make sure the catalog exists and is reasonably fresh.
        foreach (array_unique(array_map(fn ($source) => $source->game(), $sources), SORT_REGULAR) as $sourceGame) {
            if ($catalogSync->isStale($sourceGame)) {
                $this->line("Refreshing the {$sourceGame->label()} catalog first...");
                try {
                    $catalogSync->sync($sourceGame);
                } catch (Throwable $e) {
                    $this->warn("Catalog refresh failed: {$e->getMessage()}");
                }
            }
        }

        $failed = false;
        foreach ($sources as $source) {
            if (! $registry->model($source)->enabled) {
                $this->line("{$source->name()} ({$source->game()->label()}) is disabled, skipping.");

                continue;
            }

            $run = $sync->onOutput(fn (string $line) => $this->line($line))->sync($source, [
                'force' => (bool) $this->option('force'),
                'only' => $this->option('character'),
                'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null,
            ]);

            $failed = $failed || $run?->status === 'failed';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkNames(array $sources, Catalog $catalog): int
    {
        foreach ($sources as $source) {
            $refs = $source->listCharacters();
            $missing = [];
            foreach ($refs as $ref) {
                if (! $catalog->find($source->game(), Catalog::CHARACTER, $ref->name)) {
                    $missing[] = $ref->name.($ref->released ? '' : ' (unreleased)');
                }
            }
            $this->info(sprintf('%s (%s): %d characters, %d not matched%s',
                $source->name(), $source->game()->label(), count($refs), count($missing), $missing ? ': '.implode(', ', $missing) : ''));
        }

        return self::SUCCESS;
    }

    /**
     * Parse like a sync but only report: per character whether each build is new, changed or unchanged
     * compared with the database, plus unknown weapon / set names. Nothing is written. Pages come from the
     * HTTP cache while it is fresh (raise HOYO_HTTP_CACHE_HOURS to stay offline).
     */
    private function dryRun(array $sources, BuildSync $sync): int
    {
        $only = array_map(fn ($value) => Key::from($value), $this->option('character'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $problems = false;

        foreach ($sources as $source) {
            $totals = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0, 'errors' => 0];
            $checked = 0;
            $this->info("{$source->name()} ({$source->game()->label()}) — dry run");

            foreach ($source->listCharacters() as $ref) {
                /** @var CharacterRef $ref */
                if ($only !== [] && ! in_array(Key::from($ref->slug), $only, true) && ! in_array(Key::from($ref->name), $only, true)) {
                    continue;
                }
                if (! $ref->released || ($limit !== null && $checked >= $limit)) {
                    continue;
                }
                $checked++;

                try {
                    $result = $sync->preview($source, $ref);
                } catch (Throwable $e) {
                    $totals['errors']++;
                    $this->error("  {$ref->name}: ERROR {$e->getMessage()}");

                    continue;
                }

                foreach ($result['builds'] as $build) {
                    $totals[$build['status']]++;
                }
                $totals['removed'] += count($result['removed']);

                $interesting = $result['unknown'] !== [] || $result['removed'] !== [] || $result['character'] === null
                    || array_filter($result['builds'], fn ($build) => $build['status'] !== 'unchanged');
                if ($interesting || $this->output->isVerbose()) {
                    $this->line(sprintf('  %s%s: %s%s%s',
                        $ref->name,
                        $result['character'] === null ? ' (not in catalog)' : '',
                        $result['builds'] === [] ? 'no builds' : implode(', ', array_map(fn ($b) => "{$b['variant']}={$b['status']}", $result['builds'])),
                        $result['removed'] ? '; would remove: '.implode(', ', $result['removed']) : '',
                        $result['unknown'] ? '; '.implode('; ', $result['unknown']) : ''));
                }
            }

            $problems = $problems || $totals['errors'] > 0;
            $this->line(sprintf('  %d characters: %d unchanged, %d changed, %d new, %d would be removed, %d errors',
                $checked, $totals['unchanged'], $totals['changed'], $totals['new'], $totals['removed'], $totals['errors']));
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
