<?php

/**
 * The game catalog from its provider (App\Services\Catalog\CatalogSync, CatalogUpkeep, Providers\GenshinCatalog; the
 * Builds page's refresh in api/routes/admin_builds.php; sync-catalog.php): genshin.anotoki.eu's three lists mapped
 * into the catalog - the Travelers one entry per element, a weapon listed twice kept once -, a renamed entry found
 * again by its in-game ID, a conditional request answered "unchanged", a provider closed, without the list, failing
 * or answering what cannot be read (the catalog kept as it was, the reason kept), icons from the provider's own host
 * alone (an AVIF one an image), stale lists, the upkeep refreshing one after an answer, the Builds page's route and
 * the command line.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Auth\Role;
use App\Cache\Cache;
use App\Cli;
use App\Commands\SyncCatalog;
use App\Enums\Game;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Presenters\Icons;
use App\Repositories\CatalogRows;
use App\Services\Catalog\CatalogSync;
use App\Services\Catalog\Providers\GenshinCatalog;
use App\Support\Clock;
use Tests\Support\Client;
use Tests\Support\FakeHttpClient;

bootTests();

const API = 'https://genshin.anotoki.eu/api/catalog';
const EDITOR = 4;

beforeEach(function () {
    seedGenshinCatalog();
});

function catalogSync(): CatalogSync
{
    return T::$app->get(CatalogSync::class);
}

function editor(): Client
{
    return (new Client())->asIam(EDITOR, [Role::EDITOR], ['preferred_username' => 'eddie']);
}

/**
 * A character as genshin.anotoki.eu lists it: Hu Tao, unless $fields says otherwise.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function apiCharacter(array $fields = []): array
{
    return $fields + [
        'game_id' => 10000046,
        'name' => 'Hu Tao',
        'element' => 'Pyro',
        'weapon_type' => 'Polearm',
        'rarity' => 5,
        'region' => 'Liyue',
        'version' => '1.3',
        'version_name' => null,
        'release_date' => '2021-03-02',
        'is_traveler' => false,
        'icon' => 'https://genshin.anotoki.eu/media/characters/hu-tao.avif',
    ];
}

/**
 * An entry's fields as a refresh writes them; null when the catalog has no such key.
 *
 * @return array<string, mixed>|null
 */
function entry(string $list, string $key): ?array
{
    $entry = T::$app->get(CatalogRows::class)->findByKey(Game::Genshin, $list, $key);

    return match (true) {
        $entry instanceof GameCharacter => [
            'id' => $entry->id,
            'name' => $entry->name,
            'element' => $entry->element,
            'rarity' => $entry->rarity,
            'weapon_type' => $entry->weapon_type,
            'region' => $entry->region,
            'version' => $entry->version,
            'version_name' => $entry->version_name,
            'released_at' => Clock::iso($entry->released_at),
            'external_id' => $entry->external_id,
            'icon_url' => $entry->icon_url,
            'meta' => $entry->meta,
        ],
        $entry instanceof GameWeapon => [
            'id' => $entry->id,
            'name' => $entry->name,
            'rarity' => $entry->rarity,
            'weapon_type' => $entry->weapon_type,
            'external_id' => $entry->external_id,
            'icon_url' => $entry->icon_url,
        ],
        $entry instanceof GameSet => ['id' => $entry->id, 'name' => $entry->name, 'kind' => $entry->kind, 'external_id' => $entry->external_id, 'icon_url' => $entry->icon_url, 'meta' => $entry->meta],
        default => null,
    };
}

/** The characters list's line on the Builds page (CatalogSync::lists()). */
function charactersLine(): array
{
    return catalogSync()->lists(Game::Genshin)[0];
}

// ── What the lists become ────────────────────────────────────────────────────────────────────────

