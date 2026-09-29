<?php

/**
 * No other website is named, linked or credited in anything the API sends to be shown: sources are
 * "Source N", and text that came from elsewhere (errors, notes copied from a page, old run logs) has its
 * addresses and names replaced on the way out.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Support\Clock;
use App\Support\Json;
use App\Support\Neutral;
use Tests\Support\Client;

bootTests();

/** Every string in a decoded answer, keys included, as "path: value" lines. */
function stringsOf(mixed $value, string $path = ''): array
{
    if (is_string($value)) {
        return ["{$path}: {$value}"];
    }
    $lines = [];
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $lines[] = "{$path}.{$key}: (key)";
            array_push($lines, ...stringsOf($item, "{$path}.{$key}"));
        }
    }

    return $lines;
}

/**
 * Fails naming the first string that mentions another site. The only address an answer may carry is the
 * anotoki IAM's (meta says where to sign in).
 */
function assertNeutral(Tests\Support\Answer $answer, string $what): void
{
    $answer->status(200);
    foreach (stringsOf($answer->json()) as $line) {
        $line = str_replace(TEST_ISSUER, '(the IAM)', $line);
        check(! Neutral::mentionsAny($line) && ! preg_match('~https?://~i', $line), "{$what} names another site: {$line}");
    }
}

test('addresses and names of other sites become neutral words', function () {
    $cases = [
        'HTTP 403 from https://www.prydwen.gg/star-rail/characters/kafka' => 'HTTP 403 from the source page',
        'cURL error 6: Could not resolve host: enka.network' => 'cURL error 6: Could not resolve host: the source',
        'Prydwen has no curated build for this character yet.' => 'The source has no curated build for this character yet.',
        'Game data from gi.yatta.moe was refreshed.' => 'Game data from the source was refreshed.',
        'Exported by Inventory Kamera; see www.example.org/help.' => 'Exported by the source; see the source page',
        'Line one.'."\n".'genshin.gg lists 3 builds.' => 'Line one.'."\n".'The source lists 3 builds.',
    ];
    foreach ($cases as $text => $expected) {
        same($expected, Neutral::text($text), $text);
    }

    // Game names, places and the app's own words stay as they are.
    foreach (['Genshin Impact', 'Honkai: Star Rail', 'Enkanomiya', 'Hu Tao', 'Source 2 (Genshin Impact)', 'genshin-2', 'build analyzer'] as $text) {
        same($text, Neutral::text($text), $text);
        same(false, Neutral::mentionsAny($text), "{$text} mentions nothing");
    }
    same(null, Neutral::text(null), 'null');
    same(['notes' => ['The source page', 'ok']], Neutral::deep(['notes' => ['https://game8.co/x', 'ok']]), 'nested values');
});

test('what the Sources, build and account pages get names no other site', function () {
    seedGenshinCatalog();
    seedUser(1, 'owner');
    $now = Clock::now();

    // A source that failed with the site's address in the error, and a build copied from its page.
    $source = (int) db()->insert('build_sources', ['key' => 'prydwen.genshin', 'game' => 'genshin', 'name' => 'Prydwen', 'base_url' => 'https://www.prydwen.gg',
        'last_status' => 'failed', 'last_error' => 'HTTP 500 from https://www.prydwen.gg/genshin/characters/hu-tao', 'last_synced_at' => $now]);
    db()->insert('builds', [
        'build_source_id' => $source, 'game_character_id' => idOf('game_characters', 'hutao'), 'variant' => 'Prydwen pick', 'recommended' => true,
        'weapons' => Json::encode([['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => null, 'note' => 'Best in slot per genshin-builds.com', 'known' => true]]),
        'sets' => Json::encode([['rank' => 1, 'kind' => 'artifact', 'choose' => null, 'note' => 'See https://keqingmains.com/hu-tao', 'pieces' => [
            ['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']],
        ]]]),
        'main_stats' => Json::encode(['goblet' => [['stat' => 'pyro_dmg_', 'priority' => 1]]]),
        'substats' => Json::encode([]),
        'stat_targets' => Json::encode([['stat' => 'critRate_', 'min' => 60, 'max' => null, 'text' => 'CRIT Rate 60% (Prydwen)']]),
        'notes' => 'Copied from https://www.prydwen.gg/genshin/characters/hu-tao - thanks Prydwen!',
        'source_url' => 'https://www.prydwen.gg/genshin/characters/hu-tao',
        'content_hash' => 'x', 'fetched_at' => $now, 'changed_at' => $now,
    ]);
    // A run log written by an older version, which named the sites, and names it could not match as a
    // page spelled them.
    $run = (int) db()->insert('sync_runs', ['build_source_id' => $source, 'kind' => 'builds', 'game' => 'genshin', 'status' => 'failed',
        'started_at' => $now, 'finished_at' => $now, 'log' => "Prydwen (Genshin Impact): fetching https://www.prydwen.gg/genshin/characters\nFailed: enka.network did not answer",
        'stats' => Json::encode(['errors' => 1, 'unmatched' => ['genshin:weapons' => ['Prydwen favourite from https://www.prydwen.gg']]])]);
    $account = seedAccount(1, 'genshin', ['uid' => '700000001', 'nickname' => 'Main']);
    db()->insert('import_runs', ['user_id' => 1, 'account_id' => $account, 'game' => 'genshin', 'source' => 'enka', 'status' => 'failed',
        'error' => 'enka.network answered HTTP 424 for https://enka.network/api/uid/700000001', 'created_at' => $now, 'updated_at' => $now]);

    $admin = (new Client)->asIam(1, ['MEMBER', 'ADMIN']);
    assertNeutral($admin->get('/api/sources'), 'the sources');
    $sources = $admin->get('/api/sources')->json();
    check(in_array('Source 2', array_column($sources['sources'] ?? $sources, 'name'), true), 'the failed source is "Source 2": '.json_encode($sources));
    assertNeutral($admin->get('/api/sync-runs'), 'the runs');
    assertNeutral($admin->get("/api/sync-runs/{$run}"), 'a run with its log');
    assertNeutral($admin->get('/api/builds/genshin'), 'the builds of a game');
    $builds = $admin->get('/api/builds/genshin/hutao');
    assertNeutral($builds, 'a character\'s builds');
    same(null, $builds->json('builds.0.source_url'), 'the page a build came from is not sent');
    $file = $admin->get('/api/sources/genshin-2/export');
    assertNeutral($file, 'a builds file');
    same(1, count($file->json('builds')), 'the file holds the build');
    assertNeutral($admin->get("/api/accounts/{$account}/imports"), 'the imports of an account');
    assertNeutral($admin->get('/api/meta'), 'meta');
});

finishTests();
