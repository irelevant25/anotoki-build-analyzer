<?php

/**
 * Imports: inventory files (GOOD, the Star Rail scanner format), showcases by UID, how they land in an
 * account, and how an unknown in-game ID refreshes the catalog. The showcase service and the game data
 * provider are faked.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Enums\Game;
use App\Models\User;
use App\Repositories\Accounts;
use App\Services\Import\AccountImporter;
use App\Services\Import\Enka\EnkaGenshinParser;
use App\Services\Import\Enka\EnkaHsrParser;
use App\Services\Import\Enka\EnkaStore;
use App\Services\Import\ImportService;
use Tests\Support\Client;
use Tests\Support\FakeHttpClient;

bootTests();

// The Genshin character data file, never downloaded in the tests.
$store = new class extends EnkaStore
{
    public function __construct() {}

    public function genshinAvatar(string $key): ?array
    {
        return ['SkillOrder' => [10051, 10052, 10053], 'ProudMap' => ['10052' => 532, '10053' => 539]];
    }
};

beforeEach(function () use ($store) {
    T::$app->set(EnkaStore::class, $store);
    seedGenshinCatalog();
    seedHsrCatalog();
    seedUser(1, 'owner');
});

$owner = fn () => new User(1, 'owner');

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
            ['setKey' => 'CrimsonWitchOfFlames', 'slotKey' => 'flower', 'level' => 20, 'rarity' => 5, 'mainStatKey' => 'hp', 'location' => 'HuTao', 'lock' => true,
                'substats' => [['key' => 'critRate_', 'value' => 10.5], ['key' => 'critDMG_', 'value' => 14.0], ['key' => 'atk', 'value' => 16], ['key' => '', 'value' => 0]]],
            ['setKey' => 'CrimsonWitchOfFlames', 'slotKey' => 'sands', 'level' => 16, 'rarity' => 5, 'mainStatKey' => 'hp_', 'location' => 'Traveler', 'lock' => false,
                'substats' => [['key' => 'critDMG_', 'value' => 7.8]]],
            ['setKey' => 'SomeFutureSet', 'slotKey' => 'goblet', 'level' => 20, 'rarity' => 5, 'mainStatKey' => 'pyro_dmg_', 'location' => '', 'lock' => false, 'substats' => []],
        ],
    ]);
}

function showcaseProfile(): array
{
    return [
        'uid' => '618285856',
        'playerInfo' => ['nickname' => 'Tester', 'level' => 57, 'worldLevel' => 8],
        'avatarInfoList' => [[
            'avatarId' => 10000005,
            'skillDepotId' => 504,
            'propMap' => ['4001' => ['val' => '90'], '1002' => ['val' => '6']],
            'talentIdList' => [1, 2, 3],
            'skillLevelMap' => ['10051' => 9, '10052' => 8, '10053' => 10],
            'proudSkillExtraLevelMap' => ['532' => 3],
            'fightPropMap' => ['2000' => 15000.4, '2001' => 2000.2, '20' => 0.652, '22' => 1.5, '23' => 1.805, '28' => 100],
            'equipList' => [
                ['itemId' => 13501, 'weapon' => ['level' => 90, 'promoteLevel' => 6, 'affixMap' => ['113501' => 0]], 'flat' => ['itemType' => 'ITEM_WEAPON']],
                ['itemId' => 81543, 'reliquary' => ['level' => 21, 'appendPropIdList' => [501204, 501224, 501223, 501201]],
                    'flat' => ['itemType' => 'ITEM_RELIQUARY', 'equipType' => 'EQUIP_BRACER', 'setId' => 15006, 'rankLevel' => 5,
                        'reliquaryMainstat' => ['mainPropId' => 'FIGHT_PROP_HP', 'statValue' => 4780],
                        'reliquarySubstats' => [['appendPropId' => 'FIGHT_PROP_CRITICAL', 'statValue' => 7.4], ['appendPropId' => 'FIGHT_PROP_CRITICAL_HURT', 'statValue' => 14.8]]]],
            ],
        ]],
    ];
}

$item = fn (string $where, array $params = []) => db()->one("SELECT i.*, c.key AS equipped_key FROM account_items i LEFT JOIN game_characters c ON c.id = i.equipped_character_id WHERE {$where}", $params);

test('imports a GOOD file', function () use ($owner, $item) {
    $run = T::$app->get(ImportService::class)->fromFile(goodFile(), $owner(), Game::Genshin, null, 'export.json');

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
    $first = $service->fromFile(goodFile(), $owner(), Game::Genshin);
    $account = T::$app->get(Accounts::class)->findOwned(1, (int) $first->account_id);

    $service->fromFile(goodFile(), $owner(), Game::Genshin, $account);

    same(2, (int) db()->value('SELECT count(*) FROM account_items WHERE account_id = ?', [$account->id]), 'items');
    same(1, (int) db()->value('SELECT count(*) FROM accounts'), 'accounts');
});

test('imports a Star Rail scanner file', function () use ($owner, $item) {
    $file = json_encode([
        'source' => 'reliquary_archiver',
        'build' => '0.18.0',
        'version' => 4,
        'metadata' => ['uid' => 700000001, 'trailblazer' => 'Stelle'],
        'light_cones' => [
            ['id' => '23006', 'name' => 'Patience Is All You Need', 'level' => 80, 'ascension' => 6, 'superimposition' => 1, 'location' => '1005', 'lock' => true, '_uid' => 'lc1'],
        ],
        'relics' => [
            ['set_id' => '117', 'name' => 'Prisoner in Deep Confinement', 'slot' => 'Feet', 'rarity' => 5, 'level' => 15, 'mainstat' => 'SPD',
                'substats' => [['key' => 'CRIT Rate_', 'value' => 3.24, 'count' => 1, 'step' => 2], ['key' => 'ATK', 'value' => 42.34, 'count' => 2, 'step' => 2]],
                'location' => '1005', 'lock' => true, 'discard' => false, '_uid' => 'r1'],
            ['set_id' => '310', 'name' => 'Firmament Frontline: Glamoth', 'slot' => 'Link Rope', 'rarity' => 5, 'level' => 15, 'mainstat' => 'ATK',
                'substats' => [], 'location' => '8006', 'lock' => false, 'discard' => false, '_uid' => 'r2'],
        ],
        'characters' => [
            ['id' => '1005', 'name' => 'Kafka', 'path' => 'Nihility', 'level' => 80, 'ascension' => 6, 'eidolon' => 1, 'skills' => ['basic' => 6, 'skill' => 10, 'ult' => 10, 'talent' => 10], 'traces' => []],
            ['id' => '8006', 'name' => 'Trailblazer', 'path' => 'Harmony', 'level' => 80, 'ascension' => 6, 'eidolon' => 6, 'skills' => ['basic' => 6, 'skill' => 10, 'ult' => 10, 'talent' => 10], 'traces' => []],
        ],
    ]);

    $run = T::$app->get(ImportService::class)->fromFile($file, $owner());

    same('success', $run->status, 'status');
    $account = T::$app->get(Accounts::class)->findOwned(1, (int) $run->account_id);
    same(Game::Hsr, $account->game, 'game');
    same('700000001', $account->uid, 'uid');

    $feet = $item("i.slot = 'feet'");
    same('spd', $feet['main_stat'], 'main stat');
    near(25.032, (float) $feet['main_value'], 0.001, 'main value');
    same([
        ['key' => 'critRate_', 'value' => 3.24, 'rolls' => 1],
        ['key' => 'atk', 'value' => 42.34, 'rolls' => 2],
    ], json_decode($feet['substats'], true), 'substats');

    // "ATK" on a rope is ATK%; location 8006 (Stelle, Harmony) maps to the Harmony Trailblazer.
    $rope = $item("i.slot = 'rope'");
    same('atk_', $rope['main_stat'], 'rope main stat');
    same('trailblazerharmony', $rope['equipped_key'], 'on the Trailblazer');

    $kafka = db()->value("SELECT ac.talents FROM account_characters ac JOIN game_characters c ON c.id = ac.game_character_id WHERE c.key = 'kafka'");
    same(['basic' => 6, 'skill' => 10, 'ultimate' => 10, 'talent' => 10], json_decode($kafka, true), 'traces');
});

test('a showcase import can complete an account made from a file', function () use ($owner) {
    $fromFile = T::$app->get(ImportService::class)->fromFile(goodFile(), $owner(), Game::Genshin);
    $accountId = (int) $fromFile->account_id;
    same(null, db()->value('SELECT uid FROM accounts WHERE id = ?', [$accountId]), 'no UID yet');

    T::$http->fake(['enka.network/*' => FakeHttpClient::json(showcaseProfile())]);
    (new Client)->asIam(1)->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '618285856', 'account_id' => $accountId])
        ->status(201)->path('account_id', $accountId)->path('source', 'showcase');

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

test('parses a Star Rail showcase, support characters only once', function () {
    $kafka = [
        'avatarId' => 1005, 'level' => 80, 'promotion' => 6, 'rank' => 1,
        'skillTreeList' => [['pointId' => 1005001, 'level' => 6], ['pointId' => 1005002, 'level' => 10], ['pointId' => 1005003, 'level' => 10], ['pointId' => 1005004, 'level' => 10], ['pointId' => 1005201, 'level' => 1]],
        'equipment' => ['tid' => 23006, 'level' => 80, 'promotion' => 6, 'rank' => 1],
        'relicList' => [[
            'tid' => 61174, 'type' => 4, 'level' => 15, 'mainAffixId' => 4,
            'subAffixList' => [['affixId' => 8, 'cnt' => 3, 'step' => 2], ['affixId' => 7, 'cnt' => 2]],
            '_flat' => ['setID' => 117, 'props' => [['type' => 'SpeedDelta', 'value' => 25.032], ['type' => 'CriticalChance', 'value' => 0.0972], ['type' => 'SpeedDelta', 'value' => 4.6]]],
        ]],
    ];
    $json = ['uid' => '700000002', 'detailInfo' => [
        'uid' => 700000002, 'nickname' => 'Pom', 'level' => 70, 'worldLevel' => 6,
        'avatarDetailList' => [$kafka + ['_assist' => true], $kafka],
    ]];

    $parsed = T::$app->get(EnkaHsrParser::class)->parse($json);

    same(1, count($parsed->characters), 'one Kafka');
    same(['basic' => 6, 'skill' => 10, 'ultimate' => 10, 'talent' => 10], $parsed->characters[0]['talents'], 'traces');
    $relic = $parsed->items[0];
    same(5, $relic['rarity'], 'rarity');
    same('spd', $relic['main_stat'], 'main stat');
    same(['key' => 'critRate_', 'value' => 9.72, 'rolls' => 3], $relic['substats'][0], 'crit');
    same(['key' => 'spd', 'value' => 4.6, 'rolls' => 2], $relic['substats'][1], 'speed');
});

test('file imports through the API, and their errors', function () {
    $client = (new Client)->asIam(1);

    $client->post('/api/imports/file', ['content' => goodFile(), 'file_name' => 'x.json'])
        ->status(201)->path('summary.characters', 2)->path('account.game', 'genshin')->path('source', 'file');

    $client->post('/api/imports/file', ['content' => 'not json'])->status(422)->path('message', 'The file is not valid JSON.');

    $client->post('/api/imports/file', ['content' => goodFile(), 'game' => 'hsr'])
        ->status(422)->path('message', 'This file contains Genshin Impact data, but you chose Honkai: Star Rail.');

    // An upload as a file, as a script would send it.
    $path = T::$storage.DIRECTORY_SEPARATOR.'upload.json';
    file_put_contents($path, goodFile());
    $client->request('POST', '/api/imports/file', null, ['files' => ['file' => [$path, 'export.json']], 'form' => []])
        ->status(201)->path('file_name', 'export.json');

    // Nothing in what the API sends names the exporter the file came from.
    $answer = $client->get('/api/accounts');
    check(! str_contains($answer->body, 'Some Exporter'), 'the tool is not sent: '.$answer->body);
});

test('showcase import errors through the API', function () {
    T::$http->fake(['enka.network/*' => FakeHttpClient::status(404)]);
    $client = (new Client)->asIam(1);

    $client->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '123'])->status(422)->path('message', 'A UID is 9 or 10 digits long.');
    $client->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000009'])
        ->status(422)->path('message', 'No player with this UID exists (check the UID and the game).');

    T::$http->fake(['enka.network/*' => FakeHttpClient::status(503)]);
    $answer = $client->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000008'])->status(422);
    same('The showcase service is having problems right now. Try again later.', $answer->json('message'), 'message');
});

test('an unknown in-game ID refreshes that catalog list, once per interval', function () use ($owner) {
    // A character newer than the local catalog: its ID is unknown, so the character list (only that one) is
    // downloaded - and not again for the next import within the interval.
    T::$http->fake([
        'gi.yatta.moe/api/v2/en/avatar' => FakeHttpClient::json(['data' => ['items' => [
            '10000046' => ['name' => 'Hu Tao', 'element' => 'Fire', 'rank' => 5, 'weaponType' => 'WEAPON_POLE'],
            '10000999' => ['name' => 'Brand New', 'element' => 'Fire', 'rank' => 5, 'weaponType' => 'WEAPON_POLE'],
        ]]]),
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
});

finishTests();
