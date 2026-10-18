<?php

namespace App\Commands;

use App\App;
use App\Cli;
use App\Enums\Game;
use App\Models\SyncRun;
use App\Repositories\SyncRuns;
use App\Services\Builds\BuildSource;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Builds\BuildSync;
use App\Services\Builds\CharacterRef;
use App\Services\Catalog\Catalog;
use App\Services\Sync\SyncRunner;
use App\Support\Key;
use Throwable;

/**
 * sync-builds.php: read recommended builds from the build sources (see that file for the options).
 */
final class SyncBuilds
{
    /** @param list<string> $argv */
    public static function run(App $app, array $argv): int
    {
        [$options, $positional] = Cli::arguments($argv);
        $verbose = in_array('-v', $argv, true);

        // A longer page cache for this run only: a dry run then replays the pages fetched in the last days
        // instead of asking the sites again.
        if (isset($options['cache-hours'])) {
            $app->config->set('fetch.cache_hours', max(0, (int) $options['cache-hours']));
        }

        $registry = $app->get(BuildSourceRegistry::class);
        $game = isset($options['game']) ? Game::tryFrom((string) $options['game']) : null;
        if (isset($options['game']) && $game === null) {
            Cli::error('--game is genshin or hsr.');

            return 1;
        }
        if (isset($positional[0])) {
            $source = $registry->find($positional[0]);
            if ($source === null) {
                Cli::error("Unknown build source {$positional[0]}. Known: " . implode(', ', array_keys($registry->all())));

                return 1;
            }
            $sources = [$source];
        } else {
            $sources = array_values($registry->all($game));
        }
        $only = array_values(array_filter(array_map('strval', (array) ($options['character'] ?? []))));
        $limit = isset($options['limit']) ? (int) $options['limit'] : null;

        $name = fn(BuildSource $source) => "{$source->name()} ({$source->game()->label()}, {$registry->label($source->key())})";

        // ── --check-names ──────────────────────────────────────────────────────────────────────────
        if (isset($options['check-names'])) {
            $catalog = $app->get(Catalog::class);
            foreach ($sources as $source) {
                $refs = $source->listCharacters();
                $missing = [];
                foreach ($refs as $ref) {
                    if (!$catalog->find($source->game(), Catalog::CHARACTER, $ref->name)) {
                        $missing[] = $ref->name . ($ref->released ? '' : ' (unreleased)');
                    }
                }
                Cli::info(sprintf('%s: %d characters, %d not matched%s', $name($source), count($refs), count($missing), $missing ? ': ' . implode(', ', $missing) : ''));
            }

            return 0;
        }

        // ── --dry-run ──────────────────────────────────────────────────────────────────────────
        if (isset($options['dry-run'])) {
            $sync = $app->get(BuildSync::class);
            $onlyKeys = array_map(fn($value) => Key::from($value), $only);
            $problems = false;

            foreach ($sources as $source) {
                $totals = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0, 'errors' => 0];
                $checked = 0;
                Cli::info("{$name($source)} - dry run");

                foreach ($source->listCharacters() as $ref) {
                    /** @var CharacterRef $ref */
                    if ($onlyKeys !== [] && !in_array(Key::from($ref->slug), $onlyKeys, true) && !in_array(Key::from($ref->name), $onlyKeys, true)) {
                        continue;
                    }
                    if (!$ref->released || ($limit !== null && $checked >= $limit)) {
                        continue;
                    }
                    $checked++;

                    try {
                        $result = $sync->preview($source, $ref);
                    } catch (Throwable $e) {
                        $totals['errors']++;
                        Cli::error("  {$ref->name}: ERROR {$e->getMessage()}");

                        continue;
                    }

                    foreach ($result['builds'] as $build) {
                        $totals[$build['status']]++;
                    }
                    $totals['removed'] += count($result['removed']);

                    $interesting =
                        $result['unknown'] !== [] || $result['removed'] !== [] || $result['character'] === null || array_filter($result['builds'], fn($build) => $build['status'] !== 'unchanged');
                    if ($interesting || $verbose) {
                        Cli::line(
                            sprintf(
                                '  %s%s: %s%s%s',
                                $ref->name,
                                $result['character'] === null ? ' (not in catalog)' : '',
                                $result['builds'] === [] ? 'no builds' : implode(', ', array_map(fn($b) => "{$b['variant']}={$b['status']}", $result['builds'])),
                                $result['removed'] ? '; would remove: ' . implode(', ', $result['removed']) : '',
                                $result['unknown'] ? '; ' . implode('; ', $result['unknown']) : '',
                            ),
                        );
                    }
                }

                $problems = $problems || $totals['errors'] > 0;
                Cli::line(
                    sprintf(
                        '  %d characters: %d unchanged, %d changed, %d new, %d would be removed, %d errors',
                        $checked,
                        $totals['unchanged'],
                        $totals['changed'],
                        $totals['new'],
                        $totals['removed'],
                        $totals['errors'],
                    ),
                );
            }

            return $problems ? 1 : 0;
        }

        // ── a sync ──────────────────────────────────────────────────────────────────────────
        $runner = $app->get(SyncRunner::class);
        ['started' => $started, 'skipped' => $skipped] = $runner->startBuilds($sources, [
            'force' => isset($options['force']),
            'only' => $only,
            'limit' => $limit,
        ]);
        foreach ($skipped as $skip) {
            Cli::line("{$skip['name']}: {$skip['reason']}, skipping.");
        }
        if ($started === []) {
            return 0;
        }

        // A catalog run started for a stale game is part of the work to wait for.
        $ids = array_map(fn(SyncRun $run) => (int) $run->id, $started);
        foreach ($runner->active() as $run) {
            if ($run->kind === SyncRun::CATALOG) {
                $ids[] = (int) $run->id;
            }
        }
        $runner->runUntilDone($ids, fn(string $line) => Cli::line($line));

        return $app->get(SyncRuns::class)->anyFailed($ids) ? 1 : 0;
    }
}
