<?php

namespace App\Http;

use App\Cache\Cache;
use App\Config;
use App\Support\Clock;
use RuntimeException;

/**
 * Polite HTTP GET helper used by the catalog sync, the showcase client and tools run on the command line.
 *
 * - identifies itself with a User-Agent (or a browser UA for sites that reject bots),
 * - waits between requests to the same host - remembered in the cache, so the wait also holds between separate
 *   requests or processes,
 * - retries 429 / 5xx responses and failed connections a few times, with backoff - in a web request (somebody
 *   waits) once at most, after a short wait,
 * - optionally keeps bodies on disk for some hours (storage/http-cache), which the day's tidying up clears.
 */
class Fetcher
{
    /** Attempts at a URL (429 / 5xx / no connection) before its failure stands, on the command line. */
    private const ATTEMPTS = 3;

    /**
     * A web request (an import) may wait at most this long for an answer, and retries once at most, after no more
     * than WEB_MAX_RETRY_WAIT_SECONDS: the person is waiting, and the host limits request times.
     */
    private const WEB_TIMEOUT_SECONDS = 10;

    private const WEB_MAX_RETRY_WAIT_SECONDS = 2;

    /** @var array<string, float> host => microtime of this process's last request to it */
    private array $lastRequestAt = [];

    /**
     * @param  bool  $web  answering a web request (someone is waiting), rather than running on the command line
     */
    public function __construct(private readonly HttpClient $http, private readonly Cache $cache, private readonly Config $config, private readonly bool $web) {}

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
                if ($this->retry($attempt, 10 * $attempt)) {
                    continue;
                }
                throw new RuntimeException("Could not connect to {$url}: {$e->getMessage()}", previous: $e);
            }

            $status = $response->status;
            if ($status === 429 || $status >= 500) {
                $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : 0;
                if ($this->retry($attempt, max($retryAfter, 2 * $attempt))) {
                    continue;
                }
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
        $wait = $last + $delayMs / 1000 - Clock::microtime();
        if ($wait > 0) {
            usleep((int) ceil($wait * 1_000_000));
        }

        $now = Clock::microtime();
        $this->lastRequestAt[$host] = $now;
        $this->cache->put($this->throttleKey($host), $now, max(60, 2 * (int) ceil($delayMs / 1000)));
    }

    /**
     * After a failed attempt: true to try again after the wait, false when the failure stands - in a web request
     * after the first retry, or at once when the wait would be longer than somebody should wait.
     */
    private function retry(int $attempt, int $waitSeconds): bool
    {
        if ($attempt >= ($this->web ? 2 : self::ATTEMPTS) || ($this->web && $waitSeconds > self::WEB_MAX_RETRY_WAIT_SECONDS)) {
            return false;
        }
        usleep(1_000_000 * $waitSeconds);

        return true;
    }

    /**
     * Seconds a request may take: the configured timeout, and in a web request (an import) no more than
     * WEB_TIMEOUT_SECONDS.
     */
    private function timeout(): int
    {
        $timeout = (int) $this->config->get('fetch.timeout', 30);

        return $this->web ? min($timeout, self::WEB_TIMEOUT_SECONDS) : $timeout;
    }

    private function throttleKey(string $host): string
    {
        return 'fetcher:last-request:' . $host;
    }
}