test('the characters: element, weapon type, rarity, region, version, release and icon as the catalog keeps them; the Travelers one entry per element; what cannot be read left out', function () {
    $huTao = idOf('game_characters', 'hutao');
    $anemo = idOf('game_characters', 'traveleranemo');
    $traveler = fn(int $id, string $twin, string $element) => apiCharacter([
        'game_id' => $id,
        'name' => "{$twin} ({$element})",
        'element' => $element,
        'weapon_type' => 'Sword',
        'region' => null,
        'version' => '1.0',
        'release_date' => '2020-09-28',
        'is_traveler' => true,
        'icon' => 'https://genshin.anotoki.eu/media/characters/' . strtolower("{$twin}-{$element}") . '.avif',
    ]);
    T::$http->fake([
        API . '/characters' => FakeHttpClient::json(
            [
                apiCharacter(),
                apiCharacter([
                    'game_id' => 10000125,
                    'name' => 'Ineffa',
                    'element' => 'Electro',
                    'region' => 'Nod-Krai',
                    'version' => '6.0',
                    'version_name' => 'Luna I',
                    'release_date' => '2025-09-10',
                    'icon' => 'https://genshin.anotoki.eu/media/characters/ineffa.avif',
                ]),
                $traveler(10000007, 'Lumine', 'Anemo'),
                $traveler(10000005, 'Aether', 'Anemo'),
                $traveler(10000007, 'Lumine', 'Geo'),
                $traveler(10000005, 'Aether', 'Geo'),
                // What the catalog has no place for is none: an element and a weapon type the game does not have, a
                // rarity beyond five, a region too long, a version that is a name, a day that never was, an icon elsewhere.
                apiCharacter([
                    'game_id' => 10000990,
                    'name' => 'Someone Odd',
                    'element' => 'Adaptive',
                    'weapon_type' => 'Glaive',
                    'rarity' => 9,
                    'region' => str_repeat('x', 51),
                    'version' => 'Luna II',
                    'version_name' => 'Luna II',
                    'release_date' => '2025-02-30',
                    'icon' => 'https://elsewhere.example/odd.avif',
                ]),
                apiCharacter(['game_id' => null, 'name' => 'Not Numbered Yet', 'element' => 'hydro']),
                apiCharacter(['game_id' => 10000991, 'name' => '  ']),
                apiCharacter(['game_id' => 10000992, 'name' => '<b></b>']),
                'not an entry',
            ],
            200,
            ['ETag' => '"c1"'],
        ),
    ]);

    same(['outcome' => 'changed', 'written' => 6, 'problem' => null], catalogSync()->refresh(Game::Genshin, 'characters'), 'six written');
    same(
        [
            'id' => $huTao,
            'name' => 'Hu Tao',
            'element' => 'pyro',
            'rarity' => 5,
            'weapon_type' => 'polearm',
            'region' => 'Liyue',
            'version' => '1.3',
            'version_name' => null,
            'released_at' => '2021-03-02T00:00:00+00:00',
            'external_id' => '10000046',
            'icon_url' => 'https://genshin.anotoki.eu/media/characters/hu-tao.avif',
            'meta' => [],
        ],
        entry('characters', 'hutao'),
        'Hu Tao\'s row, found by its in-game ID',
    );
    same(
        [
            'name' => 'Ineffa',
            'element' => 'electro',
            'rarity' => 5,
            'weapon_type' => 'polearm',
            'region' => 'Nod-Krai',
            'version' => '6.0',
            'version_name' => 'Luna I',
            'released_at' => '2025-09-10T00:00:00+00:00',
            'external_id' => '10000125',
            'icon_url' => 'https://genshin.anotoki.eu/media/characters/ineffa.avif',
            'meta' => [],
        ],
        array_diff_key((array) entry('characters', 'ineffa'), ['id' => true]),
        'a character the catalog did not have',
    );
    same(
        [
            'id' => $anemo,
            'name' => 'Traveler (Anemo)',
            'element' => 'anemo',
            'rarity' => 5,
            'weapon_type' => 'sword',
            'region' => null,
            'version' => '1.0',
            'version_name' => null,
            'released_at' => '2020-09-28T00:00:00+00:00',
            'external_id' => '10000005-anemo',
            'icon_url' => 'https://genshin.anotoki.eu/media/characters/aether-anemo.avif',
            'meta' => ['ids' => ['10000005-anemo', '10000007-anemo']],
        ],
        entry('characters', 'traveleranemo'),
        'the twins one entry per element, by the lower ID - as imports name them',
    );
    $geo = (array) entry('characters', 'travelergeo');
    same(['Traveler (Geo)', '10000005-geo', ['ids' => ['10000005-geo', '10000007-geo']]], [$geo['name'], $geo['external_id'], $geo['meta']], 'another element, another entry');
    same(
        ['element' => null, 'rarity' => null, 'weapon_type' => null, 'region' => null, 'version' => null, 'version_name' => 'Luna II', 'released_at' => null, 'icon_url' => null],
        array_intersect_key((array) entry('characters', 'someoneodd'), array_flip(['element', 'rarity', 'weapon_type', 'region', 'version', 'version_name', 'released_at', 'icon_url'])),
        'what the catalog has no place for is none',
    );
    same([null, 'hydro'], [entry('characters', 'notnumberedyet')['external_id'], entry('characters', 'notnumberedyet')['element']], 'one without an in-game ID yet');
    same(
        ['hutao', 'ineffa', 'kaedeharakazuha', 'notnumberedyet', 'someoneodd', 'traveleranemo', 'travelergeo'],
        db()->column('SELECT key FROM game_characters ORDER BY key COLLATE "C"'),
        'none without a name; Kazuha, not in the list, kept',
    );
});

