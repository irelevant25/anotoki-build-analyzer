<?php

namespace App\Http;

use RuntimeException;

/**
 * A request that may not be sent yet - a site's crawl delay, or a retry after 429 / 5xx - and does not fit
 * into the time the current sync step has left. The step stops there; the next one starts at $retryAt.
 */
class FetchDeferred extends RuntimeException
{
    public function __construct(
        public readonly string $host,
        public readonly float $retryAt,
        string $reason = 'crawl delay',
    ) {
        parent::__construct("{$host}: {$reason}, next request at ".gmdate('H:i:s', (int) ceil($retryAt)).' UTC');
    }
}
