<?php

namespace App\Cache;

use App\Config;
use App\Support\Clock;
use App\Web\AfterResponse;

/**
 * The day's tidying up, after an answer (api/middleware/upkeep.php - the host has no cron): what has expired in the
 * cache table - the limits' counters, the showcase's answers, a refresh's claim, the day's own claim - and the
 * downloaded files the showcase import keeps for a day (storage/http-cache) once they are older than
 * PAGE_DAYS. The first answer of a UTC day that finds it not done claims it and does it after the answer has gone;
 * config/app.php `upkeep.prune` switches it off (the tests do, but where they mean to try it).
 */
final class Prune
{
    /** How old a downloaded file may get before it goes: its users keep one a day at most. */
    private const PAGE_DAYS = 3;

    public function __construct(private readonly Cache $cache, private readonly Config $config) {}

    /** Claims today's tidying up, if nobody has, and leaves it for after the answer. */
    public function dailyIfDue(): void
    {
        if ($this->cache->add('prune:' . Clock::now()->format('Y-m-d'), true, 2 * 86400)) {
            AfterResponse::add(fn() => $this->run());
        }
    }

    /**
     * @return array{cache: int, files: int} what went: rows of the cache table, downloaded files
     */
    public function run(): array
    {
        $rows = $this->cache->prune();
        $files = 0;
        $limit = time() - self::PAGE_DAYS * 86400;
        foreach (glob($this->config->storage('http-cache/*.cache')) ?: [] as $file) {
            if (@filemtime($file) < $limit && @unlink($file)) {
                $files++;
            }
        }

        return ['cache' => $rows, 'files' => $files];
    }
}