test('weapons and artifact sets: of a weapon listed twice the lower in-game ID; a set\'s rarities and bonuses, without markup', function () {
    $homa = idOf('game_weapons', 'staffofhoma');
    $crimson = idOf('game_sets', 'crimsonwitchofflames');
    T::$http->fake([
        API . '/weapons' => FakeHttpClient::json([
            [
                'game_id' => 13501,
                'name' => 'Staff of Homa',
                'type' => 'Polearm',
                'rarity' => 5,
                'version' => '1.3',
                'version_name' => null,
                'release_date' => '2021-03-02',
                'icon' => 'https://genshin.anotoki.eu/media/weapons/staff-of-homa.avif',
            ],
            [
                'game_id' => 11511,
                'name' => 'Prized Isshin Blade',
                'type' => 'Sword',
                'rarity' => 4,
                'version' => '2.1',
                'version_name' => null,
                'release_date' => '2021-09-01',
                'icon' => 'https://genshin.anotoki.eu/media/weapons/prized-isshin-blade-2.avif',
            ],
            [
                'game_id' => 11510,
                'name' => 'Prized Isshin Blade',
                'type' => 'Sword',
                'rarity' => 4,
                'version' => '2.1',
                'version_name' => null,
                'release_date' => '2021-09-01',
                'icon' => 'https://genshin.anotoki.eu/media/weapons/prized-isshin-blade.avif',
            ],
            ['game_id' => 15599, 'name' => 'Not A Weapon Type', 'type' => 'Glaive', 'rarity' => 0, 'icon' => null],
        ]),
        API . '/artifacts' => FakeHttpClient::json([
            [
                'game_id' => 15006,
                'name' => 'Crimson Witch of Flames',
                'two_piece' => 'Pyro DMG Bonus +15%',
                'four_piece' => 'Increases Overloaded and Burning DMG by 40%.',
                'rarities' => [4, 5],
                'version' => '1.0',
                'version_name' => null,
                'icon' => 'https://genshin.anotoki.eu/media/artifacts/crimson-witch.avif',
            ],
            [
                'game_id' => 15040,
                'name' => 'A Set of Tomorrow',
                'two_piece' => '<b>ATK</b> +18%',
                'four_piece' => null,
                'rarities' => [5, 'five', 7],
                'version' => '6.2',
                'version_name' => 'Luna III',
                'icon' => null,
            ],
        ]),
    ]);

    same(['outcome' => 'changed', 'written' => 3, 'problem' => null], catalogSync()->refresh(Game::Genshin, 'weapons'), 'three weapons');
    same(
        ['id' => $homa, 'name' => 'Staff of Homa', 'rarity' => 5, 'weapon_type' => 'polearm', 'external_id' => '13501', 'icon_url' => 'https://genshin.anotoki.eu/media/weapons/staff-of-homa.avif'],
        entry('weapons', 'staffofhoma'),
        'Homa\'s row',
    );
    $isshin = (array) entry('weapons', 'prizedisshinblade');
    same(['11510', 'https://genshin.anotoki.eu/media/weapons/prized-isshin-blade.avif'], [$isshin['external_id'], $isshin['icon_url']], 'listed twice: the lower ID\'s, once');
    same([null, null], [entry('weapons', 'notaweapontype')['weapon_type'], entry('weapons', 'notaweapontype')['rarity']], 'a type and a rarity the game does not have');

    same(['outcome' => 'changed', 'written' => 2, 'problem' => null], catalogSync()->refresh(Game::Genshin, 'sets'), 'two sets');
    same(
        [
            'id' => $crimson,
            'name' => 'Crimson Witch of Flames',
            'kind' => 'artifact',
            'external_id' => '15006',
            'icon_url' => 'https://genshin.anotoki.eu/media/artifacts/crimson-witch.avif',
            'meta' => ['rarities' => [4, 5], 'bonuses' => ['Pyro DMG Bonus +15%', 'Increases Overloaded and Burning DMG by 40%.']],
        ],
        entry('sets', 'crimsonwitchofflames'),
        'the set\'s row, its bonuses',
    );
    same(['artifact', ['rarities' => [5], 'bonuses' => ['ATK +18%']]], [entry('sets', 'asetoftomorrow')['kind'], entry('sets', 'asetoftomorrow')['meta']], 'a new set: what can be read of it');
});

