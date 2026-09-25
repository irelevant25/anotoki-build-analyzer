<?php

namespace App\Jobs;

use App\Services\Builds\BuildSourceRegistry;
use App\Services\Builds\BuildSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Sync now" from the UI. A full Prydwen sync takes ~20 minutes because of its crawl delay,
 * so this runs on the queue worker (php artisan queue:work).
 */
class SyncBuildSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3300;

    public int $tries = 1;

    public function __construct(public readonly string $sourceKey, public readonly bool $force = true) {}

    public function uniqueId(): string
    {
        return $this->sourceKey;
    }

    public function handle(BuildSourceRegistry $registry, BuildSync $sync): void
    {
        $sync->sync($registry->get($this->sourceKey), ['force' => $this->force]);
    }
}
