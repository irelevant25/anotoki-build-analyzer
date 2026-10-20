<?php

/**
 * Imports: inventory files (GOOD), showcases by UID, how they land in an
 * account, and how an unknown in-game ID refreshes the catalog. The showcase service and the game data
 * provider are faked.
 *
 * What an import says - a problem, a warning - is a kind with parameters beside its English sentence
 * (ImportKinds): every kind is reached here with its sentence, and every site in the sources is read.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Enums\Game;
use App\Http\Fetcher;
use App\Http\HttpResponse;
use App\Models\User;
use App\Repositories\Accounts;
use App\Services\Import\AccountImporter;
use App\Services\Import\Enka\EnkaGenshinParser;
use App\Services\Import\Enka\EnkaStore;
use App\Services\Import\Files\GoodParser;
use App\Services\Import\ImportException;
use App\Services\Import\ImportKinds;
use App\Services\Import\ImportService;
use App\Support\Clock;
use App\Support\Json;
use Tests\Support\Client;
use Tests\Support\FakeHttpClient;

bootTests();

// The Genshin character data file, never downloaded in the tests.
$store = new class extends EnkaStore {
    public function __construct() {}

    public function genshinAvatar(string $key): ?array
    {
        return ['SkillOrder' => [10051, 10052, 10053], 'ProudMap' => ['10052' => 532, '10053' => 539]];
    }
};

beforeEach(function () use ($store) {
    T::$app->set(EnkaStore::class, $store);
    seedGenshinCatalog();
    seedUser(1, 'owner');
});

$owner = fn() => new User(1, 'owner');

function goodFile(): string
{
    return json_encode([
        'format' => 'GOOD',
        'version' => 3,
        'source' => 'Some Exporter',
        'characters' => [
            ['key' => 'HuTao', 'level' => 90, 'constellation' => 1, 'ascension' => 6, 'talent' => ['auto' => 10, 'skill' => 9, 'burst' => 8]],
            ['key' => 'TravelerAnemo', 'level' => 80, 'constellation' => 6, 'ascension' => 6, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]],
        ],
        'weapons' => [
            ['key' => 'StaffOfHoma', 'level' => 90, 'ascension' => 6, 'refinement' => 1, 'location' => 'HuTao', 'lock' => true],
            ['key' => 'DragonsBane', 'level' => 90, 'ascension' => 6, 'refinement' => 5, 'location' => '', 'lock' => false],
        ],
        'artifacts' => [
            [
                'setKey' => 'CrimsonWitchOfFlames',
                'slotKey' => 'flower',
                'level' => 20,
                'rarity' => 5,
                'mainStatKey' => 'hp',
                'location' => 'HuTao',
                'lock' => true,
                'substats' => [['key' => 'critRate_', 'value' => 10.5], ['key' => 'critDMG_', 'value' => 14.0], ['key' => 'atk', 'value' => 16], ['key' => '', 'value' => 0]],
            ],
            [
                'setKey' => 'CrimsonWitchOfFlames',
                'slotKey' => 'sands',
                'level' => 16,
                'rarity' => 5,
                'mainStatKey' => 'hp_',
                'location' => 'Traveler',
                'lock' => false,
                'substats' => [['key' => 'critDMG_', 'value' => 7.8]],
            ],
            ['setKey' => 'SomeFutureSet', 'slotKey' => 'goblet', 'level' => 20, 'rarity' => 5, 'mainStatKey' => 'pyro_dmg_', 'location' => '', 'lock' => false, 'substats' => []],
        ],
    ]);
}

function showcaseProfile(): array
{
    return [
        'uid' => '618285856',
        'playerInfo' => ['nickname' => 'Tester', 'level' => 57, 'worldLevel' => 8],
        'avatarInfoList' => [
            [
                'avatarId' => 10000005,
                'skillDepotId' => 504,
                'propMap' => ['4001' => ['val' => '90'], '1002' => ['val' => '6']],
                'talentIdList' => [1, 2, 3],
                'skillLevelMap' => ['10051' => 9, '10052' => 8, '10053' => 10],
                'proudSkillExtraLevelMap' => ['532' => 3],
                'fightPropMap' => ['2000' => 15000.4, '2001' => 2000.2, '20' => 0.652, '22' => 1.5, '23' => 1.805, '28' => 100],
                'equipList' => [
                    ['itemId' => 13501, 'weapon' => ['level' => 90, 'promoteLevel' => 6, 'affixMap' => ['113501' => 0]], 'flat' => ['itemType' => 'ITEM_WEAPON']],
                    [
                        'itemId' => 81543,
                        'reliquary' => ['level' => 21, 'appendPropIdList' => [501204, 501224, 501223, 501201]],
                        'flat' => [
                            'itemType' => 'ITEM_RELIQUARY',
                            'equipType' => 'EQUIP_BRACER',
                            'setId' => 15006,
                            'rankLevel' => 5,
                            'reliquaryMainstat' => ['mainPropId' => 'FIGHT_PROP_HP', 'statValue' => 4780],
                            'reliquarySubstats' => [['appendPropId' => 'FIGHT_PROP_CRITICAL', 'statValue' => 7.4], ['appendPropId' => 'FIGHT_PROP_CRITICAL_HURT', 'statValue' => 14.8]],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

$item = fn(string $where, array $params = []) => db()->one(
    "SELECT i.*, c.key AS equipped_key FROM account_items i LEFT JOIN game_characters c ON c.id = i.equipped_character_id WHERE {$where}",
    $params,
);

test('imports a GOOD file', function () use ($owner, $item) {
    $run = T::$app->get(ImportService::class)->fromFile(goodFile(), $owner(), null, 'export.json');

    $account = T::$app->get(Accounts::class)->findOwned(1, (int) $run->account_id);
    same(1, $account->user_id, 'the account is the owner\'s');
    same(1, $run->user_id, 'the run is the owner\'s');
    same(true, $account->has_full_inventory, 'full inventory');
    same('partial', $run->status, 'the unknown set is reported');
    same(['SomeFutureSet'], $run->summary['unmatched']['genshin:sets'], 'unmatched');
    same(2, $run->summary['characters'], 'characters');
    // The shared catalog does not grow from an uploaded file: the piece of the unknown set is skipped.
    same(2, $run->summary['items'], 'items');
    same(0, (int) db()->value("SELECT count(*) FROM game_sets WHERE key = 'somefutureset'"), 'no new set');
    check(in_array('Unknown set {"name":"SomeFutureSet"} was skipped.', $run->summary['warnings'], true), 'warning');
    // Each warning is a kind with its parameters too, index for index with the sentences.
    same(
        ['Unknown set {"name":"SomeFutureSet"} was skipped.', 'What the game catalog does not know yet was skipped. After an admin refreshes the catalog (Sources page), import again.'],
        $run->summary['warnings'],
        'the warnings',
    );
    same([['code' => 'unknown_set', 'params' => ['ref' => 'SomeFutureSet']], ['code' => 'catalog_behind', 'params' => []]], $run->summary['warning_kinds'], 'their kinds');

    $huTao = db()->one("SELECT ac.* FROM account_characters ac JOIN game_characters c ON c.id = ac.game_character_id WHERE c.key = 'hutao'");
    same(1, (int) $huTao['rank'], 'constellation');
    same(['normal' => 10, 'skill' => 9, 'burst' => 8], json_decode($huTao['talents'], true), 'talents');

    $flower = $item("i.slot = 'flower'");
    same((int) $huTao['game_character_id'], (int) $flower['equipped_character_id'], 'the flower is on Hu Tao');
    same(4780.0, (float) $flower['main_value'], 'main value computed');
    same(3, count(json_decode($flower['substats'], true)), 'the empty substat is dropped');

    // Equipment on "Traveler" goes to the Traveler the file lists.
    same('traveleranemo', $item("i.slot = 'sands'")['equipped_key'], 'the sands are on the Traveler');

    same(1, (int) db()->value('SELECT count(*) FROM account_weapons WHERE equipped_character_id IS NULL'), 'an unequipped weapon');
});

test('importing a full inventory again replaces the items', function () use ($owner) {
    $service = T::$app->get(ImportService::class);
    $first = $service->fromFile(goodFile(), $owner());
    $account = T::$app->get(Accounts::class)->findOwned(1, (int) $first->account_id);

    $service->fromFile(goodFile(), $owner(), $account);

    same(2, (int) db()->value('SELECT count(*) FROM account_items WHERE account_id = ?', [$account->id]), 'items');
    same(1, (int) db()->value('SELECT count(*) FROM accounts'), 'accounts');
});

test('a showcase import can complete an account made from a file', function () use ($owner) {
    $fromFile = T::$app->get(ImportService::class)->fromFile(goodFile(), $owner());
    $accountId = (int) $fromFile->account_id;
    same(null, db()->value('SELECT uid FROM accounts WHERE id = ?', [$accountId]), 'no UID yet');

    T::$http->fake(['enka.network/*' => FakeHttpClient::json(showcaseProfile())]);
    (new Client())
        ->asIam(1)
        ->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '618285856', 'account_id' => $accountId])
        ->status(201)
        ->path('account_id', $accountId)
        ->path('source', 'showcase');

    $account = db()->one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
    same('618285856', $account['uid'], 'UID');
    same('Tester', $account['nickname'], 'nickname');
    same(1, (int) db()->value('SELECT count(*) FROM accounts'), 'one account');
    // The showcase only replaced the showcased Traveler's gear (the sands became the flower it wears now);
    // the rest of the inventory is untouched.
    same(true, (bool) $account['has_full_inventory'], 'still a full inventory');
    same(['flower', 'flower'], db()->column('SELECT slot FROM account_items WHERE account_id = ? ORDER BY id', [$accountId]), 'items');
});

test('parses a Genshin showcase', function () use ($owner) {
    $parsed = T::$app->get(EnkaGenshinParser::class)->parse(showcaseProfile());

    same('Tester', $parsed->nickname, 'nickname');
    $character = $parsed->characters[0];
    same(['key' => 'traveleranemo', 'name' => 'Traveler (Anemo)'], $character['ref'], 'Traveler by element');
    same(3, $character['rank'], 'constellation');
    same(['normal' => 9, 'skill' => 8, 'burst' => 10, 'bonus' => ['skill' => 3]], $character['talents'], 'talents');
    same(['hp' => 15000.0, 'atk' => 2000.0, 'eleMas' => 100.0, 'critRate_' => 65.2, 'critDMG_' => 150.0, 'enerRech_' => 180.5], $character['final_stats'], 'totals');

    same(1, $parsed->weapons[0]['refinement'], 'refinement');
    $piece = $parsed->items[0];
    same('flower', $piece['slot'], 'slot');
    same(20, $piece['level'], 'level');
    same(['key' => 'critRate_', 'value' => 7.4, 'rolls' => 2], $piece['substats'][0], 'rolls from the roll list');
    same(['key' => 'critDMG_', 'value' => 14.8, 'rolls' => 2], $piece['substats'][1], 'rolls from the roll list');

    // And it lands on the right catalog rows.
    $run = T::$app->get(AccountImporter::class)->import($parsed, $owner());
    same('success', $run->status, 'status');
    same('Crimson Witch of Flames', db()->value('SELECT s.name FROM account_items i JOIN game_sets s ON s.id = i.game_set_id LIMIT 1'), 'set');
});

test('file imports through the API, and their errors', function () {
    $client = (new Client())->asIam(1);

    $imported = $client
        ->post('/api/imports/file', ['content' => goodFile(), 'file_name' => 'x.json'])
        ->status(201)
        ->path('summary.characters', 2)
        ->path('account.game', 'genshin')
        ->path('source', 'file')
        ->path('summary.warnings.0', 'Unknown set {"name":"SomeFutureSet"} was skipped.')
        ->path('summary.warning_kinds.0', ['code' => 'unknown_set', 'params' => ['ref' => 'SomeFutureSet']])
        ->path('summary.warning_kinds.1', ['code' => 'catalog_behind', 'params' => []])
        ->path('error', null)
        ->path('error_code', null)
        ->path('error_params', null);
    same(count($imported->json('summary.warnings')), count($imported->json('summary.warning_kinds')), 'as many kinds as warnings');
    check(str_contains($imported->body, '{"code":"catalog_behind","params":{}}'), 'no parameters are sent as an object: ' . $imported->body);

    // A problem is {message, code, params}: the sentence as it always was, and what a page words itself.
    $notJson = $client
        ->post('/api/imports/file', ['content' => 'not json'])
        ->status(422)
        ->path('message', 'The file is not valid JSON.')
        ->path('code', 'file_not_json')
        ->path('params', []);
    same('{"message":"The file is not valid JSON.","code":"file_not_json","params":{}}', $notJson->body, 'the whole answer');

    // An upload as a file, as a script would send it.
    $path = T::$storage . DIRECTORY_SEPARATOR . 'upload.json';
    file_put_contents($path, goodFile());
    $client
        ->request('POST', '/api/imports/file', null, ['files' => ['file' => [$path, 'export.json']], 'form' => []])
        ->status(201)
        ->path('file_name', 'export.json');

    // Nothing in what the API sends names the exporter the file came from.
    $answer = $client->get('/api/accounts');
    check(!str_contains($answer->body, 'Some Exporter'), 'the tool is not sent: ' . $answer->body);
});

test('showcase import errors through the API', function () {
    T::$http->fake(['enka.network/*' => FakeHttpClient::status(404)]);
    $client = (new Client())->asIam(1);

    // A UID of the wrong length never leaves the form: the input check refuses it (code "invalid", by field).
    $client
        ->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '123'])
        ->status(422)
        ->path('message', 'A UID is 9 or 10 digits long.')
        ->path('code', 'invalid')
        ->path('errors.uid', ['A UID is 9 or 10 digits long.']);
    $client
        ->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000009'])
        ->status(422)
        ->path('message', 'No player with this UID exists (check the UID and the game).')
        ->path('code', 'showcase_player_not_found')
        ->path('params', []);

    T::$http->fake(['enka.network/*' => FakeHttpClient::status(503)]);
    $answer = $client->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000008'])->status(422);
    same('The showcase service is having problems right now. Try again later.', $answer->json('message'), 'message');
    same('showcase_down', $answer->json('code'), 'code');
    same('{"message":"The showcase service is having problems right now. Try again later.","code":"showcase_down","params":{}}', $answer->body, 'the whole answer');
});

test('an unknown in-game ID refreshes that catalog list, once per interval', function () use ($owner) {
    // A character newer than the local catalog: its ID is unknown, so the character list (only that one) is
    // downloaded - and not again for the next import within the interval.
    T::$http->fake([
        'gi.yatta.moe/api/v2/en/avatar' => FakeHttpClient::json([
            'data' => [
                'items' => [
                    '10000046' => ['name' => 'Hu Tao', 'element' => 'Fire', 'rank' => 5, 'weaponType' => 'WEAPON_POLE'],
                    '10000999' => ['name' => 'Brand New', 'element' => 'Fire', 'rank' => 5, 'weaponType' => 'WEAPON_POLE'],
                ],
            ],
        ]),
    ]);
    $profile = showcaseProfile();
    $profile['avatarInfoList'][0] = ['avatarId' => 10000999, 'skillDepotId' => 9901, 'equipList' => []] + $profile['avatarInfoList'][0];
    $profile['avatarInfoList'][0]['equipList'] = [];
    $parsed = T::$app->get(EnkaGenshinParser::class)->parse($profile);

    $run = T::$app->get(AccountImporter::class)->import($parsed, $owner());
    same('success', $run->status, 'status');
    same(1, $run->summary['characters'], 'characters');
    same(1, count(T::$http->sent), 'one download');

    // Within the interval another unknown ID is skipped (and said so) without a download.
    $profile['avatarInfoList'][0]['avatarId'] = 10000998;
    $second = T::$app->get(AccountImporter::class)->import(T::$app->get(EnkaGenshinParser::class)->parse($profile), $owner());
    same(1, count(T::$http->sent), 'still one download');
    same('partial', $second->status, 'status');
    check(in_array('Unknown character {"external_id":"10000998"} was skipped.', $second->summary['warnings'], true), 'warning');
    // The sentence quotes the whole reference; the parameter is the one word a page can show.
    same([['code' => 'unknown_character', 'params' => ['ref' => '10000998']], ['code' => 'catalog_behind', 'params' => []]], $second->summary['warning_kinds'], 'the warnings\' kinds');
    same(count($second->summary['warnings']), count($second->summary['warning_kinds']), 'index for index');
});

// ── Kinds: what an import says, for a page to word ───────────────────────────────────────────────

/** The showcase service answering whatever a test says, with none of the Fetcher's waits between requests. */
function fakeShowcaseAnswers(): Fetcher
{
    return new class extends Fetcher {
        public HttpResponse|Throwable|null $answer = null;

        public function __construct() {}

        public function request(string $url, array $options = []): HttpResponse
        {
            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return $this->answer ?? throw new RuntimeException("A test sent a request nobody expected: GET {$url}");
        }
    };
}

