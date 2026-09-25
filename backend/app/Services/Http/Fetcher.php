<?php

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Polite HTTP GET helper used by the catalog sync, Enka client and build scrapers.
 *
 * - identifies itself with a User-Agent (or a browser UA for sites that reject bots),
 * - waits between requests to the same host,
 * - retries 429 / 5xx responses with backoff,
 * - optionally caches bodies on disk so re-running a sync during development does not refetch.
 */
class Fetcher
{
    /** @var array<string, float> host => microtime of last request */
    private array $lastRequestAt = [];

    /**
     * @param  array{browser?: bool, cache_hours?: int|null, headers?: array<string, string>, delay_ms?: int}  $options
     */
    public function get(string $url, array $options = []): string
    {
        $cacheHours = $options['cache_hours'] ?? null;
        $cachePath = 'http-cache/'.sha1($url).'.cache';
        $disk = Storage::disk('local');

        if ($cacheHours && $disk->exists($cachePath) && $disk->lastModified($cachePath) > time() - $cacheHours * 3600) {
            return $disk->get($cachePath);
        }

        $response = $this->send($url, $options);
        $body = $response->body();

        if ($cacheHours) {
            $disk->put($cachePath, $body);
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
    public function request(string $url, array $options = []): Response
    {
        return $this->send($url, $options, throw: false);
    }

    private function send(string $url, array $options, bool $throw = true): Response
    {
        $this->throttle($url, $options['delay_ms'] ?? (int) config('hoyo.http.delay_ms'));

        $userAgent = ($options['browser'] ?? false)
            ? config('hoyo.http.browser_user_agent')
            : config('hoyo.http.user_agent');

        $headers = array_merge([
            'User-Agent' => $userAgent,
            'Accept-Language' => 'en-US,en;q=0.9',
        ], $options['headers'] ?? []);

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = Http::withHeaders($headers)
                    ->timeout((int) config('hoyo.http.timeout'))
                    ->get($url);
            } catch (ConnectionException $e) {
                if ($attempt >= 3) {
                    throw new RuntimeException("Could not connect to {$url}: {$e->getMessage()}", previous: $e);
                }
                usleep(1_000_000 * $attempt);

                continue;
            }

            $status = $response->status();
            if (($status === 429 || $status >= 500) && $attempt < 3) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 0);
                usleep(1_000_000 * max($retryAfter, 2 * $attempt));

                continue;
            }

            if ($throw && $response->failed()) {
                throw new HttpStatusException($url, $status);
            }

            return $response;
        }
    }

    private function throttle(string $url, int $delayMs): void
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $now = microtime(true);
        $last = $this->lastRequestAt[$host] ?? 0.0;
        $wait = $last + $delayMs / 1000 - $now;

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        $this->lastRequestAt[$host] = microtime(true);
    }
}
