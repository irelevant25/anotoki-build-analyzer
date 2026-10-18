<?php

namespace App\Services\Sync;

use App\Cache\Cache;
use App\Cache\LockTimeout;
use App\Cache\Locks;
use App\Config;
use App\Enums\Game;
use App\Http\FetchDeferred;
use App\Http\Fetcher;
use App\Models\BuildSource as BuildSourceModel;
use App\Models\SyncRun;
use App\Models\User;
use App\Repositories\SyncRuns;
use App\Services\Builds\BuildSource;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Builds\BuildSync;
use App\Services\Catalog\CatalogSync;
use App\Support\Clock;
use App\Support\Log;
use App\Support\Text;
use Closure;
use Throwable;

/**
 * Runs build and catalog syncs without a queue worker, a scheduler or any long-running process.
 *
 * Starting a sync only creates runs (status "running"). The work happens in steps: step() takes the
 * global step lock, picks the run that is due, and works on it until the step budget
 * (fetch.local.php step_seconds) is nearly spent or its site's crawl delay no longer fits, then saves
 * where it is. The Sources page calls step() again and again while it is open; the sync-builds.php and
 * sync-catalog.php commands loop over it. Nothing else advances a run, so closing the tab pauses a sync
 * and the next step continues it.
 *
 * One step at a time, whoever asks: the lock keeps two tabs (or a tab and the command line) from
 * working on a run at once, and the fetcher keeps each site's delay between requests across steps.
 */
class SyncRunner
{
    public const STEP_LOCK = 'sync-step';

    private const START_LOCK = 'sync-start';

    /** The shortest step that still fits one request (Fetcher keeps 8 s for it) with time to store the result. */
    public const MIN_STEP_SECONDS = 12;

    /** Page cache files older than this are deleted when a build run ends. */
    private const HTTP_CACHE_DAYS = 3;

    public function __construct(
        private readonly BuildSync $builds,
        private readonly CatalogSync $catalog,
        private readonly BuildSourceRegistry $registry,
        private readonly Fetcher $fetcher,
        private readonly SyncRuns $runs,
        private readonly Locks $locks,
        private readonly Config $config,
        private readonly Cache $cache,
    ) {}

    // ── starting ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Start build runs for these sources, or join the run already going for a source. A game whose
     * catalog is stale gets a catalog run first (builds are matched to catalog characters); build runs of
     * that game wait until it is done.
     *
     * Skipped: switched-off sources, and without "force" (or only / limit) sources synced successfully
     * within stale_hours, so repeated clicks never hit a site twice a day.
     *
     * @param  list<BuildSource>  $sources
     * @param  array{force?: bool, only?: list<string>, limit?: int|null}  $options
     * @return array{started: list<SyncRun>, skipped: list<array{key: string, name: string, reason: string}>}
     */
    public function startBuilds(array $sources, array $options = [], ?User $by = null): array
    {
        return $this->locks->lock(self::START_LOCK, 10)->block(5, function () use ($sources, $options, $by) {
            $started = [];
            $skipped = [];
            $partial = !empty($options['only']) || ($options['limit'] ?? null) !== null;

            foreach ($sources as $source) {
                $model = $this->registry->model($source);
                $name = $this->registry->title($source);
                $key = (string) $this->registry->publicKey($source->key());

                if ($active = $this->runs->activeRun(SyncRun::BUILDS, $source->game(), $model->id)) {
                    $started[] = $active;

                    continue;
                }
                if (!$model->enabled) {
                    $skipped[] = ['key' => $key, 'name' => $name, 'reason' => 'switched off'];

                    continue;
                }
                if (!($options['force'] ?? false) && !$partial && $this->builds->isFresh($model)) {
                    $skipped[] = ['key' => $key, 'name' => $name, 'reason' => 'synced ' . Clock::ago($model->last_synced_at)];

                    continue;
                }

                if ($this->catalog->isStale($source->game())) {
                    $this->startCatalogRun($source->game(), $by);
                }

                $started[] = $this->create(
                    SyncRun::BUILDS,
                    $source->game(),
                    $model,
                    [
                        'options' => array_filter(
                            [
                                'force' => (bool) ($options['force'] ?? false),
                                'only' => array_values($options['only'] ?? []),
                                'limit' => $options['limit'] ?? null,
                            ],
                            fn($value) => $value !== null && $value !== [],
                        ),
                    ],
                    $by,
                    $this->builds,
                );
            }

            return compact('started', 'skipped');
        });
    }

