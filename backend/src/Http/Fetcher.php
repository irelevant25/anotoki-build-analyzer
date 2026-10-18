<?php

namespace App\Http;

use App\Cache\Cache;
use App\Config;
use App\Support\Clock;
use RuntimeException;

/**
 * Polite HTTP GET helper used by the catalog sync, the showcase client and the build scrapers.
 *
 * - identifies itself with a User-Agent (or a browser UA for sites that reject bots),
 * - waits between requests to the same host - remembered in the cache, so the wait also holds between
 *   the separate HTTP requests (or processes) of a stepped sync,
 * - retries 429 / 5xx responses and failed connections a few times, with backoff,
 * - optionally caches bodies on disk so re-running a sync during development does not refetch.
 *
 * Inside a sync step (setDeadline()) nothing may run past the step's deadline: a wait that does not fit
 * throws FetchDeferred instead of sleeping, and a retry is left to a later step (the attempts are counted
 * across steps in the cache).
 */
class Fetcher
{
    /** Time kept free for one request inside a step; a wait must leave at least this much. */
    private const REQUEST_RESERVE_SECONDS = 8;

    /** Attempts at a URL (429 / 5xx / no connection) before its failure stands. */
    private const ATTEMPTS = 3;

    /**
     * A web request outside a sync step (an import) may wait at most this long for an answer, and retries
     * once at most, after no more than WEB_MAX_RETRY_WAIT_SECONDS: the person is waiting, and the host
     * limits request times.
     */
    private const WEB_TIMEOUT_SECONDS = 10;

    private const WEB_MAX_RETRY_WAIT_SECONDS = 2;

    /** @var array<string, float> host => microtime of this process's last request to it */
    private array $lastRequestAt = [];

    private ?float $deadline = null;

    /**
     * @param  bool  $web  answering a web request (someone is waiting), rather than running on the command line
     */
    public function __construct(private readonly HttpClient $http, private readonly Cache $cache, private readonly Config $config, private readonly bool $web) {}

