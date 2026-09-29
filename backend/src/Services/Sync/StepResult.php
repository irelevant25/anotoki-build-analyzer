<?php

namespace App\Services\Sync;

use App\Models\SyncRun;

/**
 * What one step did, and what is left.
 */
final readonly class StepResult
{
    /**
     * @param  bool  $busy  another step (another tab, the command line) was running; nothing was done
     * @param  SyncRun|null  $run  the run this step worked on
     * @param  list<string>  $lines  log lines this step wrote
     * @param  list<SyncRun>  $active  the runs still going
     * @param  int|null  $waitMs  when the next step has work to do (0 = now), null when nothing is left
     */
    public function __construct(
        public bool $busy,
        public ?SyncRun $run,
        public array $lines,
        public array $active,
        public ?int $waitMs,
    ) {}
}