    /**
     * Refresh the catalog of these games; without "force" only when it is stale.
     *
     * @param  list<Game>  $games
     * @return array{started: list<SyncRun>, skipped: list<array{key: string, name: string, reason: string}>}
     */
    public function startCatalog(array $games, bool $force = true, ?User $by = null): array
    {
        return $this->locks->lock(self::START_LOCK, 10)->block(5, function () use ($games, $force, $by) {
            $started = [];
            $skipped = [];
            foreach ($games as $game) {
                if (!$force && !$this->runs->activeRun(SyncRun::CATALOG, $game) && !$this->catalog->isStale($game)) {
                    $skipped[] = ['key' => "catalog.{$game->value}", 'name' => "{$game->label()} catalog", 'reason' => 'up to date'];

                    continue;
                }
                $started[] = $this->startCatalogRun($game, $by);
            }

            return compact('started', 'skipped');
        });
    }

    /**
     * Stop a run where it is. A step working on it at that moment is not interrupted: this waits a few seconds
     * for the step lock, then gives up (LockTimeout) rather than hold the request.
     *
     * @throws LockTimeout
     */
    public function cancel(SyncRun $run, ?User $by = null): SyncRun
    {
        return $this->exclusively(function () use ($run, $by) {
            $run = $this->runs->find((int) $run->id) ?? $run;
            if ($run->isActive()) {
                $done = $run->state['done'] ?? 0;
                $total = $run->state['total'] ?? null;
                (new RunLog($run))->add('Cancelled' . ($by ? " by {$by->name}" : '') . ($total !== null ? " after {$done} of {$total}." : '.'));
                $run->status = 'cancelled';
                $run->finished_at = Clock::now();
                $run->state = null;
                $run->next_step_at = null;
                $this->runs->save($run);
            }

            return $run;
        });
    }

    /**
     * Runs $work holding the step lock, so no step works on any run meanwhile: a cancel, or builds imported
     * from a file, which write what a step of that source would. Waits a few seconds for a step that is
     * going, then gives up (LockTimeout) rather than hold the request.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @param  int|null  $seconds  how long the lock holds if the request dies with it; longer than $work can take
     * @return T
     *
     * @throws LockTimeout
     */
    public function exclusively(callable $work, ?int $seconds = null): mixed
    {
        return $this->locks->lock(self::STEP_LOCK, $seconds ?? $this->budget() + 60)->block(5, $work);
    }

    // ── driving ───────────────────────────────────────────────────────────────────────────────────

    /**
     * One bounded piece of work on the run that is due.
     */
    public function step(): StepResult
    {
        $lock = $this->locks->lock(self::STEP_LOCK, $this->budget() + 60);
        if (!$lock->get()) {
            $active = $this->active();

            return new StepResult(true, null, [], $active, $active === [] ? null : 1000);
        }

        try {
            $run = $this->due($this->active());
            $lines = $run ? $this->work($run) : [];
        } finally {
            $lock->release();
        }

        $active = $this->active();

        return new StepResult(false, $run, $lines, $active, $this->waitMs($active));
    }

    /**
     * Step until these runs are finished, sleeping through the waits (the command line).
     *
     * @param  list<int>  $runIds
     * @param  (Closure(string): void)|null  $output  receives each log line as it is written
     */
    public function runUntilDone(array $runIds, ?Closure $output = null): void
    {
        while ($this->runs->anyActive($runIds)) {
            $result = $this->step();
            foreach ($result->lines as $line) {
                $output && $output($line);
            }

            $waitMs = $result->busy ? 1000 : $result->waitMs ?? 0;
            if ($waitMs > 0 || $result->run === null) {
                usleep(1000 * max(200, min($waitMs, 60_000)));
            }
        }
    }

    /**
     * The runs still going, oldest first.
     *
     * @return list<SyncRun>
     */
    public function active(): array
    {
        return $this->runs->active();
    }