test('every import problem is a kind of ImportKinds::ERRORS with exactly its parameters, beside its sentence', function () use ($owner) {
    seedUser(2, 'somebody else');
    $accounts = T::$app->get(Accounts::class);
    $others = $accounts->findOwned(2, seedAccount(2, 'genshin', ['nickname' => 'Not mine']), withCounts: false);
    $genshin = $accounts->findOwned(1, seedAccount(1, 'genshin', ['uid' => '700000001', 'nickname' => 'Main']), withCounts: false);

    $fetcher = fakeShowcaseAnswers();
    T::$app->set(Fetcher::class, $fetcher);
    T::$app->resetScope();

    try {
        $imports = T::$app->get(ImportService::class);
        $importer = T::$app->get(AccountImporter::class);
        $good = fn() => T::$app->get(GoodParser::class)->parse(json_decode(goodFile(), true));
        $uid = 700000100;
        $showcase = function (HttpResponse|Throwable $answer) use ($fetcher, $imports, $owner, &$uid): Closure {
            return function () use ($fetcher, $imports, $owner, $answer, &$uid) {
                $fetcher->answer = $answer;
                $imports->fromEnka(Game::Genshin, (string) $uid++, $owner());
            };
        };

        // kind => [what meets it, its parameters, its sentence - byte for byte what it was before kinds]
        $cases = [
            'account_missing' => [fn() => $importer->import($good(), $owner(), $others), [], 'The selected account does not exist.'],
            'account_uid_mismatch' => [
                fn() => $importer->import(T::$app->get(EnkaGenshinParser::class)->parse(showcaseProfile()), $owner(), $genshin),
                ['data_uid' => '618285856', 'account_uid' => '700000001'],
                'The data belongs to UID 618285856, but the selected account is UID 700000001.',
            ],
            'uid_invalid' => [fn() => $imports->fromEnka(Game::Genshin, '123', $owner()), [], 'A UID is 9 or 10 digits long.'],
            'showcase_unreachable' => [$showcase(new RuntimeException('Could not connect to the service')), [], 'The showcase service cannot be reached right now. Try again in a few minutes.'],
            'showcase_uid_refused' => [$showcase(FakeHttpClient::status(400)), [], 'The showcase service did not accept the UID.'],
            'showcase_player_not_found' => [$showcase(FakeHttpClient::status(404)), [], 'No player with this UID exists (check the UID and the game).'],
            'showcase_maintenance' => [$showcase(FakeHttpClient::status(424)), [], 'The game is under maintenance or just updated, so showcases cannot be read right now. Try again later.'],
            'showcase_busy' => [$showcase(FakeHttpClient::status(429)), [], 'Too many showcase requests right now. Wait a minute and try again.'],
            'showcase_down' => [$showcase(FakeHttpClient::status(500)), [], 'The showcase service is having problems right now. Try again later.'],
            'showcase_http' => [$showcase(FakeHttpClient::status(418)), ['status' => 418], 'The showcase service answered with HTTP 418.'],
            'showcase_bad_answer' => [$showcase(FakeHttpClient::status(200, 'not json')), [], 'The showcase service returned an unexpected answer.'],
            'showcase_details_off' => [
                $showcase(FakeHttpClient::json(['playerInfo' => ['showAvatarInfoList' => [['avatarId' => 10000046]]]])),
                [],
                'The profile shows characters, but "Show Character Details" is turned off. Enable it in-game (Profile > Character Showcase) and try again in a few minutes.',
            ],
            'showcase_empty' => [
                $showcase(FakeHttpClient::json(['playerInfo' => ['nickname' => 'Nobody shown']])),
                [],
                'This profile has no characters in its Character Showcase. Add characters in-game (Profile > Character Showcase), enable "Show Character Details", then try again.',
            ],
            'good_not_good' => [fn() => T::$app->get(GoodParser::class)->parse(['format' => 'something else']), [], 'This is not a GOOD file (missing "format": "GOOD").'],
            'good_empty' => [fn() => $imports->fromFile('{"format":"GOOD","weapons":[]}', $owner()), [], 'The GOOD file contains no characters or artifacts. Check the exporter settings.'],
            'file_not_json' => [fn() => $imports->fromFile('not json', $owner()), [], 'The file is not valid JSON.'],
            'file_unknown_format' => [fn() => $imports->fromFile('{"something":"else"}', $owner()), [], 'Unknown file format. Upload a Genshin Impact inventory export in the GOOD format.'],
        ];

        foreach ($cases as $kind => [$meet, $params, $message]) {
            /** @var ImportException $e */
            $e = throws(ImportException::class, $meet, $kind);
            same($kind, $e->kind, "{$kind}: the kind");
            same($params, $e->params, "{$kind}: the parameters");
            same(ImportKinds::ERRORS[$kind] ?? null, array_keys($e->params), "{$kind}: exactly the parameters ImportKinds lists");
            same($message, $e->getMessage(), "{$kind}: the sentence");
        }

        // "unexpected" is the one kind nothing throws: a failure that is not an import problem is recorded as it.
        same('unexpected', (new ImportException('Anything'))->kind, 'the kind of a problem that names none');
        same(array_keys(ImportKinds::ERRORS), [...array_slice(array_keys($cases), 0, 2), 'unexpected', ...array_slice(array_keys($cases), 2)], 'every kind of the list was met');
    } finally {
        T::$app->forget(Fetcher::class);
        T::$app->resetScope();
    }
});

