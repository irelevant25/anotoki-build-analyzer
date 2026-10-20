<?php

/**
 * The polite fetcher: a site's delay holds across requests (each request is a new Fetcher; the time of the last
 * one is kept in the cache), each site has its own, a request somebody waits for never waits long, and pages
 * are kept in the page cache for the hours asked.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Cache\Cache;
use App\Http\Fetcher;
use App\Http\HttpStatusException;
use Tests\Support\FakeHttpClient;

bootTests();

$fetcher = fn(bool $web = false) => new Fetcher(T::$http, T::$app->get(Cache::class), T::$app->config, $web);

test('the crawl delay holds across requests, and another site has its own', function () use ($fetcher) {
    T::$http->fake(['https://slow.example/*' => 'page', 'https://other.example/*' => 'other']);

    $start = microtime(true);
    $fetcher()->get('https://slow.example/a', ['delay_ms' => 400]);

    // The next request (a new Fetcher) waits for what is left of the delay.
    $next = $fetcher();
    same('page', $next->get('https://slow.example/b', ['delay_ms' => 400]), 'body');
    $waited = microtime(true) - $start;
    check($waited >= 0.35, "the delay was waited for ({$waited} s)");

    // Another site has its own delay.
    $start = microtime(true);
    same('other', $next->get('https://other.example/x', ['delay_ms' => 400]), 'body');
    $waited = microtime(true) - $start;
    check($waited < 0.35, "no wait for another site ({$waited} s)");
    same(3, count(T::$http->sent), 'three requests sent');
});

test('a request somebody waits for does not wait out a long Retry-After: the 429 stands at once', function () use ($fetcher) {
    T::$http->fake(['https://busy.example/*' => FakeHttpClient::status(429, 'slow down', ['Retry-After' => '30'])]);

    $start = microtime(true);
    throws(HttpStatusException::class, fn() => $fetcher(web: true)->get('https://busy.example/page', ['delay_ms' => 0]), 'the 429');
    same(1, count(T::$http->sent), 'one request sent');
    check(microtime(true) - $start < 2, 'nothing waited');
});

test('pages are kept in the page cache for the hours asked', function () use ($fetcher) {
    T::$http->fake(['https://cached.example/*' => 'first']);
    same('first', $fetcher()->get('https://cached.example/p', ['delay_ms' => 0, 'cache_hours' => 12]), 'downloaded');

    T::$http->fake(['https://cached.example/*' => 'second']);
    same('first', $fetcher()->get('https://cached.example/p', ['delay_ms' => 0, 'cache_hours' => 12]), 'from the cache');
    same(1, count(T::$http->sent), 'one download');
    same('second', $fetcher()->get('https://cached.example/p', ['delay_ms' => 0]), 'without the cache');
});

finishTests();