test('a renamed entry keeps its row - and all that points at it - found by its in-game ID; one without an ID is found by its name', function () {
    $kazuha = idOf('game_characters', 'kaedeharakazuha');
    $lyney = seedCharacter('genshin', 'Lyney');
    T::$http->fake([
        API . '/characters' => FakeHttpClient::json([
            apiCharacter(['game_id' => 10000047, 'name' => 'Kazuha', 'element' => 'Anemo', 'weapon_type' => 'Sword', 'region' => 'Inazuma', 'version' => '1.6']),
            apiCharacter(['game_id' => 10000084, 'name' => 'Lyney', 'element' => 'Pyro', 'weapon_type' => 'Bow', 'region' => 'Fontaine', 'version' => '4.0']),
        ]),
    ]);

    same(2, catalogSync()->refresh(Game::Genshin, 'characters')['written'], 'two written');
    same(
        [$kazuha, 'Kazuha', '10000047'],
        [entry('characters', 'kazuha')['id'], entry('characters', 'kazuha')['name'], entry('characters', 'kazuha')['external_id']],
        'renamed: the same row, its new key',
    );
    same(null, entry('characters', 'kaedeharakazuha'), 'the old key gone with it');
    same([$lyney, '10000084'], [entry('characters', 'lyney')['id'], entry('characters', 'lyney')['external_id']], 'a row without an in-game ID found by its key, and given one');
});

// ── Asking ───────────────────────────────────────────────────────────────────────────────────────