test('every warning is a kind of ImportKinds::WARNINGS with exactly its parameters, index for index with the sentences', function () use ($owner) {
    $imports = T::$app->get(ImportService::class);
    $seen = [];
    $kinds = function (array $pairs) use (&$seen): void {
        foreach ($pairs as ['code' => $code, 'params' => $params]) {
            same(ImportKinds::WARNINGS[$code] ?? null, array_keys($params), "{$code}: exactly the parameters ImportKinds lists");
            $seen[$code] = true;
        }
    };

    // A GOOD file with everything that can be wrong in one: what the parser says comes first, then what the
    // catalog lacks, then the advice; a sentence said twice (two pieces of one unknown set) is kept once.
    $piece = ['setKey' => 'SomeFutureSet', 'slotKey' => 'goblet', 'level' => 20, 'rarity' => 5, 'mainStatKey' => 'pyro_dmg_', 'location' => '', 'substats' => []];
    $run = $imports->fromFile(
        (string) json_encode([
            'format' => 'GOOD',
            'characters' => [['key' => 'Traveler', 'level' => 90], ['key' => 'HuTao', 'level' => 90], ['key' => 'SomeoneNew', 'level' => 1]],
            'weapons' => [['key' => 'FutureBlade', 'level' => 90]],
            'artifacts' => [$piece, ['slotKey' => 'flower', 'mainStatKey' => 'hp'], ['setKey' => 'CrimsonWitchOfFlames', 'slotKey' => 'hat', 'mainStatKey' => 'hp'], ['slotKey' => 'circlet'] + $piece],
        ]),
        $owner(),
    );

    same('partial', $run->status, 'status');
    same(
        [
            'The file has a Traveler without an element (old GOOD version); it was skipped.',
            '2 artifact(s) with missing set, slot or main stat were skipped.',
            'Unknown character {"name":"SomeoneNew"} was skipped.',
            'Unknown weapon {"name":"FutureBlade"} was skipped.',
            'Unknown set {"name":"SomeFutureSet"} was skipped.',
            'What the game catalog does not know yet was skipped. After an admin refreshes the catalog (Sources page), import again.',
        ],
        $run->summary['warnings'],
        'the sentences, in the order they always had',
    );
    same(
        [
            ['code' => 'good_traveler_no_element', 'params' => []],
            ['code' => 'good_artifacts_skipped', 'params' => ['count' => 2]],
            ['code' => 'unknown_character', 'params' => ['ref' => 'SomeoneNew']],
            ['code' => 'unknown_weapon', 'params' => ['weapon_label' => 'Weapon', 'ref' => 'FutureBlade']],
            ['code' => 'unknown_set', 'params' => ['ref' => 'SomeFutureSet']],
            ['code' => 'catalog_behind', 'params' => []],
        ],
        $run->summary['warning_kinds'],
        'their kinds, index for index',
    );
    $kinds($run->summary['warning_kinds']);

    // A showcase: a piece whose data is not understood.
    $profile = showcaseProfile();
    $profile['avatarInfoList'][0]['equipList'][] = ['itemId' => 1, 'flat' => ['itemType' => 'ITEM_RELIQUARY', 'equipType' => 'EQUIP_ELBOW']];
    $parsed = T::$app->get(EnkaGenshinParser::class)->parse($profile);
    same([['message' => 'Skipped an artifact with unknown data.', 'kind' => 'showcase_artifact_skipped', 'params' => []]], $parsed->warnings, 'a Genshin showcase');
    $run = T::$app->get(AccountImporter::class)->import($parsed, $owner());
    same(['Skipped an artifact with unknown data.'], $run->summary['warnings'], 'the sentence of a showcase');
    $kinds($run->summary['warning_kinds']);

    same(array_keys(ImportKinds::WARNINGS), array_values(array_filter(array_keys(ImportKinds::WARNINGS), fn(string $kind) => isset($seen[$kind]))), 'every kind of the list was met');

    // ref: the first of a reference's name, key and in-game ID that is there, as a string.
    same('Traveler (Anemo)', ImportKinds::ref(['key' => 'traveleranemo', 'name' => 'Traveler (Anemo)']), 'the name first');
    same('traveleranemo', ImportKinds::ref(['external_id' => '10000005', 'key' => 'traveleranemo']), 'then the key');
    same('1005', ImportKinds::ref(['external_id' => 1005]), 'then the in-game ID, as a string');
    same('', ImportKinds::ref(['name' => '']), 'nothing there');
});