    /**
     * The wall-clock time (microtime) the current sync step must end by, or null outside a step.
     */
    public function setDeadline(?float $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * @param  array{browser?: bool, cache_hours?: int|null, headers?: array<string, string>, delay_ms?: int}  $options
     */
    public function get(string $url, array $options = []): string
    {
        $cacheHours = $options['cache_hours'] ?? null;
        $cachePath = $this->config->storage('http-cache/' . sha1($url) . '.cache');

        if ($cacheHours && is_file($cachePath) && filemtime($cachePath) > time() - $cacheHours * 3600) {
            return (string) file_get_contents($cachePath);
        }

        $body = $this->send($url, $options)->body;

        if ($cacheHours) {
            if (!is_dir(dirname($cachePath))) {
                @mkdir(dirname($cachePath), 0775, true);
            }
            @file_put_contents($cachePath, $body);
        }

        return $body;
    }

    /**
     * @param  array{browser?: bool, cache_hours?: int|null, headers?: array<string, string>, delay_ms?: int}  $options
     * @return array<mixed>
     */
    public function getJson(string $url, array $options = []): array
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
        $body = $this->get($url, $options);

        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Invalid JSON from {$url}: {$e->getMessage()}", previous: $e);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Like get(), but returns the raw response so callers can react to status codes.
     *
     * @param  array{browser?: bool, headers?: array<string, string>, delay_ms?: int}  $options
     */
    public function request(string $url, array $options = []): HttpResponse
    {
        return $this->send($url, $options, throw: false);
    }

    private function send(string $url, array $options, bool $throw = true): HttpResponse
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $delayMs = $options['delay_ms'] ?? (int) $this->config->get('fetch.delay_ms', 1200);

        $userAgent = $options['browser'] ?? false ? (string) $this->config->get('fetch.browser_user_agent') : (string) $this->config->get('fetch.user_agent');

        $headers = array_merge(
            [
                'User-Agent' => $userAgent,
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
            $options['headers'] ?? [],
        );

        $attempt = 0;
        while (true) {
            $attempt++;
            $this->throttle($host, $delayMs);

            try {
                $response = $this->http->get($url, $headers, $this->timeout());
            } catch (ConnectionFailed $e) {
                if ($this->retry($url, $host, $attempt, 10 * $attempt, 'no connection')) {
                    continue;
                }
                throw new RuntimeException("Could not connect to {$url}: {$e->getMessage()}", previous: $e);
            }

            $status = $response->status;
            if ($status === 429 || $status >= 500) {
                $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : 0;
                $wait = $this->deadline === null ? max($retryAfter, 2 * $attempt) : max($retryAfter, 15 * $attempt);
                if ($this->retry($url, $host, $attempt, $wait, "HTTP {$status}")) {
                    continue;
                }
            } elseif ($this->deadline !== null) {
                $this->cache->forget($this->failuresKey($url));
            }

            if ($throw && $response->failed()) {
                throw new HttpStatusException($url, $status);
            }

            return $response;
        }
    }

    /**
     * Wait for the host's delay since the last request to it (by this process or any other).
     */
    private function throttle(string $host, int $delayMs): void
    {
        $last = max($this->lastRequestAt[$host] ?? 0.0, (float) $this->cache->get($this->throttleKey($host), 0.0));
        $allowedAt = $last + $delayMs / 1000;
        $wait = $allowedAt - Clock::microtime();

        if ($this->deadline !== null && Clock::microtime() + max(0, $wait) + self::REQUEST_RESERVE_SECONDS > $this->deadline) {
            throw $wait > 0 ? new FetchDeferred($host, $allowedAt) : new FetchDeferred($host, Clock::microtime(), 'no time left in this step');
        }
        if ($wait > 0) {
            usleep((int) ceil($wait * 1_000_000));
        }

        $now = Clock::microtime();
        $this->lastRequestAt[$host] = $now;
        $this->cache->put($this->throttleKey($host), $now, max(60, 2 * (int) ceil($delayMs / 1000)));
    }

    /**
     * After a failed attempt: true to try again after the wait, false when the failure stands. Inside a
     * step the wait belongs to a later step (FetchDeferred), and attempts are counted in the cache.
     */
    private function retry(string $url, string $host, int $attempt, int $waitSeconds, string $reason): bool
    {
        if ($this->deadline === null) {
            if ($attempt >= ($this->web ? 2 : self::ATTEMPTS) || ($this->web && $waitSeconds > self::WEB_MAX_RETRY_WAIT_SECONDS)) {
                return false;
            }
            usleep(1_000_000 * $waitSeconds);

            return true;
        }

        $key = $this->failuresKey($url);
        $failures = (int) $this->cache->get($key, 0) + 1;
        if ($failures >= self::ATTEMPTS) {
            $this->cache->forget($key);

            return false;
        }
        $this->cache->put($key, $failures, 3600);

        throw new FetchDeferred($host, Clock::microtime() + min(600, $waitSeconds), "{$reason}, will retry");
    }

    /**
     * Seconds a request may take: the configured timeout, inside a step never past its deadline, and in any
     * other web request (an import) no more than WEB_TIMEOUT_SECONDS.
     */
    private function timeout(): int
    {
        $timeout = (int) $this->config->get('fetch.timeout', 30);
        if ($this->deadline !== null) {
            $timeout = min($timeout, max(5, (int) floor($this->deadline - Clock::microtime()) - 1));
        } elseif ($this->web) {
            $timeout = min($timeout, self::WEB_TIMEOUT_SECONDS);
        }

        return $timeout;
    }

    private function throttleKey(string $host): string
    {
        return 'fetcher:last-request:' . $host;
    }

    private function failuresKey(string $url): string
    {
        return 'fetcher:failures:' . sha1($url);
    }
}
