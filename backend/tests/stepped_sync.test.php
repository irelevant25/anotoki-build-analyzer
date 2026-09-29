<?php

/**
 * Syncs without a queue worker or scheduler: an admin starts them and the browser drives them with short
 * step requests; progress lives in the database, so a closed tab only pauses a run.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Auth\Role;
use App\Cache\Locks;
use App\Cli;
use App\Commands\SyncBuilds;
use App\Enums\Game;
use App\Services\Catalog\CatalogSync;
use App\Services\Sync\SyncRunner;
use App\Support\Clock;
use Tests\Support\Client;
use Tests\Support\FakeBuildSource;
use Tests\Support\FakeHttpClient;

bootTests();

beforeEach(function () {
    seedGenshinCatalog();
    T::$app->config->set('sources', [FakeBuildSource::class]);
});

$admin = fn () => (new Client)->asIam(1, [Role::ADMIN], ['preferred_username' => 'admin']);
$run = fn (int $id) => db()->one('SELECT * FROM sync_runs WHERE id = ?', [$id]);
$builds = fn () => (int) db()->value('SELECT count(*) FROM builds');

test('an admin syncs a source in steps that respect the crawl delay', function () use ($admin, $run, $builds) {
    // Kazuha's page may only be fetched 10 s after Hu Tao's: more than this step has left.
    FakeBuildSource::$defer = ['Kaedehara Kazuha' => 1];
    $client = $admin();

    $start = $client->post('/api/sources/genshin-1/sync')->status(200)
        ->path('active', true)
        ->path('started.0.status', 'running')
        ->path('started.0.started_by.name', 'admin')
        ->path('started.0.progress', ['done' => 0, 'total' => null])
        ->path('started.0.source.name', 'Source 1');
    $runId = $start->json('started.0.id');

    // Step 1: the character list and Hu Tao; then the site asks for a pause.
    $step = $client->post('/api/sync/step')->status(200)
        ->path('busy', false)
        ->path('did.id', $runId)
        ->path('did.status', 'running')
        ->path('did.progress', ['done' => 1, 'total' => 2]);
    check($step->json('wait_ms') > 8000, 'wait_ms '.$step->json('wait_ms'));
    same(['Hu Tao'], FakeBuildSource::$fetched, 'fetched');
    same(1, $builds(), 'builds');

    // Too early: a step does nothing and says how long to wait.
    $client->post('/api/sync/step')->path('did', null)->path('active', true);
    same(['Hu Tao'], FakeBuildSource::$fetched, 'nothing more fetched');

    // The delay has passed: the next step continues with Kazuha and finishes the run.
    Clock::travel(11);
    $client->post('/api/sync/step')->path('did.status', 'success')->path('active', false)->path('wait_ms', null);

    same(['Hu Tao', 'Kaedehara Kazuha'], FakeBuildSource::$fetched, 'both fetched');
    same(2, $builds(), 'builds');
    $row = $run($runId);
    $stats = json_decode($row['stats'], true);
    same(2, $stats['characters_synced'], 'synced');
    same(1, $stats['characters_skipped'], 'not released yet');
    same(null, $row['state'], 'no state left');
    check(str_contains($row['log'], 'Started by admin.'), $row['log']);
    check(str_contains($row['log'], '3 characters listed.'), $row['log']);
    check(str_contains($row['log'], 'Done: success.'), $row['log']);
    same('success', db()->value("SELECT last_status FROM build_sources WHERE key = 'fake.genshin'"), 'source status');
});

test('a run nobody steps waits, and continues where it stopped', function () use ($admin) {
    FakeBuildSource::$defer = ['Kaedehara Kazuha' => 1];
    $client = $admin();
    $client->post('/api/sources/genshin-1/sync')->status(200);
    $client->post('/api/sync/step')->path('did.progress.done', 1);

    // The tab is closed for an hour: nothing happens meanwhile.
    Clock::travel(3600);
    $client->get('/api/sync')->status(200)
        ->path('active', true)
        ->path('wait_ms', 0)
        ->path('runs.0.status', 'running')
        ->path('runs.0.progress', ['done' => 1, 'total' => 2]);

    // Reopened: a new request (with a new runner) picks the run up from the database.
    $client->post('/api/sync/step')->path('did.status', 'success');
    same(['Hu Tao', 'Kaedehara Kazuha'], FakeBuildSource::$fetched, 'both fetched');
});

test('sync all skips sources synced recently and switched off', function () use ($admin) {
    $client = $admin();
    $client->get('/api/sources')->status(200); // makes the source's row
    db()->run("UPDATE build_sources SET last_status = 'success', last_synced_at = ? WHERE key = 'fake.genshin'", [Clock::now()->modify('-2 hours')]);

    $client->post('/api/sync/builds')->status(200)
        ->path('started', [])
        ->path('skipped.0.key', 'genshin-1')
        ->path('skipped.0.name', 'Source 1 (Genshin Impact)')
        ->path('skipped.0.reason', 'synced 2 hours ago');

    $client->patch('/api/sources/genshin-1', ['enabled' => false])->status(200);
    $client->post('/api/sync/builds', ['force' => true])->path('skipped.0.reason', 'switched off');
    same(0, (int) db()->value('SELECT count(*) FROM sync_runs'), 'no runs');
});

test('starting twice joins the run already going', function () use ($admin) {
    FakeBuildSource::$defer = ['Hu Tao' => 1];
    $client = $admin();
    $first = $client->post('/api/sources/genshin-1/sync')->json('started.0.id');
    $second = $client->post('/api/sources/genshin-1/sync')->json('started.0.id');

    same($first, $second, 'the same run');
    same(1, (int) db()->value('SELECT count(*) FROM sync_runs'), 'one run');
});

test('a stale catalog is refreshed first, in a run of its own', function () use ($admin) {
    // A catalog last refreshed a week ago.
    db()->run('UPDATE game_characters SET updated_at = ?', [Clock::now()->modify('-7 days')]);
    T::$http->fake([
        'gi.yatta.moe/api/v2/en/avatar' => FakeHttpClient::json(['data' => ['items' => ['10000046' => ['name' => 'Hu Tao', 'element' => 'Fire', 'rank' => 5]]]]),
        'gi.yatta.moe/api/v2/en/weapon' => FakeHttpClient::json(['data' => ['items' => ['13501' => ['name' => 'Staff of Homa', 'rank' => 5]]]]),
        'gi.yatta.moe/api/v2/en/reliquary' => FakeHttpClient::json(['data' => ['items' => ['15006' => ['name' => 'Crimson Witch of Flames']]]]),
    ]);
    $client = $admin();

    $start = $client->post('/api/sources/genshin-1/sync')->status(200);
    same(['catalog', 'builds'], array_column($start->json('runs'), 'kind'), 'runs');

    // The catalog run goes first; the build run waits for it.
    $client->post('/api/sync/step')->path('did.kind', 'catalog')->path('did.status', 'success');
    same([], FakeBuildSource::$fetched, 'no build page yet');
    same(3, count(T::$http->sent), 'three lists');

    $client->post('/api/sync/step')->path('did.kind', 'builds')->path('did.status', 'success');
    T::$app->resetScope();
    same(false, T::$app->get(CatalogSync::class)->isStale(Game::Genshin), 'the catalog is fresh');
});

test('a catalog refresh takes one list per unit of work', function () use ($admin) {
    T::$http->fake([
        'gi.yatta.moe/api/v2/en/avatar' => FakeHttpClient::json(['data' => ['items' => ['10000089' => ['name' => 'Furina', 'element' => 'Water', 'rank' => 5, 'weaponType' => 'WEAPON_SWORD_ONE_HAND']]]]),
        'gi.yatta.moe/api/v2/en/weapon' => FakeHttpClient::json(['data' => ['items' => []]]),
        'gi.yatta.moe/api/v2/en/reliquary' => FakeHttpClient::status(500),
        'sr.yatta.moe/*' => FakeHttpClient::json(['data' => ['items' => []]]),
    ]);
    $client = $admin();

    $client->post('/api/sync/catalog', ['games' => ['genshin']])->status(200)
        ->path('started.0.kind', 'catalog')
        ->path('started.0.game', 'genshin')
        ->path('started.0.progress', ['done' => 0, 'total' => 3]);

    // The failing set list is retried by later steps, then counted as an error.
    for ($i = 0; $i < 4 && $client->get('/api/sync')->json('active'); $i++) {
        $client->post('/api/sync/step');
        Clock::travel(60);
    }

    $row = db()->one("SELECT * FROM sync_runs WHERE kind = 'catalog'");
    same('partial', $row['status'], 'status');
    same(1, json_decode($row['stats'], true)['errors'], 'errors');
    same(1, (int) db()->value("SELECT count(*) FROM game_characters WHERE key = 'furina' AND external_id = '10000089'"), 'Furina');
});

test('cancelling stops a run without marking the source synced', function () use ($admin, $run) {
    FakeBuildSource::$defer = ['Kaedehara Kazuha' => 5];
    $client = $admin();
    $runId = $client->post('/api/sources/genshin-1/sync')->json('started.0.id');
    $client->post('/api/sync/step');

    $client->post("/api/sync-runs/{$runId}/cancel")->status(200)->path('status', 'cancelled');

    $client->get('/api/sync')->path('active', false);
    check(str_contains($run($runId)['log'], 'Cancelled by admin after 1 of 2.'), $run($runId)['log']);
    same(null, db()->value("SELECT last_synced_at FROM build_sources WHERE key = 'fake.genshin'"), 'not synced');
});

test('only one step runs at a time', function () use ($admin) {
    $client = $admin();
    $client->post('/api/sources/genshin-1/sync')->status(200);

    $lock = T::$app->get(Locks::class)->lock(SyncRunner::STEP_LOCK, 60);
    check($lock->get(), 'took the lock');
    try {
        $client->post('/api/sync/step')->path('busy', true)->path('did', null)->path('wait_ms', 1000);
    } finally {
        $lock->release();
    }

    $client->post('/api/sync/step')->path('busy', false)->path('did.status', 'success');
});

test('a step budget set too short still leaves room for a request', function () use ($admin) {
    // The fetcher keeps 8 s free for a request; a 5 s step would never send one and loop forever.
    T::$app->config->set('fetch.step_seconds', 5);
    FakeBuildSource::$viaFetcher = true;
    T::$http->fake(['fake-builds.example/*' => 'page']);
    $client = $admin();

    $client->post('/api/sources/genshin-1/sync')->status(200);
    $client->post('/api/sync/step')->path('did.status', 'success');

    same(2, count(T::$http->sent), 'two pages');
});

test('cancel answers 409 while a step holds the lock', function () use ($admin, $run) {
    $client = $admin();
    $runId = $client->post('/api/sources/genshin-1/sync')->json('started.0.id');

    $lock = T::$app->get(Locks::class)->lock(SyncRunner::STEP_LOCK, 60);
    $lock->get();
    try {
        $client->post("/api/sync-runs/{$runId}/cancel")->status(409);
    } finally {
        $lock->release();
    }
    same('running', $run($runId)['status'], 'still running');
});

test('the command line loops over the same steps', function () {
    FakeBuildSource::$defer = ['Kaedehara Kazuha' => 1];
    FakeBuildSource::$deferSeconds = 0.2;

    Cli::capture();
    $status = SyncBuilds::run(T::$app, ['sync-builds.php', 'fake.genshin', '--force']);
    $output = Cli::captured();

    same(0, $status, $output);
    check(str_contains($output, 'Source 1 (Genshin Impact): 3 characters listed.'), $output);
    check(str_contains($output, 'Kaedehara Kazuha: 1 build(s) - changed'), $output);
    same('success', db()->value('SELECT status FROM sync_runs'), 'status');
    same(['Hu Tao', 'Kaedehara Kazuha'], FakeBuildSource::$fetched, 'fetched');
});

test('the Sources page shows neutral names and no addresses', function () use ($admin) {
    db()->insert('build_sources', ['key' => 'fake.genshin', 'game' => 'genshin', 'name' => 'Fake Builds', 'base_url' => 'https://fake-builds.example',
        'last_status' => 'failed', 'last_error' => '  Hu Tao: ERROR HTTP 403 from https://fake-builds.example/hu-tao']);

    $answer = $admin()->get('/api/sources')->status(200)
        ->path('0.name', 'Source 1')
        ->path('0.key', 'genshin-1')
        ->path('0.last_error', '  Hu Tao: ERROR HTTP 403 from the source page');
    check(! str_contains($answer->body, 'fake-builds'), $answer->body);
    check(! array_key_exists('base_url', $answer->json()[0]), 'no base_url');
    same('Source 1', db()->value("SELECT name FROM build_sources WHERE key = 'fake.genshin'"), 'the stored name is neutral too');
});

finishTests();