/** The bracket a token opens or closes ("" for any other token); "{$" inside a string opens one too. */
function bracketOf(mixed $token): string
{
    if (is_array($token)) {
        return match ($token[0]) {
            T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES => '{',
            T_ATTRIBUTE => '[',
            default => '',
        };
    }

    return in_array($token, ['(', ')', '[', ']', '{', '}'], true) ? $token : '';
}

/**
 * The tokens between the bracket at $open and its partner.
 *
 * @param  list<mixed>  $tokens
 * @return list<mixed>
 */
function tokensInside(array $tokens, int $open): array
{
    $inside = [];
    $depth = 0;
    for ($i = $open + 1; isset($tokens[$i]); $i++) {
        $bracket = bracketOf($tokens[$i]);
        if (in_array($bracket, [')', ']', '}'], true)) {
            if ($depth === 0) {
                break;
            }
            $depth--;
        } elseif ($bracket !== '') {
            $depth++;
        }
        $inside[] = $tokens[$i];
    }

    return $inside;
}

/**
 * Tokens cut at their top-level commas: the arguments of a call, the elements of an array.
 *
 * @param  list<mixed>  $tokens
 * @return list<list<mixed>>
 */
function tokensByComma(array $tokens): array
{
    $parts = [[]];
    $depth = 0;
    foreach ($tokens as $token) {
        $bracket = bracketOf($token);
        $depth += in_array($bracket, ['(', '[', '{'], true) ? 1 : ($bracket !== '' ? -1 : 0);
        if ($token === ',' && $depth === 0) {
            $parts[] = [];
        } else {
            $parts[count($parts) - 1][] = $token;
        }
    }

    return array_values(array_filter($parts, fn(array $part) => $part !== []));
}