test('a conditional request: the version tag sent back, "unchanged" writes nothing; a forced refresh asks for the whole list', function () {
    $asked = [];
    T::$http->fake([
        API . '/characters' => function (string $url, array $headers) use (&$asked) {
            $asked[] = $headers['If-None-Match'] ?? null;

            return ($headers['If-None-Match'] ?? null) === '"c1"' ? FakeHttpClient::status(304) : FakeHttpClient::json([apiCharacter()], 200, ['ETag' => '"c1"']);
        },
    ]);

    same('changed', catalogSync()->refresh(Game::Genshin, 'characters')['outcome'], 'the first read');
    db()->run("UPDATE game_characters SET name = 'Hu Tao, renamed here' WHERE key = 'hutao'");
    same(['outcome' => 'unchanged', 'written' => 0, 'problem' => null], catalogSync()->refresh(Game::Genshin, 'characters'), 'the next: as it was');
    same('Hu Tao, renamed here', entry('characters', 'hutao')['name'], 'nothing written');
    same(['outcome' => 'changed', 'written' => 1, 'problem' => null], catalogSync()->refresh(Game::Genshin, 'characters', force: true), 'forced: the whole list');
    same('Hu Tao', entry('characters', 'hutao')['name'], 'written');
    same([null, '"c1"', null], $asked, 'the version tag sent back, but when forced');
});

test('a provider closed, without the list, failing or answering what cannot be read: nothing written, the catalog as it was, the reason kept until a refresh works', function () {
    T::$http->fake([API . '/characters' => FakeHttpClient::json([apiCharacter()], 200, ['ETag' => '"c1"'])]);
    catalogSync()->refresh(Game::Genshin, 'characters');
    $before = [entry('characters', 'hutao'), charactersLine()['refreshed_at']];
    Clock::travel(60);

    $answers = [
        [FakeHttpClient::status(423), 'closed', 'The game data provider is closed for now (HTTP 423).'],
        [FakeHttpClient::status(401), 'closed', 'The game data provider is closed for now (HTTP 401).'],
        // Back in an hour: not asked again within this request.
        [FakeHttpClient::status(503, '', ['Retry-After' => '3600']), 'closed', 'The game data provider is closed for now (HTTP 503).'],
        [FakeHttpClient::status(404), 'unavailable', 'The game data provider has no such list.'],
        [FakeHttpClient::status(418), 'failed', 'The game data provider answered HTTP 418.'],
        [FakeHttpClient::status(200, 'Hu Tao'), 'failed', 'The game data provider answered with something that is not JSON.'],
        [FakeHttpClient::json(['name' => 'Hu Tao']), 'failed', 'The game data provider answered with something that is not a list.'],
        [FakeHttpClient::json(array_fill(0, GenshinCatalog::MAX_ITEMS + 1, ['name' => 'X'])), 'failed', 'The game data provider answered with more than 5000 entries.'],
        [FakeHttpClient::status(200, str_repeat(' ', GenshinCatalog::MAX_BYTES + 1)), 'failed', 'The game data provider answered with more than 5 MB.'],
        [fn() => throw new RuntimeException('No route to host.'), 'failed', 'The game data provider could not be reached.'],
    ];
    foreach ($answers as [$answer, $outcome, $problem]) {
        T::$http->fake([API . '/characters' => $answer]);
        same(['outcome' => $outcome, 'written' => 0, 'problem' => $problem], catalogSync()->refresh(Game::Genshin, 'characters', force: true), $problem);
        same($problem, charactersLine()['problem'], "the reason kept: {$problem}");
    }
    same($before, [entry('characters', 'hutao'), charactersLine()['refreshed_at']], 'the catalog as it was, and when the list was last confirmed');

    T::$http->fake([API . '/characters' => FakeHttpClient::status(304)]);
    same('unchanged', catalogSync()->refresh(Game::Genshin, 'characters')['outcome'], 'a refresh that works');
    same(null, charactersLine()['problem'], 'clears the reason');
    check(charactersLine()['refreshed_at'] !== $before[1], 'and confirms the list now');
});

