<?php

/**
 * The polite fetcher inside stepped syncs: a site's delay holds across requests (each step is a new
 * request with a new Fetcher), and nothing waits past a step's deadline.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Cache\Cache;
use App\Http\FetchDeferred;
use App\Http\Fetcher;
use App\Http\HttpStatusException;
use App\Support\Clock;
use Tests\Support\FakeHttpClient;

bootTests();

$fetcher = fn() => new Fetcher(T::$http, T::$app->get(Cache::class), T::$app->config, false);

test('the crawl delay holds across requests, and a wait that does not fit is deferred', function () use ($fetcher) {
    T::$http->fake(['https://slow.example/*' => 'page', 'https://other.example/*' => 'other']);

    $fetcher()->get('https://slow.example/a', ['delay_ms' => 10_000]);

    // The next step (a new request, a new Fetcher) has 12 s left: 10 s of waiting and a request do not fit.
    $next = $fetcher();
    $next->setDeadline(Clock::microtime() + 12);
    $deferred = throws(FetchDeferred::class, fn() => $next->get('https://slow.example/b', ['delay_ms' => 10_000]), 'deferred');
    same('slow.example', $deferred->host, 'host');
    near(Clock::microtime() + 10, $deferred->retryAt, 1.5, 'retry at');
    same(1, count(T::$http->sent), 'one request sent');

    // Another site has its own delay.
    same('other', $next->get('https://other.example/x', ['delay_ms' => 10_000]), 'body');
    same(2, count(T::$http->sent), 'two requests sent');
});

test('a step near its deadline sends nothing more', function () use ($fetcher) {
    T::$http->fake(['https://fast.example/*' => 'page']);

    $step = $fetcher();
    $step->setDeadline(Clock::microtime() + 3);

    throws(FetchDeferred::class, fn() => $step->get('https://fast.example/a', ['delay_ms' => 0]), 'deferred');
    same([], T::$http->sent, 'nothing sent');
});

test('inside a step a 429 is retried by later steps, then given up', function () use ($fetcher) {
    T::$http->fake(['https://busy.example/*' => FakeHttpClient::status(429, 'slow down', ['Retry-After' => '30'])]);

    $step = $fetcher();
    $step->setDeadline(Clock::microtime() + 60);
    foreach ([1, 2] as $attempt) {
        $deferred = throws(FetchDeferred::class, fn() => $step->get('https://busy.example/page', ['delay_ms' => 0]), "attempt {$attempt}");
        near(Clock::microtime() + 30, $deferred->retryAt, 2, "attempt {$attempt} waits for Retry-After");
    }

    // The third attempt's failure stands, so the run can count it and move on.
    throws(HttpStatusException::class, fn() => $step->get('https://busy.example/page', ['delay_ms' => 0]), 'third attempt');
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
