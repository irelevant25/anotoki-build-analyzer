<?php

namespace App\Services\Catalog;

use App\Cache\Cache;
use App\Config;
use App\Enums\Game;
use App\Support\Clock;
use App\Support\Log;
use App\Web\AfterResponse;
use Throwable;

/**
 * The catalog kept fresh by ordinary traffic (api/middleware/upkeep.php - the host has no cron): at most every
 * LOOK_SECONDS an answer looks for a list not confirmed within refresh_days, and the first it finds is refreshed
 * after the answer - claimed for retry_minutes (catalog-upkeep:{game}:{list}), so a provider that is down is asked
 * again no sooner and two requests never refresh one list. config/app.php `upkeep.catalog` switches it off (the
 * tests do, but where they mean to try it).
 */
final class CatalogUpkeep
{
    /** How often an answer looks at all: a look reads a cache row per list. */
    private const LOOK_SECONDS = 300;

    public function __construct(private readonly CatalogSync $sync, private readonly Cache $cache, private readonly Config $config) {}

    /** Claims the next stale list's refresh, if any is due, and leaves it for after the answer. */
    public function soon(): void
    {
        if (!$this->cache->add('catalog-upkeep:look', Clock::timestamp(), self::LOOK_SECONDS)) {
            return;
        }
        $retry = max(1, (int) $this->config->get('catalog.retry_minutes', 60));
        foreach (Game::cases() as $game) {
            foreach ($this->sync->staleLists($game, due: true) as $list) {
                if ($this->cache->add("catalog-upkeep:{$game->value}:{$list}", Clock::timestamp(), $retry * 60)) {
                    AfterResponse::add(function () use ($game, $list): void {
                        try {
                            $this->sync->refresh($game, $list);
                        } catch (Throwable $e) {
                            Log::exception($e, "The catalog's upkeep ({$game->value} {$list})");
                        }
                    });

                    return;
                }
            }
        }
    }
}