test('an icon only from the provider\'s own host, over https (http on this computer alone); an AVIF icon is an image the icon route serves', function () {
    $icons = [
        'a' => ['https://genshin.anotoki.eu/media/a.avif', true],
        'b' => ['https://GENSHIN.anotoki.eu/media/b.avif', true],
        'c' => ['http://genshin.anotoki.eu/media/c.avif', false],
        'd' => ['https://elsewhere.example/media/d.avif', false],
        'e' => ['https://genshin.anotoki.eu.elsewhere.example/e.avif', false],
        'f' => ['https://genshin.anotoki.eu@elsewhere.example/f.avif', false],
        'g' => ['https://elsewhere.example\\@genshin.anotoki.eu/g.avif', false],
        'h' => ['//genshin.anotoki.eu/media/h.avif', false],
        'i' => ['https://genshin.anotoki.eu/media/i j.avif', false],
        'j' => ['https://genshin.anotoki.eu/media/' . str_repeat('j', 500) . '.avif', false],
        'k' => ['ftp://genshin.anotoki.eu/k.avif', false],
    ];
    T::$http->fake([
        API . '/characters' => FakeHttpClient::json(array_map(fn(string $letter) => apiCharacter(['game_id' => null, 'name' => "Icon {$letter}", 'icon' => $icons[$letter][0]]), array_keys($icons))),
    ]);
    catalogSync()->refresh(Game::Genshin, 'characters');
    foreach ($icons as $letter => [$url, $kept]) {
        same($kept ? $url : null, entry('characters', "icon{$letter}")['icon_url'], $url);
    }

    T::$app->config->set('catalog.genshin.api', 'http://localhost:8597/api/catalog');
    T::$http->fake(['localhost:8597/api/catalog/characters' => FakeHttpClient::json([apiCharacter(['icon' => 'http://localhost:8597/media/hu-tao.avif'])])]);
    catalogSync()->refresh(Game::Genshin, 'characters', force: true);
    same('http://localhost:8597/media/hu-tao.avif', entry('characters', 'hutao')['icon_url'], 'http on this computer');

    // An AVIF file opens with an "ftyp" box naming its brands.
    $avif = fn(string $major, string ...$compatible): string => pack('N', 16 + 4 * count($compatible)) . 'ftyp' . $major . "\x00\x00\x00\x00" . implode('', $compatible) . str_repeat("\x00", 32);
    T::$http->fake(['localhost:8597/media/hu-tao.avif' => FakeHttpClient::status(200, $avif('avif', 'mif1', 'miaf'))]);
    same(
        'image/avif',
        (new Client())
            ->get('/api/icons/c/' . idOf('game_characters', 'hutao'))
            ->status(200)
            ->header('Content-Type'),
        'served as an AVIF image',
    );

    same('image/avif', Icons::type($avif('avif', 'mif1', 'miaf')), 'a still image');
    same('image/avif', Icons::type($avif('avis', 'msf1')), 'a sequence');
    same('image/avif', Icons::type($avif('mif1', 'avif')), 'AVIF among the compatible brands');
    same(null, Icons::type($avif('heic', 'mif1', 'heic')), 'another image of the family, one browsers do not show');
    same(null, Icons::type(pack('N', 4096) . 'ftypavif' . str_repeat("\x00", 20)), 'a box longer than the file');
    same(null, Icons::type(pack('N', 18) . 'ftypavif' . str_repeat("\x00", 40)), 'a box no list of brands fits');
    same(null, Icons::type('ftypavif'), 'too short');
});

// ── When ─────────────────────────────────────────────────────────────────────────────────────────

