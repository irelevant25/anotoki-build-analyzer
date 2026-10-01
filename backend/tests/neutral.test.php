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
function assertNeutral(Tests\Support\Answer $answer, string $what, int $status = 200): void
{
    $answer->status($status);
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
        'weapons' => Json::encode([['key' => 'staffofhoma', 'name' => 'Staff of Homa (prydwen.gg)','rank' => 1, 'refinement' => null, 'note' => 'Best in slot per genshin-builds.com', 'known' => true]]),
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
    // Runs whose kinds carry parameters, which quote what an uploaded file said - here a site's name where a
    // UID belongs, and a site's name and address where a set's name does.
    db()->insert('import_runs', ['user_id' => 1, 'account_id' => $account, 'game' => 'genshin', 'source' => 'good', 'status' => 'failed',
        'summary' => Json::encode(['error' => ['code' => 'account_uid_mismatch', 'params' => ['data_uid' => 'enka.network', 'account_uid' => '700000001']]]),
        'error' => 'The data belongs to UID the source, but the selected account is UID 700000001.', 'created_at' => $now, 'updated_at' => $now]);
    db()->insert('import_runs', ['user_id' => 1, 'account_id' => $account, 'game' => 'genshin', 'source' => 'good', 'status' => 'partial',
        'summary' => Json::encode(['characters' => 1, 'weapons' => 0, 'items' => 0, 'full_inventory' => true, 'tool' => 'Inventory Kamera',
            'warnings' => ['Unknown set {"name":"Prydwen"} was skipped.', 'Unknown weapon {"name":"https://www.prydwen.gg/blade"} was skipped.'],
            'warning_kinds' => [
                ['code' => 'unknown_set', 'params' => ['ref' => 'Prydwen']],
                ['code' => 'unknown_weapon', 'params' => ['weapon_label' => 'Weapon', 'ref' => 'https://www.prydwen.gg/blade']],
            ],
            'unmatched' => ['genshin:sets' => ['prydwen.gg favourite']]]),
        'created_at' => $now, 'updated_at' => $now]);

    $admin =(new Client)->asIam(1, ['MEMBER', 'ADMIN']);
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
    $imports = $admin->get("/api/accounts/{$account}/imports");
    assertNeutral($imports, 'the imports of an account');
    // The parameters are there (newest first), with neutral words where the file named a site.
    same(['code' => 'unknown_set', 'params' => ['ref' => 'The source']], $imports->json('0.summary.warning_kinds.0'), 'a warning\'s parameters');
    same(['code' => 'unknown_weapon', 'params' => ['weapon_label' => 'Weapon', 'ref' => 'The source page']], $imports->json('0.summary.warning_kinds.1'), 'an address in a parameter');
    same(1, count($imports->json('0.summary.unmatched.genshin:sets') ?? []), 'an unmatched name is still sent, neutral: '.json_encode($imports->json('0.summary.unmatched')));
    same('account_uid_mismatch', $imports->json('1.error_code'), 'a failed run\'s code');
    same(['data_uid' => 'The source', 'account_uid' => '700000001'], $imports->json('1.error_params'), 'a failed run\'s parameters');
    same(null, $imports->json('2.error_code'), 'the run of an older version has no code');

    // A character's analysis quotes the build's names in its recommendations: the weapons to aim for here,
    // with none equipped - in the kind's parameters and in the English sentence beside them.
    db()->insert('account_characters', ['account_id' => $account, 'game_character_id' => idOf('game_characters', 'hutao'), 'level' => 90, 'rank' => 0, 'source' => 'good']);
    $analysis = $admin->get("/api/accounts/{$account}/characters/hutao");
    assertNeutral($analysis, 'a character\'s analysis');
    $weapon = array_values(array_filter($analysis->json('evaluations.0.recommendations') ?? [], fn (array $r) => ($r['kind'] ?? null) === 'weapon_none'));
    same('Staff of Homa (the source)', $weapon[0]['params']['options'] ?? null, 'the weapon to aim for, neutral: '.json_encode($analysis->json()));
    assertNeutral($admin->get("/api/accounts/{$account}"), 'the account, with its characters\' top issues');

    // A file that gives a site's name where its UID belongs: the refusal's sentence quotes it.
    $rail = seedAccount(1, 'hsr', ['uid' => '700000002', 'nickname' => 'Rail']);
    $file = (string) json_encode(['source' => 'reliquary_archiver', 'version' => 4, 'metadata' => ['uid' => 'see prydwen.gg'],
        'light_cones' => [], 'relics' => [], 'characters' => [['id' => '1005', 'name' => 'Kafka', 'level' => 80]]]);
    $refusal = $admin->post('/api/imports/file', ['content' => $file, 'account_id' => $rail]);
    assertNeutral($refusal, 'an import\'s refusal', 422);
    same('account_uid_mismatch', $refusal->json('code'), 'refused for its UID');
    assertNeutral($admin->get('/api/meta'), 'meta');
});

test('the site\'s own words name no other site, in English and in Slovak', function () {
    // Every string a page shows comes out of these two answers, so what the migrations seeded is held here:
    // keys, English and Slovak. While no migration has written a string the bundle is not there to be read
    // (503), and the answers are looked at with one key of the test's own.
    $seeded = (new Client)->get('/api/translations/en')->status === 200;
    if (! $seeded) {
        echo "wait  the seeded strings of /api/translations/en and /sk name no other site - until a migration inserts into translation_keys\n";
        db()->run("INSERT INTO translation_keys (name, description) VALUES ('test.neutral', 'A probe')");
        db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.neutral', 'en', 'Builds from Source 1'), ('test.neutral', 'sk', 'Buildy zo zdroja Source 1')");
    }
    try {
        $english = (new Client)->get('/api/translations/en');
        assertNeutral($english, 'the English strings');
        $slovak = (new Client)->get('/api/translations/sk');
        assertNeutral($slovak, 'the Slovak strings');
        same('sk', $slovak->json('language'), 'in Slovak');
        check(count($slovak->json('values')) === count($english->json('values')) && $english->json('values') !== [], 'every key, in both');

        // What the owner's pages read: the grid with its descriptions, and the languages.
        $admin = (new Client)->asIam(1, ['ADMIN']);
        assertNeutral($admin->get('/api/admin/translations'), 'the grid, with the keys\' descriptions');
        assertNeutral($admin->get('/api/admin/languages'), 'the languages');

        // And the check does find a site's name in such an answer.
        db()->run("INSERT INTO translation_keys (name) VALUES ('test.named') ON CONFLICT DO NOTHING");
        db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.named', 'sk', 'Pozri Prydwen')");
        $named = throws(AssertionFailed::class, fn () => assertNeutral((new Client)->get('/api/translations/sk'), 'a bundle that names a site'), 'a site\'s name in a string');
        check(str_contains($named->getMessage(), 'test.named: Pozri Prydwen'), $named->getMessage());
    } finally {
        db()->run("DELETE FROM translation_keys WHERE name IN ('test.neutral', 'test.named')");
    }
});

finishTests();
