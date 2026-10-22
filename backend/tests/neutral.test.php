<?php

/**
 * No other website is named, linked or credited in anything the API sends to be shown: text that came from
 * elsewhere (errors, a builds file's notes and names, what an uploaded account file said) has its addresses and
 * names replaced on the way out - a builds file's on the way in too.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

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
        check(!Neutral::mentionsAny($line) && !preg_match('~https?://~i', $line), "{$what} names another site: {$line}");
    }
}

test('addresses and names of other sites become neutral words', function () {
    // The names config/neutral.local.php adds - a fictitious site here: the repository names no real one.
    Neutral::setLocalNames(['Fictional Builds', 'fictional-builds.example', '  ', 7]);
    try {
        same(['fictional builds', 'fictional-builds.example'], Neutral::localNames(), 'the names with something in them, lowercased');
        $cases = [
            'HTTP 403 from https://builds.example/characters/kafka' => 'HTTP 403 from the source page',
            'cURL error 6: Could not resolve host: enka.network' => 'cURL error 6: Could not resolve host: the source',
            'Fictional Builds has no curated build for this character yet.' => 'The source has no curated build for this character yet.',
            'Game data from gi.yatta.moe was refreshed.' => 'Game data from the source was refreshed.',
            'Exported by Inventory Kamera; see www.example.org/help.' => 'Exported by the source; see the source page',
            'Line one.' . "\n" . 'fictional-builds.example lists 3 builds.' => 'Line one.' . "\n" . 'The source lists 3 builds.',
        ];
        foreach ($cases as $text => $expected) {
            same($expected, Neutral::text($text), $text);
        }
        same(['fictional-builds.example', 'enka'], Neutral::namesIn('fictional-builds.example and Enka'), 'a domain whole, before the name inside it');
    } finally {
        Neutral::setLocalNames([]);
    }
    same('Fictional Builds lists 3 builds.', Neutral::text('Fictional Builds lists 3 builds.'), 'without the file only the names Neutral holds itself');

    // Game names, places and the app's own words stay as they are.
    foreach (['Genshin Impact', 'Enkanomiya', 'Hu Tao', 'build analyzer'] as $text) {
        same($text, Neutral::text($text), $text);
        same(false, Neutral::mentionsAny($text), "{$text} mentions nothing");
    }
    same(null, Neutral::text(null), 'null');
    same(['notes' => ['The source page', 'ok']], Neutral::deep(['notes' => ['https://elsewhere.example/x', 'ok']]), 'nested values');
});

test('config/neutral.local.php is read cleanly or not at all: a broken one adds no name', function () {
    $root = T::$storage . '/neutral-root';
    @mkdir($root . '/config', 0775, true);
    $file = $root . '/config/' . Neutral::LOCAL_FILE;
    try {
        same(null, App\Config::readOptionalFile(Neutral::LOCAL_FILE, $root), 'no file, nothing');
        file_put_contents($file, "<?php return ['names' => ['Fictional Builds'], 'tree_words' => ['fictionalbuilds']];");
        same(['names' => ['Fictional Builds'], 'tree_words' => ['fictionalbuilds']], App\Config::readOptionalFile(Neutral::LOCAL_FILE, $root), 'a clean one, as written');
        foreach (["\xEF\xBB\xBF<?php return ['names' => ['x']];", "\n<?php return ['names' => ['x']];", "<?php echo 'x'; return ['names' => ['x']];", '<?php return [;'] as $broken) {
            file_put_contents($file, $broken);
            ob_start();
            $value = App\Config::readOptionalFile(Neutral::LOCAL_FILE, $root);
            same('', ob_get_clean(), 'nothing of it printed');
            same(null, $value, 'passed over: ' . json_encode(substr($broken, 0, 12)));
        }
    } finally {
        @unlink($file);
    }
});

test('what the builds browser, the Builds page and the account pages get names no other site', function () {
    // The names config/neutral.local.php adds - a fictitious site here: the repository names no real one -, as its
    // name, its address and the key a builds file would spell it as.
    Neutral::setLocalNames(['Fictional Builds', 'fictional-builds.example', 'fictionalbuilds']);
    seedGenshinCatalog();
    seedUser(1, 'owner');
    $now = Clock::now();

    // A build whose every text names the site or links to it - written straight into the table: an import makes a
    // file's texts neutral on the way in, but what goes out passes through Neutral again (a name the owner adds to
    // the file later is kept out of the builds imported before it).
    db()->insert('builds', [
        'game_character_id' => idOf('game_characters', 'hutao'),
        'role' => 'main_dps',
        'qualifier' => 'Fictional Builds pick',
        'min_rank' => 0,
        'position' => 0,
        'weapons' => Json::encode([
            ['key' => 'staffofhoma', 'name' => 'Staff of Homa (fictional-builds.example)', 'rank' => 1, 'refinement' => 1, 'note' => 'Best in slot per Fictional Builds', 'known' => true],
        ]),
        'sets' => Json::encode([
            [
                'rank' => 1,
                'kind' => 'artifact',
                'choose' => null,
                'pieces' => [
                    [
                        'label' => 'Crimson Witch of Flames (Fictional Builds)',
                        'count' => 4,
                        'keys' => ['crimsonwitchofflames'],
                        'names' => ['Crimson Witch of Flames, as fictional-builds.example has it'],
                    ],
                ],
                'note' => 'See https://fictional-builds.example/hu-tao',
            ],
        ]),
        'main_stats' => Json::encode(['goblet' => [['stat' => 'pyro_dmg_', 'priority' => 1]]]),
        'substats' => Json::encode([]),
        'stat_targets' => Json::encode([['stat' => 'critRate_', 'min' => 60, 'max' => null, 'text' => 'CRIT Rate 60% (Fictional Builds)', 'from_rank' => 0]]),
        'notes' => 'Copied from https://fictional-builds.example/genshin/hu-tao - thanks Fictional Builds!',
        'game_version' => '5.4',
        'content_hash' => 'x',
        'checked_at' => $now,
        'changed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $account = seedAccount(1, 'genshin', ['uid' => '700000001', 'nickname' => 'Main']);
    db()->insert('import_runs', [
        'user_id' => 1,
        'account_id' => $account,
        'game' => 'genshin',
        'source' => 'enka',
        'status' => 'failed',
        'error' => 'enka.network answered HTTP 424 for https://enka.network/api/uid/700000001',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    // Runs whose kinds carry parameters, which quote what an uploaded file said - here a site's name where a
    // UID belongs, and a site's name and address where a set's name does.
    db()->insert('import_runs', [
        'user_id' => 1,
        'account_id' => $account,
        'game' => 'genshin',
        'source' => 'good',
        'status' => 'failed',
        'summary' => Json::encode(['error' => ['code' => 'account_uid_mismatch', 'params' => ['data_uid' => 'enka.network', 'account_uid' => '700000001']]]),
        'error' => 'The data belongs to UID the source, but the selected account is UID 700000001.',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    db()->insert('import_runs', [
        'user_id' => 1,
        'account_id' => $account,
        'game' => 'genshin',
        'source' => 'good',
        'status' => 'partial',
        'summary' => Json::encode([
            'characters' => 1,
            'weapons' => 0,
            'items' => 0,
            'full_inventory' => true,
            'tool' => 'Inventory Kamera',
            'warnings' => ['Unknown set {"name":"Fictional Builds"} was skipped.', 'Unknown weapon {"name":"https://fictional-builds.example/blade"} was skipped.'],
            'warning_kinds' => [
                ['code' => 'unknown_set', 'params' => ['ref' => 'Fictional Builds']],
                ['code' => 'unknown_weapon', 'params' => ['weapon_label' => 'Weapon', 'ref' => 'https://fictional-builds.example/blade']],
            ],
            'unmatched' => ['genshin:sets' => ['fictional-builds.example favourite']],
        ]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $admin = (new Client())->asIam(1, ['ADMIN']);
    assertNeutral($admin->get('/api/builds'), 'the builds of every game');
    assertNeutral($admin->get('/api/builds/genshin'), 'the builds of a game');
    $builds = $admin->get('/api/builds/genshin/hutao');
    assertNeutral($builds, 'a character\'s builds');
    same(
        ['The source pick', 'Staff of Homa (the source)', 'See the source page'],
        [$builds->json('builds.0.qualifier'), $builds->json('builds.0.weapons.0.name'), $builds->json('builds.0.sets.0.note')],
        'its texts, in neutral words',
    );
    assertNeutral($admin->get('/api/admin/builds'), 'the Builds page');
    $export = $admin->get('/api/admin/builds/genshin/export');
    assertNeutral($export, 'a builds file');
    same(['The source pick'], array_column($export->json('builds'), 'qualifier'), 'the file holds the build');
    $imports = $admin->get("/api/accounts/{$account}/imports");
    assertNeutral($imports, 'the imports of an account');
    // The parameters are there (newest first), with neutral words where the file named a site.
    same(['code' => 'unknown_set', 'params' => ['ref' => 'The source']], $imports->json('0.summary.warning_kinds.0'), 'a warning\'s parameters');
    same(['code' => 'unknown_weapon', 'params' => ['weapon_label' => 'Weapon', 'ref' => 'The source page']], $imports->json('0.summary.warning_kinds.1'), 'an address in a parameter');
    same(1, count($imports->json('0.summary.unmatched.genshin:sets') ?? []), 'an unmatched name is still sent, neutral: ' . json_encode($imports->json('0.summary.unmatched')));
    same('account_uid_mismatch', $imports->json('1.error_code'), 'a failed run\'s code');
    same(['data_uid' => 'The source', 'account_uid' => '700000001'], $imports->json('1.error_params'), 'a failed run\'s parameters');
    same(null, $imports->json('2.error_code'), 'the run of an older version has no code');

    // A character's analysis quotes the build's names in its recommendations: the weapons to aim for here,
    // with none equipped - in the kind's parameters and in the English sentence beside them.
    db()->insert('account_characters', ['account_id' => $account, 'game_character_id' => idOf('game_characters', 'hutao'), 'level' => 90, 'rank' => 0, 'source' => 'good']);
    $analysis = $admin->get("/api/accounts/{$account}/characters/hutao");
    assertNeutral($analysis, 'a character\'s analysis');
    $weapon = array_values(array_filter($analysis->json('evaluations.0.recommendations') ?? [], fn(array $r) => ($r['kind'] ?? null) === 'weapon_none'));
    same('Staff of Homa (the source)', $weapon[0]['params']['options'] ?? null, 'the weapon to aim for, neutral: ' . json_encode($analysis->json()));
    assertNeutral($admin->get("/api/accounts/{$account}"), 'the account, with its characters\' top issues');

    // A builds file that names the site wherever it has text, with a character this catalog lacks named after it:
    // an import's answers - a dry run's too - name it nowhere, nor the build it removes (the one above, which the
    // file does not have), and neither does the Builds page with the import listed.
    $kazuha = [
        'character' => 'kaedeharakazuha',
        'character_name' => 'Kaedehara Kazuha (Fictional Builds)',
        'role' => 'support',
        'qualifier' => 'Fictional Builds',
        'min_rank' => 0,
        'weapons' => [['key' => 'freedomsworn', 'name' => 'Freedom-Sworn (fictional-builds.example)', 'refinement' => null, 'note' => 'Per https://fictional-builds.example/kazuha']],
        'sets' => [
            [
                'kind' => 'artifact',
                'choose' => null,
                'pieces' => [
                    ['label' => 'Viridescent Venerer (Fictional Builds)', 'count' => 4, 'keys' => ['viridescentvenerer'], 'names' => ['Viridescent Venerer, as fictional-builds.example has it']],
                ],
                'note' => 'Fictional Builds says so.',
            ],
        ],
        'main_stats' => ['sands' => [['stat' => 'eleMas', 'priority' => 1]]],
        'substats' => [['stat' => 'eleMas', 'priority' => 1]],
        'talent_priority' => null,
        'stat_targets' => [['stat' => 'eleMas', 'min' => 800, 'max' => null, 'text' => '800 EM (fictional-builds.example)', 'from_rank' => 0]],
        'teams' => [
            [
                'name' => 'Fictional Builds Swirl',
                'members' => [
                    ['key' => 'kaedeharakazuha', 'name' => 'Kaedehara Kazuha', 'role' => 'support', 'min_rank' => 0, 'alternatives' => []],
                    [
                        'key' => 'xiangling',
                        'name' => 'Xiangling of fictional-builds.example',
                        'role' => null,
                        'min_rank' => 0,
                        'alternatives' => [['key' => 'bennett', 'name' => 'Bennett (Fictional Builds)']],
                    ],
                ],
                'note' => 'As https://fictional-builds.example/teams plays it',
            ],
        ],
        'notes' => 'From https://fictional-builds.example/kazuha - thanks Fictional Builds!',
        'game_version' => '5.4',
    ];
    $file = [
        'format' => 'build-analyzer.builds',
        'format_version' => 3,
        'game' => 'genshin',
        'exported_at' => '2026-10-01T10:00:00Z',
        // The mascot's build is Kazuha's under another key: a team of Kazuha's is none of its.
        'builds' => [$kazuha, ['character' => 'fictionalbuilds', 'character_name' => 'Fictional Builds mascot', 'teams' => []] + $kazuha],
    ];
    assertNeutral($admin->post('/api/admin/builds/genshin/import?dry_run=1', $file), 'a dry run\'s answer');
    $imported = $admin->post('/api/admin/builds/genshin/import', $file);
    assertNeutral($imported, 'an import\'s answer');
    same(
        [1, 1, 1],
        [$imported->json('created'), $imported->json('removed'), count($imported->json('skipped'))],
        'the file\'s build created, the one above removed, the character the catalog lacks skipped',
    );
    assertNeutral($admin->get('/api/admin/builds'), 'the Builds page, the import listed');
    assertNeutral($admin->get('/api/builds/genshin/kaedeharakazuha'), 'the build imported');

    assertNeutral($admin->get('/api/meta'), 'meta');
});

test('the site\'s own words name no other site, in English and in Slovak', function () {
    // Every string a page shows comes out of these two answers, so what the migrations seeded is held here:
    // keys, English and Slovak. While no migration has written a string the bundle is not there to be read
    // (503), and the answers are looked at with one key of the test's own.
    $seeded = (new Client())->get('/api/translations/en')->status === 200;
    if (!$seeded) {
        echo "wait  the seeded strings of /api/translations/en and /sk name no other site - until a migration inserts into translation_keys\n";
        db()->run("INSERT INTO translation_keys (name, description) VALUES ('test.neutral', 'A probe')");
        db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.neutral', 'en', 'Builds from Source 1'), ('test.neutral', 'sk', 'Buildy zo zdroja Source 1')");
    }
    try {
        $english = (new Client())->get('/api/translations/en');
        assertNeutral($english, 'the English strings');
        $slovak = (new Client())->get('/api/translations/sk');
        assertNeutral($slovak, 'the Slovak strings');
        same('sk', $slovak->json('language'), 'in Slovak');
        check(count($slovak->json('values')) === count($english->json('values')) && $english->json('values') !== [], 'every key, in both');

        // What the owner's pages read: the grid with its descriptions, and the languages.
        $admin = (new Client())->asIam(1, ['ADMIN']);
        assertNeutral($admin->get('/api/admin/translations'), 'the grid, with the keys\' descriptions');
        assertNeutral($admin->get('/api/admin/languages'), 'the languages');

        // And the check does find a site's name in such an answer.
        db()->run("INSERT INTO translation_keys (name) VALUES ('test.named') ON CONFLICT DO NOTHING");
        db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.named', 'sk', 'Pozri Fribbels')");
        $named = throws(AssertionFailed::class, fn() => assertNeutral((new Client())->get('/api/translations/sk'), 'a bundle that names a site'), 'a site\'s name in a string');
        check(str_contains($named->getMessage(), 'test.named: Pozri Fribbels'), $named->getMessage());
    } finally {
        db()->run("DELETE FROM translation_keys WHERE name IN ('test.neutral', 'test.named')");
    }
});

finishTests();
