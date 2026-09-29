<?php

namespace App\Services\Sync;

use App\Models\SyncRun;

/**
 * One kind of sync run (builds of a source, the catalog of a game) worked through in steps.
 */
interface RunHandler
{
    /**
     * Set up the state and stats of a new run (not saved yet).
     */
    public function begin(SyncRun $run): void;

    /**
     * Work on the run until it is finished or the deadline is near, saving after each unit of work, so an
     * interrupted step loses at most the unit in progress. An App\Http\FetchDeferred from the fetcher (the
     * site's crawl delay, or a retry, does not fit into this step) may simply propagate: the run keeps its
     * place and the next step continues it. state "done" / "total" count the units, for the progress bar;
     * a finished run gets its final status.
     *
     * @param  float  $deadline  Clock::microtime() by which the step must be done
     */
    public function step(SyncRun $run, float $deadline, RunLog $log): void;
}
