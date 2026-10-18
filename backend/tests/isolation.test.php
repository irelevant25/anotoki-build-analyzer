<?php

/**
 * Every person has their own accounts, imports and analysis: user B never sees or changes user A's.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Cli;
use App\Commands\ImportAccount;
use App\Services\Import\Enka\EnkaStore;
use Tests\Support\Client;
use Tests\Support\FakeHttpClient;

bootTests();

const ALICE = 1;
const BOB = 2;

$alices = 0;
beforeEach(function () use (&$alices) {
    seedGenshinCatalog();
    T::$app->set(
        EnkaStore::class,
        new class extends EnkaStore {
            public function __construct() {}

            public function genshinAvatar(string $key): ?array
            {
                return ['SkillOrder' => [10461, 10462, 10463], 'ProudMap' => []];
            }
        },
    );

    seedUser(ALICE, 'alice');
    seedUser(BOB, 'bob');
    $alices = seedAccount(ALICE, 'genshin', ['uid' => '700000001', 'nickname' => 'Alice main']);
    db()->insert('account_characters', ['account_id' => $alices, 'game_character_id' => idOf('game_characters', 'hutao'), 'level' => 90, 'source' => 'enka']);
});

function isolationGoodFile(): string
{
    return json_encode([
        'format' => 'GOOD',
        'version' => 2,
        'source' => 'Some Exporter',
        'characters' => [['key' => 'HuTao', 'level' => 80, 'constellation' => 0, 'ascension' => 5, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]]],
        'weapons' => [],
        'artifacts' => [],
    ]);
}

function isolationProfile(string $uid, string $nickname): array
{
    return [
        'uid' => $uid,
        'playerInfo' => ['nickname' => $nickname, 'level' => 55],
        'avatarInfoList' => [
            [
                'avatarId' => 10000046,
                'propMap' => ['4001' => ['val' => '90']],
                'skillLevelMap' => [],
                'equipList' => [],
            ],
        ],
    ];
}

$characters = fn(int $account) => (int) db()->value('SELECT count(*) FROM account_characters WHERE account_id = ?', [$account]);

test('a person lists only their own accounts', function () use (&$alices) {
    same(
        '[]',
        (new Client())
            ->asIam(BOB)
            ->get('/api/accounts')
            ->status(200)->body,
        'Bob has none',
    );

    $list = (new Client())
        ->asIam(ALICE)
        ->get('/api/accounts')
        ->status(200)
        ->json();
    same(1, count($list), 'Alice has one');
    same($alices, $list[0]['id'], 'hers');
});

test('another person\'s account is not found, whatever is asked', function () use (&$alices) {
    $id = $alices;
    $bob = (new Client())->asIam(BOB);

    $bob->get("/api/accounts/{$id}")->status(404);
    $bob->get("/api/accounts/{$id}/imports")->status(404);
    $bob->get("/api/accounts/{$id}/characters/hutao")->status(404);
    $bob->patch("/api/accounts/{$id}", ['nickname' => 'Mine now'])->status(404);
    $bob->delete("/api/accounts/{$id}")->status(404);

    same('Alice main', db()->value('SELECT nickname FROM accounts WHERE id = ?', [$id]), 'unchanged');

    // Alice herself gets all of it.
    (new Client())
        ->asIam(ALICE)
        ->get("/api/accounts/{$id}/characters/hutao")
        ->status(200)
        ->path('account.id', $id);
});

test('imports cannot target another person\'s account', function () use (&$alices, $characters) {
    $id = $alices;
    T::$http->fake(['enka.network/*' => FakeHttpClient::json(isolationProfile('700000001', 'Bob'))]);
    $bob = (new Client())->asIam(BOB);

    $bob->post('/api/imports/file', ['content' => isolationGoodFile(), 'account_id' => $id])
        ->status(422)
        ->path('message', 'The selected account does not exist.');
    $bob->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000001', 'account_id' => $id])
        ->status(422)
        ->path('message', 'The selected account does not exist.');

    same(1, $characters($id), 'Alice\'s account untouched');
    same(0, (int) db()->value('SELECT count(*) FROM import_runs'), 'no import recorded');
    same([], T::$http->sent, 'nothing downloaded');
});

test('two people can import the same UID into accounts of their own', function () use (&$alices) {
    T::$http->fake(['enka.network/*' => FakeHttpClient::json(isolationProfile('700000001', 'Traveler'))]);

    $bobs = (new Client())
        ->asIam(BOB)
        ->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000001'])
        ->status(201)
        ->json('account_id');
    check($bobs !== $alices, 'a new account for Bob');
    same(BOB, (int) db()->value('SELECT user_id FROM accounts WHERE id = ?', [$bobs]), 'Bob\'s');

    // Alice's import of that UID still lands in her own account.
    (new Client())
        ->asIam(ALICE)
        ->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000001'])
        ->status(201)
        ->path('account_id', $alices);

    same(2, (int) db()->value("SELECT count(*) FROM accounts WHERE uid = '700000001'"), 'two accounts');
    same([ALICE, BOB], array_map('intval', db()->column('SELECT user_id FROM import_runs ORDER BY user_id')), 'a run each');
});

test('a failed import is recorded for the person who tried it only', function () use (&$alices) {
    T::$http->fake(['enka.network/*' => FakeHttpClient::status(424)]);

    (new Client())
        ->asIam(BOB)
        ->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000001'])
        ->status(422);

    $failed = db()->one('SELECT * FROM import_runs');
    same('failed', $failed['status'], 'status');
    same(BOB, (int) $failed['user_id'], 'Bob\'s');
    // Not attached to Alice's account with the same UID.
    same(null, $failed['account_id'], 'no account');
    same(
        '[]',
        (new Client())
            ->asIam(ALICE)
            ->get("/api/accounts/{$alices}/imports")
            ->status(200)->body,
        'Alice sees nothing',
    );
});

test('the showcase import limit is per person', function () {
    T::$http->fake(['enka.network/*' => FakeHttpClient::status(404)]);

    for ($i = 0; $i < 10; $i++) {
        (new Client())
            ->asIam(BOB)
            ->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000009'])
            ->status(422);
    }
    (new Client())
        ->asIam(BOB)
        ->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000009'])
        ->status(429);

    // Bob's imports do not use up Alice's.
    (new Client())
        ->asIam(ALICE)
        ->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000009'])
        ->status(422);
});

test('two people\'s accounts of the same UID stay apart, down to deleting one', function () use (&$alices, $characters) {
    $bobs = seedAccount(BOB, 'genshin', ['uid' => '700000001', 'nickname' => 'Bob main']);
    db()->insert('account_characters', ['account_id' => $bobs, 'game_character_id' => idOf('game_characters', 'hutao'), 'level' => 40, 'source' => 'enka']);

    (new Client())
        ->asIam(BOB)
        ->delete("/api/accounts/{$bobs}")
        ->status(204);

    same(0, (int) db()->value('SELECT count(*) FROM accounts WHERE id = ?', [$bobs]), 'Bob\'s is gone');
    same(0, $characters($bobs), 'with its characters');
    same('Alice main', db()->value('SELECT nickname FROM accounts WHERE id = ?', [$alices]), 'Alice\'s stays');
    same(1, $characters($alices), 'with hers');
});

test('the command line imports for the given owner', function () use (&$alices, $characters) {
    $file = T::$storage . DIRECTORY_SEPARATOR . 'good.json';
    file_put_contents($file, isolationGoodFile());

    Cli::capture();
    $status = ImportAccount::run(T::$app, ['import-account.php', 'genshin', $file, '--user=5']);
    $output = Cli::captured();
    same(0, $status, 'exit status: ' . $output);
    same(1, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = 5'), 'an account of user 5');
    same('user 5', db()->value('SELECT name FROM users WHERE id = 5'), 'user 5 made');

    // Somebody else's account is not a target.
    Cli::capture();
    $status = ImportAccount::run(T::$app, ['import-account.php', 'genshin', $file, '--user=5', "--account={$alices}"]);
    $output = Cli::captured();
    same(1, $status, 'refused');
    check(str_contains($output, "User 5 has no account #{$alices}."), $output);
    same(1, $characters($alices), 'Alice\'s account untouched');
});

finishTests();