/**
 * Every call in $files that $opens recognises (it answers where the call's "(" is, or null), as
 * [where, the kind it names, the names of its parameters]: the kind null when that argument is not one plain
 * string, the names null when the parameters are not a plain array with string keys, [] when there are none.
 *
 * @param  list<string>  $files
 * @param  callable(list<mixed>, int): ?int  $opens
 * @return list<array{0: string, 1: string|null, 2: list<string>|null}>
 */
function kindSites(array $files, callable $opens, int $kindAt, int $paramsAt): array
{
    $plain = fn(mixed $token): ?string => is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : null;

    $sites = [];
    foreach ($files as $file) {
        $line = 0;
        $lines = [];
        $tokens = [];
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            $line = is_array($token) ? $token[2] : $line;
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = $token;
                $lines[] = $line;
            }
        }

        foreach (array_keys($tokens) as $i) {
            $open = $opens($tokens, $i);
            if ($open === null) {
                continue;
            }
            $arguments = tokensByComma(tokensInside($tokens, $open));

            $kind = count($arguments[$kindAt] ?? []) === 1 ? $plain($arguments[$kindAt][0]) : null;

            $names = [];
            $params = $arguments[$paramsAt] ?? null;
            if ($params !== null) {
                $elements = $params[0] === '[' && end($params) === ']' ? tokensByComma(array_slice($params, 1, -1)) : null;
                foreach ($elements ?? [] as $element) {
                    $names[] = is_array($element[1] ?? null) && $element[1][0] === T_DOUBLE_ARROW ? $plain($element[0]) : null;
                }
                $names = $elements === null || in_array(null, $names, true) ? null : $names;
            }

            $sites[] = [basename($file) . ':' . $lines[$i], $kind, $names];
        }
    }

    return $sites;
}

