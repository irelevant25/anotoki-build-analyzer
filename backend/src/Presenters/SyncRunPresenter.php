<?php

namespace App\Presenters;

use App\Models\BuildSource;
use App\Models\SyncRun;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Sync\StepResult;
use App\Support\Clock;
use App\Support\Neutral;

/**
 * Sync runs and sources as the Sources page shows them (mirrored in frontend/src/app/core/models.ts).
 * Sources appear as "Source N" with a key like "genshin-2"; logs and errors carry no site's name.
 */
final class SyncRunPresenter
{
    public function __construct(private readonly BuildSourceRegistry $registry) {}

    /**
     * @param  bool  $withStarter  include who started the run (their IAM id and name): editors and admins only
     */
    public function summary(SyncRun $run, bool $withStarter = true): array
    {
        $state = $run->state ?? [];

        return [
            'id' => $run->id,
            'kind' => $run->kind,
            'game' => $run->game?->value,
            'status' => $run->status,
            'started_at' => Clock::iso($run->started_at),
            'finished_at' => Clock::iso($run->finished_at),
            // Names a sync or an import could not match ("unmatched") are text from elsewhere.
            'stats' => Neutral::deep($run->stats),
            'progress' => $run->isActive() ? ['done' => (int) ($state['done'] ?? 0), 'total' => isset($state['total']) ? (int) $state['total'] : null] : null,
            'next_step_at' => Clock::iso($run->next_step_at),
            'last_step_at' => Clock::iso($run->last_step_at),
            'started_by' => $withStarter && $run->starter ? ['id' => $run->starter->id, 'name' => $run->starter->name] : null,
            'source' => $run->source
                ? [
                    'key' => $this->registry->publicKey($run->source->key),
                    'name' => $this->registry->label($run->source->key),
                    'game' => $run->source->game->value,
                ]
                : null,
        ];
    }

    public function withLog(SyncRun $run): array
    {
        return $this->summary($run) + ['log' => Neutral::text($run->log)];
    }

    /**
     * What the Sources page needs to drive the syncs: the runs going and when to step next.
     *
     * @param  list<SyncRun>  $active
     */
    public function status(array $active, ?int $waitMs): array
    {
        return [
            'active' => $active !== [],
            'wait_ms' => $waitMs,
            'runs' => array_map(fn(SyncRun $run) => $this->summary($run), $active),
        ];
    }

    public function step(StepResult $result): array
    {
        return [
            'busy' => $result->busy,
            'did' => $result->run ? $this->summary($result->run) : null,
            'lines' => $result->lines,
        ] + $this->status($result->active, $result->waitMs);
    }

    public function source(BuildSource $source): array
    {
        return [
            'key' => $this->registry->publicKey($source->key),
            'game' => $source->game->value,
            'name' => $this->registry->label($source->key),
            'enabled' => $source->enabled,
            'last_synced_at' => Clock::iso($source->last_synced_at),
            'last_status' => $source->last_status,
            'last_error' => Neutral::text($source->last_error),
        ];
    }
}