    /**
     * Milliseconds until the next step has work to do (0 = now), or null when no run is going.
     *
     * @param  list<SyncRun>  $active
     */
    public function waitMs(array $active): ?int
    {
        $eligible = $this->eligible($active);
        if ($eligible === []) {
            return $active === [] ? null : 1000;
        }

        $next = min(array_map(fn(SyncRun $run) => $run->next_step_at ? Clock::ms($run->next_step_at) : 0, $eligible));

        return (int) max(0, $next - Clock::ms(Clock::now()));
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────

    /**
     * @return list<string> the log lines written
     */
    private function work(SyncRun $run): array
    {
        $handler = $run->kind === SyncRun::CATALOG ? $this->catalog : $this->builds;
        $log = new RunLog($run);
        $deadline = Clock::microtime() + $this->budget();

        $run->last_step_at = Clock::now();
        $run->next_step_at = null;
        $this->fetcher->setDeadline($deadline);

        try {
            $handler->step($run, $deadline, $log);
        } catch (FetchDeferred $e) {
            // The site's delay (or a retry) does not fit into this step; the run keeps its place.
            $run->next_step_at = Clock::fromTimestamp((int) ceil($e->retryAt));
        } catch (Throwable $e) {
            // Something the handler could not deal with (a source removed from the configuration, the
            // database): the run cannot go on.
            Log::exception($e, "Sync run {$run->id}");
            $log->add('Stopped: ' . Text::limit($e->getMessage(), 300));
            $run->status = 'failed';
            $run->finished_at = Clock::now();
            $run->state = null;
        } finally {
            $this->fetcher->setDeadline(null);
        }

        $this->runs->save($run);

        if (!$run->isActive() && $run->kind === SyncRun::BUILDS) {
            $this->pruneHttpCache();
            // Expired entries (a failing page's retry counter, a showcase answer) are only ever overwritten by
            // the same key; nothing else would remove them.
            $this->cache->prune();
        }

        return $log->written();
    }

    /**
     * The run to work on now: due (its site allows the next request), not waiting for a catalog refresh of
     * its game, and among those the one that has waited longest, so runs on different sites take turns.
     *
     * @param  list<SyncRun>  $active
     */
    private function due(array $active): ?SyncRun
    {
        $now = Clock::now();
        $due = array_filter($this->eligible($active), fn(SyncRun $run) => $run->next_step_at === null || $run->next_step_at <= $now);
        usort($due, fn(SyncRun $a, SyncRun $b) => [($a->last_step_at ?? $a->started_at)->getTimestamp(), $a->id] <=> [($b->last_step_at ?? $b->started_at)->getTimestamp(), $b->id]);

        return $due[0] ?? null;
    }

    /**
     * Active runs that may be worked on: a build run waits while its game's catalog is being refreshed.
     *
     * @param  list<SyncRun>  $active
     * @return list<SyncRun>
     */
    private function eligible(array $active): array
    {
        $refreshing = [];
        foreach ($active as $run) {
            if ($run->kind === SyncRun::CATALOG && $run->game !== null) {
                $refreshing[] = $run->game;
            }
        }

        return array_values(array_filter($active, fn(SyncRun $run) => !($run->kind === SyncRun::BUILDS && in_array($run->game, $refreshing, true))));
    }

    private function startCatalogRun(Game $game, ?User $by): SyncRun
    {
        return $this->runs->activeRun(SyncRun::CATALOG, $game) ?? $this->create(SyncRun::CATALOG, $game, null, [], $by, $this->catalog);
    }

    private function create(string $kind, Game $game, ?BuildSourceModel $model, array $state, ?User $by, RunHandler $handler): SyncRun
    {
        $run = new SyncRun(
            id: null,
            kind: $kind,
            game: $game,
            build_source_id: $model?->id,
            status: SyncRun::RUNNING,
            started_at: Clock::now(),
            state: $state,
            started_by: $by?->id,
            source: $model,
            starter: $by,
        );

        $handler->begin($run);
        if ($by) {
            (new RunLog($run))->add("Started by {$by->name}.");
        }
        $this->runs->insert($run);

        return $run;
    }

    /**
     * Seconds per step. Never below MIN_STEP_SECONDS: the fetcher keeps 8 s free for a request, so a shorter
     * step could never send one and a run would be stepped forever without moving.
     */
    private function budget(): int
    {
        return max(self::MIN_STEP_SECONDS, (int) $this->config->get('fetch.step_seconds', 20));
    }

    /**
     * Keep the page cache from growing forever (it only has to outlive a sync).
     */
    private function pruneHttpCache(): void
    {
        $limit = time() - self::HTTP_CACHE_DAYS * 86400;

        foreach (glob($this->config->storage('http-cache/*.cache')) ?: [] as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