test('every import problem and warning written in the sources names a kind of ImportKinds, with exactly its parameters', function () {
    // The sources: everything the server runs, but for the three files that are the mechanism itself
    // (ParsedAccount::warn() hands its arguments on as they came).
    $backend = dirname(__DIR__);
    $files = glob($backend . '/*.php') ?: [];
    foreach (['src', 'api'] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend . DIRECTORY_SEPARATOR . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    $files = array_values(array_filter($files, fn(string $file) => !in_array(basename($file), ['ImportException.php', 'ImportKinds.php', 'ParsedAccount.php'], true)));
    sort($files);

    $text = fn(array $tokens, int $i): string => is_array($tokens[$i] ?? '') ? $tokens[$i][1] : (string) ($tokens[$i] ?? '');
    $named = fn(array $tokens, int $i, string $class): bool => is_array($tokens[$i] ?? '') &&
        in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) &&
        (bool) preg_match('/(^|\\\\)' . $class . '$/', $tokens[$i][1]);

    // new ImportException(message, kind, params)
    $problems = kindSites(
        $files,
        fn(array $tokens, int $i): ?int => is_array($tokens[$i]) && $tokens[$i][0] === T_NEW && $named($tokens, $i + 1, 'ImportException') && $text($tokens, $i + 2) === '(' ? $i + 2 : null,
        1,
        2,
    );
    // ->warn(kind, params, message) and ImportKinds::warning(kind, params, message)
    $warnings = kindSites(
        $files,
        function (array $tokens, int $i) use ($text, $named): ?int {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && $text($tokens, $i + 1) === 'warn' && $text($tokens, $i + 2) === '(') {
                return $i + 2;
            }

            return $named($tokens, $i, 'ImportKinds') && $text($tokens, $i + 1) === '::' && $text($tokens, $i + 2) === 'warning' && $text($tokens, $i + 3) === '(' ? $i + 3 : null;
        },
        0,
        1,
    );

    foreach ([['problem', $problems, ImportKinds::ERRORS], ['warning', $warnings, ImportKinds::WARNINGS]] as [$what, $sites, $list]) {
        check(count($sites) >= count($list) - 1, "the {$what}s of the sources were found: " . json_encode($sites));
        foreach ($sites as [$where, $kind, $names]) {
            check($kind !== null, "{$where}: the {$what} names its kind as a plain string");
            check(isset($list[$kind]), "{$where}: \"{$kind}\" is not a {$what} kind of ImportKinds");
            same($list[$kind], $names, "{$where}: the parameters of \"{$kind}\"");
        }
    }

    // And the other way round: no kind of the lists is one nothing says ("unexpected" is what recordFailure()
    // writes for a failure that is not an import problem).
    $said = fn(array $sites): array => array_values(array_unique(array_column($sites, 1)));
    same([], array_values(array_diff(array_keys(ImportKinds::ERRORS), $said($problems), ['unexpected'])), 'problem kinds nothing throws');
    same([], array_values(array_diff(array_keys(ImportKinds::WARNINGS), $said($warnings))), 'warning kinds nothing adds');

    // No name is in both lists, and every name and parameter is what a translation key and a placeholder take.
    same([], array_values(array_intersect(array_keys(ImportKinds::ERRORS), array_keys(ImportKinds::WARNINGS))), 'a kind is a problem or a warning, not both');
    foreach (ImportKinds::ERRORS + ImportKinds::WARNINGS as $kind => $params) {
        check((bool) preg_match('/^[a-z][a-z0-9_]*$/', $kind), "{$kind}: a kind is lower case with underscores");
        foreach ($params as $param) {
            check((bool) preg_match('/^[a-z][a-z0-9_]*$/', $param), "{$kind}: {$param} is lower case with underscores");
        }
    }
});