test('stale: a list not confirmed within refresh_days; one that failed waits retry_minutes for the next try; when the catalog was last refreshed', function () {
    same(
        [true, null, ['characters', 'weapons', 'sets']],
        [catalogSync()->isStale(Game::Genshin), catalogSync()->lastSyncedAt(Game::Genshin), catalogSync()->staleLists(Game::Genshin)],
        'never refreshed',
    );
    T::$http->fake([
        API . '/characters' => FakeHttpClient::json([apiCharacter()]),
        API . '/weapons' => FakeHttpClient::json([]),
        API . '/artifacts' => FakeHttpClient::status(423),
    ]);
    $start = Clock::timestamp();
    foreach (CatalogSync::LISTS as $list) {
        catalogSync()->refresh(Game::Genshin, $list);
    }
    same(['sets'], catalogSync()->staleLists(Game::Genshin), 'the one that failed');
    same([], catalogSync()->staleLists(Game::Genshin, due: true), 'not to be tried again before retry_minutes');
    same([true, null], [catalogSync()->isStale(Game::Genshin), catalogSync()->lastSyncedAt(Game::Genshin)], 'stale, and not refreshed, while a list never was');

    Clock::travel(61 * 60);
    same(['sets'], catalogSync()->staleLists(Game::Genshin, due: true), 'due after retry_minutes');
    T::$http->fake([API . '/artifacts' => FakeHttpClient::json([])]);
    catalogSync()->refresh(Game::Genshin, 'sets');
    same(false, catalogSync()->isStale(Game::Genshin), 'fresh');
    near((float) $start, (float) catalogSync()->lastSyncedAt(Game::Genshin)?->getTimestamp(), 2.0, 'refreshed when its stalest list was');

    Clock::travel(3 * 86400 - 30 * 60);
    same(['characters', 'weapons'], catalogSync()->staleLists(Game::Genshin), 'refresh_days later: the two confirmed first');
    same(true, catalogSync()->isStale(Game::Genshin), 'stale again');
});

test('the upkeep after an answer: one stale list refreshed - the next at a later look - and one that failed asked again only after retry_minutes', function () {
    T::$http->fake([
        API . '/characters' => FakeHttpClient::json([apiCharacter()]),
        API . '/weapons' => FakeHttpClient::status(423),
        API . '/artifacts' => FakeHttpClient::json([]),
    ]);
    $answer = fn() => (new Client())->get('/api/meta')->status(200);
    $answer();
    same([], T::$http->sent, 'switched off (config upkeep.catalog): nothing asked');

    T::$app->config->set('upkeep.catalog', true);
    $answer();
    same([API . '/characters'], T::$http->sent, 'one list, after that answer');
    same('Hu Tao', entry('characters', 'hutao')['name'], 'written');
    $answer();
    same(1, count(T::$http->sent), 'a look at most every five minutes');

    Clock::travel(301);
    $answer();
    same(API . '/weapons', T::$http->sent[1] ?? null, 'the next stale list at the next look');
    Clock::travel(301);
    $answer();
    same(API . '/artifacts', T::$http->sent[2] ?? null, 'the weapons failed and wait: the sets');
    Clock::travel(301);
    $answer();
    same(3, count(T::$http->sent), 'nothing due');
    Clock::travel(3600);
    $answer();
    same(API . '/weapons', T::$http->sent[3] ?? null, 'the weapons asked again after retry_minutes');
});

// ── Who asks ─────────────────────────────────────────────────────────────────────────────────────