test('a failed run keeps what happened as a kind, and is sent without a summary as before', function () use ($owner) {
    $client = (new Client())->asIam(1);
    $account = (int) $client
        ->post('/api/imports/file', ['content' => goodFile()])
        ->status(201)
        ->json('account_id');

    // Through the API: a GOOD file with nothing in it.
    $client
        ->post('/api/imports/file', ['content' => '{"format":"GOOD","weapons":[]}', 'account_id' => $account, 'file_name' => 'empty.json'])
        ->status(422)
        ->path('code', 'good_empty');

    $stored = db()->one("SELECT * FROM import_runs WHERE status = 'failed'");
    same('The GOOD file contains no characters or artifacts. Check the exporter settings.', $stored['error'], 'the error column is the sentence, as it always was');
    same(['error' => ['code' => 'good_empty', 'params' => []]], json_decode((string) $stored['summary'], true), 'the kind is kept in the summary column');

    // A failure that is not an import problem: "unexpected", with the sentence the history always showed.
    T::$app
        ->get(AccountImporter::class)
        ->recordFailure(Game::Genshin, 'good', new RuntimeException('boom'), $owner(), T::$app->get(Accounts::class)->findOwned(1, $account, withCounts: false), 'broken.json');
    // A run stored before kinds were kept: no summary at all.
    $now = Clock::now();
    db()->insert('import_runs', [
        'user_id' => 1,
        'account_id' => $account,
        'game' => 'genshin',
        'source' => 'enka',
        'status' => 'failed',
        'error' => 'No player with this UID exists (check the UID and the game).',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $history = $client->get("/api/accounts/{$account}/imports")->status(200);
    same(4, count($history->json()), 'the four runs: ' . $history->body);
    $one = fn(callable $is): array => array_values(array_filter($history->json(), $is))[0] ?? throw new AssertionFailed('no such run: ' . $history->body);

    $failed = $one(fn(array $run) => $run['file_name'] === 'empty.json');
    same('failed', $failed['status'], 'status');
    same(null, $failed['summary'], 'a failed run has no summary');
    same('The GOOD file contains no characters or artifacts. Check the exporter settings.', $failed['error'], 'the unchanged error');
    same('good_empty', $failed['error_code'], 'error_code');
    same([], $failed['error_params'], 'error_params');

    $unexpected = $one(fn(array $run) => $run['file_name'] === 'broken.json');
    same(null, $unexpected['summary'], 'no summary');
    same('The import stopped with an unexpected error: boom', $unexpected['error'], 'the error of an unexpected failure');
    same('unexpected', $unexpected['error_code'], 'its code');
    same([], $unexpected['error_params'], 'no parameters');
    check(str_contains($history->body, '"error_code":"unexpected","error_params":{}'), 'no parameters are sent as an object: ' . $history->body);

    $older = $one(fn(array $run) => $run['status'] === 'failed' && $run['file_name'] === null);
    same(null, $older['summary'], 'no summary');
    same('No player with this UID exists (check the UID and the game).', $older['error'], 'its stored sentence');
    same(null, $older['error_code'], 'no code for an older run');
    same(null, $older['error_params'], 'no parameters for an older run');

    // A run that went through has neither.
    $imported = $one(fn(array $run) => $run['status'] !== 'failed');
    same([null, null, null], [$imported['error'], $imported['error_code'], $imported['error_params']], 'a run that went through');
    check(is_array($imported['summary']) && !array_key_exists('error', $imported['summary']), 'and its summary is a summary');
});

test('warning kinds are sent only when there is one for each warning', function () {
    $client = (new Client())->asIam(1);
    $account = seedAccount(1, 'genshin', ['uid' => '700000001', 'nickname' => 'Main']);
    $now = Clock::now();
    $summary = [
        'characters' => 1,
        'weapons' => 0,
        'items' => 0,
        'full_inventory' => true,
        'tool' => 'Some Exporter',
        'unmatched' => [],
        'warnings' => ['Unknown set {"name":"A"} was skipped.', 'Unknown set {"name":"B"} was skipped.'],
    ];
    $run = fn(string $name, array $more) => db()->insert('import_runs', [
        'user_id' => 1,
        'account_id' => $account,
        'game' => 'genshin',
        'source' => 'good',
        'status' => 'partial',
        'file_name' => $name,
        'summary' => Json::encode($more + $summary),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $kinds = [['code' => 'unknown_set', 'params' => ['ref' => 'A']], ['code' => 'unknown_set', 'params' => ['ref' => 'B']]];
    $run('before-kinds.json', []);
    $run('both.json', ['warning_kinds' => $kinds]);
    $run('one-short.json', ['warning_kinds' => [$kinds[0]]]);
    $run('not-kinds.json', ['warning_kinds' => ['unknown_set', 'unknown_set']]);

    $runs = array_column(
        $client
            ->get("/api/accounts/{$account}/imports")
            ->status(200)
            ->json(),
        null,
        'file_name',
    );

    same($kinds, $runs['both.json']['summary']['warning_kinds'], 'one for each warning: sent');
    same($summary['warnings'], $runs['both.json']['summary']['warnings'], 'beside the sentences');
    foreach (['before-kinds.json' => 'a run stored before kinds were kept', 'one-short.json' => 'fewer kinds than warnings', 'not-kinds.json' => 'something else under that name'] as $name => $case) {
        check(!array_key_exists('warning_kinds', $runs[$name]['summary']), "{$case}: no warning_kinds");
        same($summary['warnings'], $runs[$name]['summary']['warnings'], "{$case}: the sentences are sent all the same");
    }
});

test('the parameters a file fills in never name another site, whatever the file says', function () {
    $client = (new Client())->asIam(1);

    // An export that gives a site's name where a character's key belongs.
    $file = (string) json_encode([
        'format' => 'GOOD',
        'version' => 3,
        'characters' => [['key' => 'enka.network', 'level' => 80, 'constellation' => 0, 'ascension' => 6, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]]],
    ]);
    $run = $client->post('/api/imports/file', ['content' => $file])->status(201);
    same(['code' => 'unknown_character', 'params' => ['ref' => 'The source']], $run->json('summary.warning_kinds.0'), 'the parameter, in neutral words');
    check(!str_contains(strtolower($run->body), 'enka'), 'the site is named nowhere: ' . $run->body);
});

finishTests();