test(
    'the Builds page\'s refresh of a list: what it did, audited; "unchanged" by the version tag; forced in full; a provider closed says why, on the page too; one refresh of a list at a time',
    function () {
        T::$http->fake([
            API . '/characters' => fn(string $url, array $headers) => ($headers['If-None-Match'] ?? null) === '"c1"'
                ? FakeHttpClient::status(304)
                : FakeHttpClient::json([apiCharacter(), apiCharacter(['game_id' => 10000125, 'name' => 'Ineffa', 'element' => 'Electro'])], 200, ['ETag' => '"c1"']),
            API . '/artifacts' => FakeHttpClient::status(423),
        ]);
        $outcome = fn(array $answer): array => array_intersect_key($answer, array_flip(['outcome', 'written']));

        $answer = editor()->post('/api/admin/catalog/genshin/characters')->status(200)->json();
        check(is_string($answer['refreshed_at']), 'confirmed now');
        same(
            ['outcome' => 'changed', 'written' => 2, 'list' => 'characters', 'entries' => 4, 'problem' => null],
            array_diff_key($answer, ['refreshed_at' => true]),
            'two written: four characters now',
        );
        same(['outcome' => 'unchanged', 'written' => 0], $outcome(editor()->post('/api/admin/catalog/genshin/characters')->status(200)->json()), 'again: as it was');
        same(['outcome' => 'changed', 'written' => 2], $outcome(editor()->post('/api/admin/catalog/genshin/characters?force=1')->status(200)->json()), 'forced: in full');
        $audited = db()->all("SELECT actor_id, target_id, details FROM anotoki_audit_log WHERE action = 'catalog.refreshed' ORDER BY id");
        $details = function (string $json): array {
            $details = json_decode($json, true);
            ksort($details);

            return $details;
        };
        same(
            [
                [EDITOR, 'genshin', ['entries' => 2, 'list' => 'characters', 'outcome' => 'changed']],
                [EDITOR, 'genshin', ['entries' => 0, 'list' => 'characters', 'outcome' => 'unchanged']],
                [EDITOR, 'genshin', ['entries' => 2, 'list' => 'characters', 'outcome' => 'changed']],
            ],
            array_map(fn(array $row) => [(int) $row['actor_id'], $row['target_id'], $details((string) $row['details'])], $audited),
            'each audited, by the editor',
        );

        same('The game data provider is closed for now (HTTP 423).', editor()->post('/api/admin/catalog/genshin/sets')->status(502)->json('message'), 'closed: why');
        $lists = array_column(editor()->get('/api/admin/builds')->status(200)->json('0.catalog'), null, 'list');
        same([null, 'The game data provider is closed for now (HTTP 423).'], [$lists['characters']['problem'], $lists['sets']['problem']], 'the page says why too');
        same(3, (int) db()->value("SELECT count(*) FROM anotoki_audit_log WHERE action = 'catalog.refreshed'"), 'a refresh that failed is not audited');

        T::$app->get(Cache::class)->add('catalog-refresh:genshin:weapons', Clock::timestamp(), 120);
        editor()->post('/api/admin/catalog/genshin/weapons')->status(409)->path('message', 'This list is being refreshed. Try again in a moment.');
    },
);

test('the command line: a stale catalog refreshed list by list, a fresh one left alone unless forced; --list takes one; a failure ends 1', function () {
    T::$http->fake([
        API . '/characters' => fn(string $url, array $headers) => ($headers['If-None-Match'] ?? null) === '"c1"'
            ? FakeHttpClient::status(304)
            : FakeHttpClient::json([apiCharacter()], 200, ['ETag' => '"c1"']),
        API . '/weapons' => FakeHttpClient::json([]),
        API . '/artifacts' => FakeHttpClient::json([]),
    ]);
    $run = function (string ...$arguments): array {
        Cli::capture();
        $status = SyncCatalog::run(T::$app, ['sync-catalog.php', ...$arguments]);

        return [$status, explode(PHP_EOL, Cli::captured())];
    };

    same([0, ['Genshin Impact characters: 1 written.', 'Genshin Impact weapons: 0 written.', 'Genshin Impact sets: 0 written.']], $run(), 'list by list');
    same([0, ['Genshin Impact: refreshed within 3 days, skipping (use --force to refresh).']], $run('genshin'), 'fresh: left alone');
    Clock::travel(3 * 86400 + 1);
    same([0, ['Genshin Impact characters: as they were.']], $run('--list=characters'), 'stale again: one list, asked by its version tag');
    same([0, ['Genshin Impact characters: 1 written.']], $run('--list=characters', '--force'), 'forced: in full');

    T::$http->fake([API . '/characters' => FakeHttpClient::status(423)]);
    same([1, ['Genshin Impact characters: The game data provider is closed for now (HTTP 423).']], $run('--force', '--list=characters'), 'a failure');
    same([1, ['The list is characters, weapons, sets.']], $run('--list=relics'), 'a list there is not');
    same([1, ['The game is genshin.']], $run('chess'), 'a game there is not');
});

finishTests();
