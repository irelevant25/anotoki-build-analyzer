<?php

/**
 * The admin panel's builds editor (App\Services\Builds\BuildEditor, api/routes/admin_builds.php; docs/builds.md, "The
 * editor"): what it picks from, a character's builds as it edits them, and its saves - every part read as a builds
 * file's build is, the site's notes in English and Slovak, a build of the file's keeping its name, builds of the
 * editor's own, builds removed and brought back, the order said to be the editor's, nothing written when nothing
 * changed, one save at a time and never against builds that changed meanwhile - and what an import does afterwards.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Auth\Role;
use App\Db\Db;
use App\Services\Builds\BuildFile;
use App\Services\Builds\BuildImport;
use App\Support\Json;
use App\Support\Neutral;
use App\Support\Stats;
use Tests\Support\Answer;
use Tests\Support\Client;
use Tests\Support\FakeHttpClient;

bootTests();

const ADMIN = 1;
const EDITOR = 4;
const MEMBER = 7;

beforeEach(function () {
    seedGenshinCatalog();
    T::$http->fake(['genshin.anotoki.eu/api/catalog/*' => FakeHttpClient::status(423)]);
});

function editor(): Client
{
    return (new Client())->asIam(EDITOR, [Role::EDITOR], ['preferred_username' => 'eddie']);
}

/**
 * A build as the builds file holds it: Hu Tao's main DPS build, unless $fields says otherwise.
 *
 * @return array<string, mixed>
 */
function fileBuild(array $fields = []): array
{
    return $fields + [
        'character' => 'hutao',
        'character_name' => 'Hu Tao',
        'role' => 'main_dps',
        'qualifier' => '',
        'min_rank' => 0,
        'weapons' => [['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'refinement' => 1, 'note' => null], ['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'refinement' => 5, 'note' => null]],
        'sets' => [
            [
                'kind' => 'artifact',
                'choose' => null,
                'pieces' => [['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']]],
                'note' => null,
            ],
        ],
        'main_stats' => ['sands' => [['stat' => 'hp_', 'priority' => 1]], 'goblet' => [['stat' => Stats::ELEMENTAL_DMG, 'priority' => 1]]],
        'substats' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1], ['stat' => 'hp_', 'priority' => 2]],
        'talent_priority' => [['talent' => 'skill', 'priority' => 1]],
        'stat_targets' => [['stat' => 'critRate_', 'min' => 70, 'max' => null, 'text' => '', 'from_rank' => 0]],
        'teams' => [
            [
                'name' => 'Vaporize',
                'members' => [
                    ['key' => 'hutao', 'name' => 'Hu Tao', 'role' => 'main_dps', 'min_rank' => 0, 'alternatives' => []],
                    ['key' => 'kaedeharakazuha', 'name' => 'Kaedehara Kazuha', 'role' => 'support', 'min_rank' => 0, 'alternatives' => []],
                ],
                'note' => null,
            ],
        ],
        'notes' => null,
        'game_version' => '5.4',
    ];
}

/** The tools' file of these builds: Hu Tao's main DPS and support builds, and Kazuha's support build, unless given. */
function importFile(?array $builds = null): Answer
{
    $builds ??= [fileBuild(), fileBuild(['role' => 'support']), fileBuild(['character' => 'kaedeharakazuha', 'character_name' => 'Kaedehara Kazuha', 'role' => 'support', 'teams' => []])];

    return editor()->post('/api/admin/builds/genshin/import', [
        'format' => BuildFile::FORMAT,
        'format_version' => BuildFile::VERSION,
        'game' => 'genshin',
        'exported_at' => '2026-10-01T10:00:00Z',
        'builds' => $builds,
    ]);
}

/** A character's builds as the editor loads them. */
function load(string $key = 'hutao'): array
{
    return editor()
        ->get("/api/admin/builds/genshin/characters/{$key}")
        ->status(200)
        ->json();
}

/**
 * The save of what was loaded, each build through $change first (its entry: id, removal, build, notes) - the order
 * as loaded.
 *
 * @param  ?callable(array<string, mixed>, int): ?array<string, mixed>  $change  null leaves the build out
 * @return array<string, mixed>
 */
function payloadOf(array $loaded, ?callable $change = null): array
{
    $builds = [];
    foreach ($loaded['builds'] as $i => $entry) {
        $item = [
            'id' => $entry['id'],
            'removal' => null,
            'build' => $entry['build'],
            'notes' => [
                'build' => $entry['notes']['build'],
                'teams' => $entry['notes']['teams'],
                'waiting' => array_map(fn(array $waiting) => ['key' => $waiting['key'], 'note' => $waiting['note']], $entry['notes']['waiting']),
            ],
        ];
        $item = $change === null ? $item : $change($item, $i);
        if ($item !== null) {
            $builds[] = $item;
        }
    }

    return ['version' => $loaded['version'], 'builds' => $builds];
}

function save(array $payload, string $key = 'hutao', ?Client $as = null): Answer
{
    return ($as ?? editor())->put("/api/admin/builds/genshin/characters/{$key}", $payload);
}

/** A new build of a save: the editor's own. */
function newBuild(array $build, array $notes = []): array
{
    return ['id' => null, 'removal' => null, 'build' => $build, 'notes' => $notes + ['build' => null, 'teams' => array_fill(0, count($build['teams']), null), 'waiting' => []]];
}

/** The stored row of this identity. */
function row(string $key = 'hutao', string $role = 'main_dps', string $qualifier = ''): ?array
{
    return db()->one('SELECT b.* FROM builds b JOIN game_characters c ON c.id = b.game_character_id WHERE c.key = ? AND b.role = ? AND lower(b.qualifier) = lower(?)', [$key, $role, $qualifier]);
}

/** An array with every object's keys sorted: the audit log keeps its details as JSONB, which gives keys back in an order of its own. */
function sortedKeys(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $value = array_map('sortedKeys', $value);
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return $value;
}

/** What the latest builds.edited entry says the save did - its game and character aside -, its keys sorted. */
function lastEdit(): array
{
    $details = json_decode((string) db()->value("SELECT details FROM anotoki_audit_log WHERE action = 'builds.edited' ORDER BY id DESC LIMIT 1"), true);

    return sortedKeys(array_diff_key($details, ['game' => true, 'character' => true]));
}

/** How many builds.edited entries the audit log holds. */
function edits(): int
{
    return (int) db()->value("SELECT count(*) FROM anotoki_audit_log WHERE action = 'builds.edited'");
}

test('who may: an editor or an admin - nobody signs in first, anybody else is refused', function () {
    importFile()->status(200);
    $payload = payloadOf(load());
    foreach (
        [['GET', '/api/admin/builds/genshin/catalog', null], ['GET', '/api/admin/builds/genshin/characters/hutao', null], ['PUT', '/api/admin/builds/genshin/characters/hutao', $payload]]
        as [$method, $url, $body]
    ) {
        (new Client())->request($method, $url, $body)->status(401)->path('code', 'sign_in_required');
        (new Client())
            ->asIam(MEMBER, [])
            ->request($method, $url, $body)
            ->status(403)
            ->path('code', 'editor_only');
        (new Client())
            ->asIam(ADMIN, [Role::ADMIN])
            ->request($method, $url, $body)
            ->status(200);
    }
    editor()->get('/api/admin/builds/chess/catalog')->status(404);
    editor()->get('/api/admin/builds/genshin/characters/nobody')->status(404);
    save($payload, 'nobody')->status(404);
});

test('what the editor picks from: the game\'s facts with their English names, and the catalog\'s characters, weapons and sets by name, with pictures', function () {
    $catalog = editor()->get('/api/admin/builds/genshin/catalog')->status(200)->json();
    $facts = $catalog['facts'];
    same(['main_dps', 'sub_dps', 'support'], $facts['roles'], 'the roles');
    same([6, 'C', 'R', 4], [$facts['max_rank'], $facts['rank_prefix'], $facts['refinement_prefix'], $facts['team_size']], 'constellations, refinements, a team\'s size');
    same(['sands', 'goblet', 'circlet'], array_keys($facts['slots']), 'the slots whose main stat varies');
    check(
        in_array(Stats::ELEMENTAL_DMG, $facts['slots']['goblet'], true) && !in_array(Stats::ELEMENTAL_DMG, $facts['slots']['sands'], true),
        'a goblet\'s "Elemental DMG", the character\'s own element\'s',
    );
    same(['normal', 'skill', 'burst'], $facts['talents'], 'the talents');
    same(['artifact' => [2, 4]], $facts['set_kinds'], 'the kinds of sets and their pieces');
    same(
        ['CRIT Rate', 'Elemental DMG Bonus', 'Elemental Skill', 'Sands of Eon'],
        [$facts['labels']['stats']['critRate_'], $facts['labels']['stats'][Stats::ELEMENTAL_DMG], $facts['labels']['talents']['skill'], $facts['labels']['slots']['sands']],
        'their English names',
    );
    same(
        [40, 5, 20, 60, 20000, 5000, 30],
        [
            $facts['limits']['qualifier'],
            $facts['limits']['refinement'],
            $facts['limits']['teams'],
            $facts['limits']['team_name'],
            $facts['limits']['note_build'],
            $facts['limits']['note_team'],
            $facts['limits']['builds'],
        ],
        'the caps',
    );

    same(['Hu Tao', 'Kaedehara Kazuha', 'Traveler (Anemo)'], array_column($catalog['characters'], 'name'), 'the characters, by name');
    same(
        ['key' => 'hutao', 'name' => 'Hu Tao', 'element' => 'pyro', 'weapon_type' => 'polearm', 'rarity' => 5],
        array_diff_key($catalog['characters'][0], ['icon_url' => true]),
        'what a character is',
    );
    same(["Dragon's Bane", 'Staff of Homa'], array_column($catalog['weapons'], 'name'), 'the weapons');
    same(['polearm', 4], [$catalog['weapons'][0]['weapon_type'], $catalog['weapons'][0]['rarity']], 'a weapon\'s type and rarity');
    same(['Crimson Witch of Flames', 'Emblem of Severed Fate', "Shimenawa's Reminiscence"], array_column($catalog['sets'], 'name'), 'the sets');
});

test('a character\'s builds as the editor edits them: the file\'s shape without what no page shows, what the file said, the parts edited, the notes, the version', function () {
    importFile()->status(200);
    $main = row();
    db()->update(
        'builds',
        [
            'weapons' => Json::column(array_reverse(Json::decode($main['weapons']))),
            'site_notes' => Json::column(['build' => ['en' => 'Burst low.', 'sk' => 'Ult nízko.'], 'teams' => ['hutao kaedeharakazuha' => ['en' => 'Swirl.'], 'hutao xingqiu' => ['en' => 'Hold.']]]),
        ],
        ['id' => (int) $main['id']],
    );

    $loaded = load();
    same(['game', 'character', 'version', 'order', 'builds', 'removed'], array_keys($loaded), 'what the page gets');
    same(['hutao', 'Hu Tao', 'file', []], [$loaded['character']['key'], $loaded['character']['name'], $loaded['order'], $loaded['removed']], 'the character, the order the file\'s, nothing removed');
    check(preg_match('/^[0-9a-f]{64}$/', $loaded['version']) === 1, 'a version');
    same(
        [['main_dps', 'file', ['weapons']], ['support', 'file', []]],
        array_map(fn(array $entry) => [$entry['build']['role'], $entry['origin'], $entry['edited']], $loaded['builds']),
        'each build, where it is from, what an editor changed',
    );

    $build = $loaded['builds'][0]['build'];
    same(
        ['role', 'qualifier', 'min_rank', 'weapons', 'sets', 'main_stats', 'substats', 'talent_priority', 'stat_targets', 'teams', 'game_version'],
        array_keys($build),
        'the file\'s shape without its character and its notes',
    );
    same(['key', 'name', 'refinement'], array_keys($build['weapons'][0]), 'a weapon without the note no page shows');
    same(["Dragon's Bane", 'Staff of Homa'], array_column($build['weapons'], 'name'), 'the weapons as an editor left them');
    same(['Staff of Homa', "Dragon's Bane"], array_column($loaded['builds'][0]['file']['weapons'], 'name'), 'and as the file said them');
    same(['stat', 'min', 'max', 'from_rank'], array_keys($build['stat_targets'][0]), 'a target without its text');
    same(['name', 'members'], array_keys($build['teams'][0]), 'a team without its note');
    same(
        [
            'build' => ['en' => 'Burst low.', 'sk' => 'Ult nízko.'],
            'teams' => [['en' => 'Swirl.']],
            'waiting' => [['key' => 'hutao xingqiu', 'members' => [['key' => 'hutao', 'name' => 'Hu Tao'], ['key' => 'xingqiu', 'name' => 'xingqiu']], 'note' => ['en' => 'Hold.']]],
        ],
        $loaded['builds'][0]['notes'],
        'the notes: the build\'s, each team\'s beside it, one waiting for a team of its members',
    );
});

test('a save of what was loaded changes nothing and writes nothing', function () {
    importFile()->status(200);
    $before = db()->all('SELECT * FROM builds ORDER BY id');
    $answer = save(payloadOf(load()))->status(200)->json();
    same($before, db()->all('SELECT * FROM builds ORDER BY id'), 'every build as it was, to its last time');
    same([0, load()['version']], [edits(), $answer['version']], 'nothing recorded; the answer is the builds as they are');
});

test('a part changed: members see it at once, what the file said stays beside it - and a later import keeps the edit', function () {
    importFile()->status(200);
    $main = row();
    $payload = payloadOf(load(), function (array $item, int $i) {
        if ($i === 0) {
            $item['build']['weapons'] = [['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'refinement' => 5]];
            $item['build']['game_version'] = '5.5';
        }

        return $item;
    });
    $answer = save($payload)->status(200)->json();
    same(['weapons', 'game_version'], $answer['builds'][0]['edited'], 'the parts an editor changed');

    $after = row();
    same(["Dragon's Bane"], array_column(Json::decode($after['weapons']), 'name'), 'the editor\'s weapons');
    same([true], array_column(Json::decode($after['weapons']), 'known'), 'known to the catalog');
    same($main['file_build'], $after['file_build'], 'what the file said, as it was');
    same($main['content_hash'], $after['content_hash'], 'and its hash');
    check($after['changed_at'] > $main['changed_at'] && $after['edited_at'] !== null, 'changed and edited now');
    same(["Dragon's Bane"], array_column((new Client())->get('/api/builds/genshin/hutao')->status(200)->json('builds.0.weapons'), 'name'), 'what members see');

    $entry = db()->one("SELECT actor_id, target_type, target_id, details FROM anotoki_audit_log WHERE action = 'builds.edited'");
    same([EDITOR, 'character', 'hutao'], [(int) $entry['actor_id'], $entry['target_type'], $entry['target_id']], 'the audit log: who, which character');
    same(
        sortedKeys([
            'created' => [],
            'updated' => [['build' => 'main_dps', 'parts' => ['weapons', 'game_version'], 'renamed' => false, 'moved' => false, 'notes' => false]],
            'removed' => [],
            'restored' => [],
            'order' => null,
        ]),
        lastEdit(),
        'what it did',
    );

    $import = importFile()->status(200)->json();
    same([[], 1], [$import['questions'], $import['edits_kept']], 'the tools\' file again: nothing asked, the edit kept');
    same(["Dragon's Bane"], array_column(Json::decode(row()['weapons']), 'name'), 'still the editor\'s');
});

test('notes in English, Slovak where written - a team\'s going by its members -, each checked; naming another site refused', function () {
    importFile()->status(200);
    $withNotes = fn(array $notes) => payloadOf(load(), fn(array $item, int $i) => $i === 0 ? array_replace($item, ['notes' => $notes]) : $item);

    $answer = save($withNotes(['build' => ['en' => "  Burst low.\r\nThen swirl.  ", 'sk' => 'Ult nízko.'], 'teams' => [['en' => 'Swirl the Pyro.', 'sk' => '']], 'waiting' => []]))
        ->status(200)
        ->json();
    same(
        ['build' => ['en' => "Burst low.\nThen swirl.", 'sk' => 'Ult nízko.'], 'teams' => ['hutao kaedeharakazuha' => ['en' => 'Swirl the Pyro.']]],
        Json::decode(row()['site_notes']),
        'stored: trimmed, a team\'s by its members, an empty Slovak as none',
    );
    same([['en' => 'Swirl the Pyro.']], $answer['builds'][0]['notes']['teams'], 'beside its team');
    same(
        [
            1,
            sortedKeys([
                'created' => [],
                'updated' => [['build' => 'main_dps', 'parts' => [], 'renamed' => false, 'moved' => false, 'notes' => true]],
                'removed' => [],
                'restored' => [],
                'order' => null,
            ]),
        ],
        [edits(), lastEdit()],
        'recorded as a change of notes',
    );

    $before = row()['site_notes'];
    Neutral::setLocalNames(['Fictional Builds']);
    foreach (
        [
            [['build' => ['sk' => 'Len po slovensky.'], 'teams' => [], 'waiting' => []], 'invalid_note', 'notes.build.en'],
            [['build' => ['en' => 'As Fictional Builds says.'], 'teams' => [], 'waiting' => []], 'names_another_site', 'notes.build.en'],
            [['build' => ['en' => 'Fine.', 'sk' => 'See https://example.com/x'], 'teams' => [], 'waiting' => []], 'names_another_site', 'notes.build.sk'],
            [['build' => ['en' => str_repeat('x', 20001)], 'teams' => [], 'waiting' => []], 'invalid_note', 'notes.build.en'],
            [['build' => ['en' => 'Fine.', 'de' => 'Gut.'], 'teams' => [], 'waiting' => []], 'invalid_note', 'notes.build'],
            [['build' => null, 'teams' => [null, null], 'waiting' => []], 'invalid_note', 'notes.teams'],
            [['build' => null, 'teams' => [['en' => str_repeat('x', 5001)]], 'waiting' => []], 'invalid_note', 'notes.teams[0].en'],
            [['build' => null, 'teams' => [null], 'waiting' => [['key' => 'hutao xingqiu', 'note' => null]]], 'invalid_note', 'notes.waiting[0].note'],
            [['build' => null, 'teams' => []], 'invalid_note', 'notes'],
            // A note left out of the teams' list would be a note lost: one for each team, or the save is refused.
            [['build' => null, 'teams' => [], 'waiting' => []], 'invalid_note', 'notes.teams'],
            // A waiting note's members: the build's own character among them, each once, a team's size at most.
            [['build' => null, 'teams' => [null], 'waiting' => [['key' => 'xingqiu yelan', 'note' => ['en' => 'Hold.']]]], 'invalid_note', 'notes.waiting[0].key'],
            [['build' => null, 'teams' => [null], 'waiting' => [['key' => 'hutao hutao', 'note' => ['en' => 'Hold.']]]], 'invalid_note', 'notes.waiting[0].key'],
            [['build' => null, 'teams' => [null], 'waiting' => [['key' => 'bennett hutao xiangling xingqiu yelan', 'note' => ['en' => 'Hold.']]]], 'invalid_note', 'notes.waiting[0].key'],
            [
                ['build' => null, 'teams' => [null], 'waiting' => [['key' => 'hutao xingqiu', 'note' => ['en' => 'A.']], ['key' => 'xingqiu hutao', 'note' => ['en' => 'B.']]]],
                'invalid_note',
                'notes.waiting[1]',
            ],
        ]
        as [$notes, $code, $path]
    ) {
        $refused = save($withNotes($notes))->status(422);
        same([$code, 0, $path], [$refused->json('code'), $refused->json('build'), $refused->json('path')], "{$code} at {$path}: " . $refused->json('message'));
    }
    same($before, row()['site_notes'], 'nothing written by them');

    // A note waiting for a team: kept as it is sent - its members in the order a team's note goes by -, given back as
    // waiting; with its team again, that team's.
    save($withNotes(['build' => null, 'teams' => [null], 'waiting' => [['key' => 'xingqiu hutao', 'note' => ['en' => 'Hold.']]]]))->status(200);
    same(['teams' => ['hutao xingqiu' => ['en' => 'Hold.']]], Json::decode(row()['site_notes']), 'a waiting note kept, by its members sorted');
    save($withNotes(['build' => null, 'teams' => [null], 'waiting' => [['key' => 'hutao kaedeharakazuha', 'note' => ['en' => 'Now the team\'s.']]]]))->status(200);
    same([['en' => 'Now the team\'s.']], load()['builds'][0]['notes']['teams'], 'a waiting note whose team is there: that team\'s');
});

test('a build of the file\'s keeps its name; one of the editor\'s is made, renamed and deleted as the editor likes; two builds of one name refused', function () {
    importFile()->status(200);
    $loaded = load();

    $renamed = save(payloadOf($loaded, fn(array $item, int $i) => $i === 0 ? array_replace_recursive($item, ['build' => ['qualifier' => 'Melt']]) : $item))->status(422);
    same(['invalid_build', 0, 'qualifier'], [$renamed->json('code'), $renamed->json('build'), $renamed->json('path')], 'a file build\'s qualifier: ' . $renamed->json('message'));
    $twice = save(payloadOf($loaded, fn(array $item, int $i) => $i === 1 ? array_replace_recursive($item, ['build' => ['role' => 'main_dps']]) : $item))->status(422);
    same(['invalid_build', 1, 'role'], [$twice->json('code'), $twice->json('build'), $twice->json('path')], 'a file build\'s role too');

    // A build of the editor's own: made, then renamed - its qualifier only in another case is another build no more.
    $payload = payloadOf($loaded);
    $payload['builds'][] = newBuild(array_replace($loaded['builds'][0]['build'], ['role' => 'sub_dps', 'qualifier' => 'Mine']));
    $answer = save($payload)->status(200)->json();
    same(['file', 'file', 'made_here'], array_column($answer['builds'], 'origin'), 'made here, after the file\'s');
    $own = row('hutao', 'sub_dps', 'mine');
    same([null, null], [$own['file_build'], $own['checked_at']], 'no file carries it');

    $payload = payloadOf($answer, fn(array $item, int $i) => $i === 2 ? array_replace_recursive($item, ['build' => ['qualifier' => 'Vape', 'min_rank' => 2]]) : $item);
    save($payload)->status(200);
    same(
        [(int) $own['id'], 'Vape', 2],
        [(int) row('hutao', 'sub_dps', 'vape')['id'], row('hutao', 'sub_dps', 'vape')['qualifier'], (int) row('hutao', 'sub_dps', 'vape')['min_rank']],
        'the same build, renamed',
    );

    $same = payloadOf(load(), fn(array $item, int $i) => $i === 2 ? array_replace_recursive($item, ['build' => ['role' => 'support', 'qualifier' => '', 'min_rank' => 0]]) : $item);
    $refused = save($same)->status(422);
    same(['invalid_build', 2, 'qualifier'], [$refused->json('code'), $refused->json('build'), $refused->json('path')], 'two builds of one name: ' . $refused->json('message'));

    // Deleted: one of the editor's simply goes.
    save(payloadOf(load(), fn(array $item, int $i) => $i === 2 ? null : $item))->status(200);
    same([null, 0], [row('hutao', 'sub_dps', 'vape'), (int) db()->value('SELECT count(*) FROM build_removals')], 'gone, nothing remembered');
});

test('a build of the file\'s removed is remembered with its notes - an import brings it back only when asked to - and brought back by the editor as it was', function () {
    importFile()->status(200);
    db()->update('builds', ['site_notes' => Json::column(['build' => ['en' => 'Our support build.']])], ['id' => (int) row('hutao', 'support')['id']]);
    $answer = save(payloadOf(load(), fn(array $item, int $i) => $i === 1 ? null : $item))->status(200)->json();
    same(
        [['main_dps'], ['support']],
        [array_column(array_column($answer['builds'], 'build'), 'role'), array_column(array_column($answer['removed'], 'build'), 'role')],
        'gone from the builds, among the removed',
    );
    same([null, ['en' => 'Our support build.']], [row('hutao', 'support'), $answer['removed'][0]['notes']['build']], 'its notes kept with it');
    same(sortedKeys(['created' => [], 'updated' => [], 'removed' => ['support'], 'restored' => [], 'order' => null]), lastEdit(), 'recorded');

    same([[], 1], [importFile()->status(200)->json('questions'), (int) db()->value('SELECT count(*) FROM build_removals')], 'the tools\' file again: it stays removed, nothing asked');
    check(row('hutao', 'support') === null, 'still removed');

    // A new build of its name is refused: bring that one back instead.
    $loaded = load();
    $payload = payloadOf($loaded);
    $payload['builds'][] = newBuild(array_replace($loaded['builds'][0]['build'], ['role' => 'support']));
    $refused = save($payload)->status(422);
    same(['invalid_build', 1, 'qualifier'], [$refused->json('code'), $refused->json('build'), $refused->json('path')], 'its name taken: ' . $refused->json('message'));

    // Brought back: as the file said it, with its notes, its removal forgotten.
    $removed = $loaded['removed'][0];
    $payload = payloadOf($loaded);
    $payload['builds'][] = [
        'id' => null,
        'removal' => $removed['id'],
        'build' => $removed['build'],
        'notes' => ['build' => $removed['notes']['build'], 'teams' => $removed['notes']['teams'], 'waiting' => []],
    ];
    $answer = save($payload)->status(200)->json();
    same(['file', ['build' => ['en' => 'Our support build.']]], [$answer['builds'][1]['origin'], Json::decode(row('hutao', 'support')['site_notes'])], 'a build of the file\'s again, with its notes');
    same([0, []], [(int) db()->value('SELECT count(*) FROM build_removals'), $answer['removed']], 'no longer among the removed');
    same(['support'], json_decode((string) db()->value("SELECT details FROM anotoki_audit_log WHERE action = 'builds.edited' ORDER BY id DESC LIMIT 1"), true)['restored'], 'recorded as brought back');
    same([0, 0, 3, 0], [($import = importFile()->status(200)->json())['created'], $import['updated'], $import['unchanged'], $import['removed']], 'an import afterwards: the file\'s builds, unchanged');
});

test('the order: as sent - one other than an import\'s is the editor\'s, said, and kept by imports; "file" gives it back', function () {
    importFile()->status(200);
    $loaded = load();
    $payload = payloadOf($loaded);
    $payload['builds'] = array_reverse($payload['builds']);
    $answer = save($payload)->status(200)->json();
    same([['support', 'main_dps'], 'editors'], [array_column(array_column($answer['builds'], 'build'), 'role'), $answer['order']], 'the editor\'s order, said so');
    same(1, (int) db()->value('SELECT count(*) FROM build_orders'), 'written down');
    same(
        ['support'],
        array_column(
            array_filter(
                json_decode((string) db()->value("SELECT details FROM anotoki_audit_log WHERE action = 'builds.edited'"), true)['updated'],
                fn(array $change) => $change['moved'] && $change['build'] === 'support',
            ),
            'build',
        ),
        'the moves recorded',
    );

    importFile()->status(200);
    same(['support', 'main_dps'], array_column(array_column(load()['builds'], 'build'), 'role'), 'an import keeps it');

    $back = save(payloadOf(load()) + ['order' => 'file'])
        ->status(200)
        ->json();
    same(
        [['main_dps', 'support'], 'file', 0],
        [array_column(array_column($back['builds'], 'build'), 'role'), $back['order'], (int) db()->value('SELECT count(*) FROM build_orders')],
        'given back: the file\'s order',
    );
});

test('one save at a time, never during an import, never against builds that changed since they were loaded - nothing written', function () {
    importFile()->status(200);
    $loaded = load();
    $payload = payloadOf($loaded, fn(array $item, int $i) => $i === 0 ? array_replace_recursive($item, ['build' => ['game_version' => '5.5']]) : $item);

    $other = Db::pdo((array) T::$app->config->get('database'), T::$database, false);
    $other->query('SELECT pg_advisory_lock(' . BuildImport::LOCK . ')');
    try {
        save($payload)->status(409)->path('code', 'busy');
    } finally {
        $other->query('SELECT pg_advisory_unlock(' . BuildImport::LOCK . ')');
    }

    // Another editor saves first.
    save(payloadOf($loaded, fn(array $item, int $i) => $i === 1 ? array_replace_recursive($item, ['build' => ['game_version' => '6.0']]) : $item))->status(200);
    save($payload)->status(409)->path('code', 'changed');
    same(['5.4', '6.0'], [row()['game_version'], row('hutao', 'support')['game_version']], 'nothing of it written');

    // An import that changes nothing an editor sees leaves the version as it was.
    $fresh = load();
    importFile()->status(200);
    same($fresh['version'], load()['version'], 'the same version after an import that changed nothing');
});

test('a build of a save is read as a file\'s build is - its first problem said at its path; a save not even shaped as one, said', function () {
    importFile()->status(200);
    $loaded = load();
    $before = db()->all('SELECT * FROM builds ORDER BY id');
    foreach (
        [
            'a refinement the game does not have' => [
                fn(array $build) => array_replace($build, ['weapons' => [['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'refinement' => 9]]]),
                'weapons[0].refinement',
                'must be a whole number from 1 to 5, or null',
            ],
            'a main stat the slot cannot have' => [
                fn(array $build) => array_replace($build, ['main_stats' => ['sands' => [['stat' => 'critRate_', 'priority' => 1]]]]),
                'main_stats.sands[0].stat',
                'must be',
            ],
            'a field no build has' => [fn(array $build) => $build + ['position' => 3], 'position', 'is not a field of this format'],
            'a field missing' => [fn(array $build) => array_diff_key($build, ['substats' => true]), 'substats', 'is missing'],
            'a team without its build\'s character' => [
                fn(array $build) => array_replace($build, [
                    'teams' => [
                        [
                            'name' => 'Duo',
                            'members' => [
                                ['key' => 'xingqiu', 'name' => 'Xingqiu', 'role' => null, 'min_rank' => 0, 'alternatives' => []],
                                ['key' => 'yelan', 'name' => 'Yelan', 'role' => null, 'min_rank' => 0, 'alternatives' => []],
                            ],
                        ],
                    ],
                ]),
                'teams[0].members',
                "must have the build's own character (hutao) among them",
            ],
        ]
        as $case => [$change, $path, $problem]
    ) {
        $refused = save(
            payloadOf($loaded, fn(array $item, int $i) => $i === 1 ? array_replace($item, ['build' => $change($item['build']), 'notes' => array_replace($item['notes'], ['teams' => []])]) : $item),
        )->status(422);
        same(['invalid_build', 1, $path], [$refused->json('code'), $refused->json('build'), $refused->json('path')], "{$case}: " . $refused->json('message'));
        check(str_starts_with((string) $refused->json('message'), "Build 2: {$path} {$problem}"), "{$case}: said so - " . $refused->json('message'));
    }
    foreach (
        [
            'no version' => ['builds' => []],
            'a version of another form' => ['version' => 'x', 'builds' => []],
            'an order other than the file\'s' => ['version' => $loaded['version'], 'builds' => [], 'order' => 'mine'],
            'a field a save has not' => ['version' => $loaded['version'], 'builds' => [], 'extra' => true],
            'a build without its notes' => ['version' => $loaded['version'], 'builds' => [['id' => null, 'removal' => null, 'build' => []]]],
            'an id of another form' => ['version' => $loaded['version'], 'builds' => [['id' => 'one', 'removal' => null, 'build' => [], 'notes' => []]]],
        ]
        as $case => $body
    ) {
        same('invalid_body', save($body)->status(422)->json('code'), $case);
    }
    $foreign = payloadOf($loaded, fn(array $item, int $i) => $i === 0 ? array_replace($item, ['id' => (int) row('kaedeharakazuha', 'support')['id']]) : $item);
    same(
        ['invalid_build', 0, 'id'],
        [($refused = save($foreign)->status(422))->json('code'), $refused->json('build'), $refused->json('path')],
        'another character\'s build: ' . $refused->json('message'),
    );
    editor()
        ->request('PUT', '/api/admin/builds/genshin/characters/hutao', null, ['body' => 'not json'])
        ->status(400)
        ->path('code', 'invalid_json');
    editor()
        ->request('PUT', '/api/admin/builds/genshin/characters/hutao', null, ['body' => '{}', 'type' => 'text/plain'])
        ->status(415);
    same($before, db()->all('SELECT * FROM builds ORDER BY id'), 'nothing written by any of them');
});

test('a text the editor types names no other site: refused, never reworded behind the editor\'s back - and what the editor reads is neutral', function () {
    importFile()->status(200);
    Neutral::setLocalNames(['Fictional Builds']);
    $loaded = load();
    foreach (
        [
            'qualifier' => fn(array $build) => array_replace($build, ['role' => 'sub_dps', 'qualifier' => 'Fictional Builds pick']),
            'game_version' => fn(array $build) => array_replace($build, ['role' => 'sub_dps', 'game_version' => 'Fictional Builds 5']),
            'teams[0].name' => fn(array $build) => array_replace_recursive($build, ['role' => 'sub_dps', 'teams' => [['name' => 'Fictional Builds Vape']]]),
            'sets[0].pieces[0].label' => fn(array $build) => array_replace_recursive($build, ['role' => 'sub_dps', 'sets' => [['pieces' => [['label' => 'As on Fictional Builds']]]]]),
        ]
        as $path => $change
    ) {
        $payload = payloadOf($loaded);
        $payload['builds'][] = newBuild($change($loaded['builds'][0]['build']));
        $refused = save($payload)->status(422);
        same(['names_another_site', 2, $path], [$refused->json('code'), $refused->json('build'), $refused->json('path')], "{$path}: " . $refused->json('message'));
    }
    same(2, (int) db()->value('SELECT count(*) FROM builds b JOIN game_characters c ON c.id = b.game_character_id WHERE c.key = \'hutao\''), 'nothing written');

    // A stored text the names file learns of later: the editor reads it neutral.
    db()->update('builds', ['game_version' => 'Fictional Builds 5'], ['id' => (int) row()['id']]);
    check(!str_contains(strtolower(editor()->get('/api/admin/builds/genshin/characters/hutao')->status(200)->body), 'fictional'), 'the answer names no site');
});

test('what a builds file has but an editor\'s build has not is refused, never dropped: its character, its notes, the notes of its parts', function () {
    importFile()->status(200);
    $loaded = load();
    foreach (
        [
            'notes' => fn(array $build) => $build + ['notes' => 'My important note'],
            'character' => fn(array $build) => ['character' => 'kaedeharakazuha'] + $build,
            'weapons[0].note' => fn(array $build) => array_replace_recursive($build, ['weapons' => [['note' => 'Best in slot']]]),
            'teams[0].note' => fn(array $build) => array_replace_recursive($build, ['teams' => [['note' => 'Swirl']]]),
            'stat_targets[0].text' => fn(array $build) => array_replace_recursive($build, ['stat_targets' => [['text' => 'CRIT 70%']]]),
        ]
        as $path => $change
    ) {
        $refused = save(payloadOf($loaded, fn(array $item, int $i) => $i === 0 ? array_replace($item, ['build' => $change($item['build'])]) : $item))->status(422);
        same(['invalid_build', 0, $path], [$refused->json('code'), $refused->json('build'), $refused->json('path')], "{$path}: " . $refused->json('message'));
    }

    // A number too large for a float (INF once decoded): said at its path, as a builds file's is - never a 500.
    $body = str_replace('"min":70,', '"min":1e400,', json_encode(payloadOf($loaded), JSON_THROW_ON_ERROR));
    check(str_contains($body, '1e400'), 'the body carries it');
    $refused = editor()
        ->request('PUT', '/api/admin/builds/genshin/characters/hutao', null, ['body' => $body])
        ->status(422);
    same(['invalid_build', 0, 'stat_targets[0].min'], [$refused->json('code'), $refused->json('build'), $refused->json('path')], $refused->json('message'));
    same(0, edits(), 'nothing saved');
});

test('a stored text the names list learns of later blocks no save and makes no edit: what the page sends back as it got it stays as stored', function () {
    importFile([fileBuild(), fileBuild(['role' => 'support', 'qualifier' => 'Fictional Duo', 'game_version' => 'Fictional 5'])])->status(200);
    Neutral::setLocalNames(['Fictional']);
    $loaded = load();
    same(['The source Duo', 'The source 5'], [$loaded['builds'][1]['build']['qualifier'], $loaded['builds'][1]['build']['game_version']], 'sent neutral');

    $before = db()->all('SELECT * FROM builds ORDER BY id');
    save(payloadOf($loaded))->status(200);
    same([$before, 0], [db()->all('SELECT * FROM builds ORDER BY id'), edits()], 'sent back as it came: nothing written, no edit');

    // Another part changed: saved, the untouched texts kept as stored, no edit of them.
    $answer = save(
        payloadOf(load(), function (array $item, int $i) {
            if ($i === 1) {
                $item['build']['substats'] = [['stat' => 'hp_', 'priority' => 1]];
            }

            return $item;
        }),
    )
        ->status(200)
        ->json();
    same(['substats'], $answer['builds'][1]['edited'], 'the substats alone an edit');
    same(['Fictional Duo', 'Fictional 5'], [row('hutao', 'support', 'fictional duo')['qualifier'], row('hutao', 'support', 'fictional duo')['game_version']], 'the stored texts as they were');
});

test('two builds of the editor\'s own trade their names in one save', function () {
    importFile()->status(200);
    $loaded = load();
    $payload = payloadOf($loaded);
    $payload['builds'][] = newBuild(array_replace($loaded['builds'][0]['build'], ['role' => 'sub_dps', 'qualifier' => 'Melt']));
    $payload['builds'][] = newBuild(array_replace($loaded['builds'][0]['build'], ['role' => 'sub_dps', 'qualifier' => 'Vape', 'game_version' => '6.0']));
    $made = save($payload)->status(200)->json();
    [$melt, $vape] = [(int) row('hutao', 'sub_dps', 'melt')['id'], (int) row('hutao', 'sub_dps', 'vape')['id']];

    save(payloadOf($made, fn(array $item, int $i) => $i >= 2 ? array_replace_recursive($item, ['build' => ['qualifier' => $i === 2 ? 'Vape' : 'Melt']]) : $item))->status(200);
    same([$melt, $vape], [(int) row('hutao', 'sub_dps', 'vape')['id'], (int) row('hutao', 'sub_dps', 'melt')['id']], 'each build with the other\'s name');
    same('6.0', row('hutao', 'sub_dps', 'melt')['game_version'], 'its content its own');
});

test('a note opening a sentence with "the source" names nobody; each build and each removed one says where the file puts it', function () {
    importFile()->status(200);
    $loaded = load();
    save(
        payloadOf(
            $loaded,
            fn(array $item, int $i) => $i === 0
                ? array_replace($item, ['notes' => ['build' => ['en' => 'Burst first. the source of most damage is the burst.'], 'teams' => [null], 'waiting' => []]])
                : $item,
        ),
    )->status(200);
    same([0, 1], array_column($loaded['builds'], 'file_position'), 'each build\'s place in the file');
    $answer = save(payloadOf(load(), fn(array $item, int $i) => $i === 0 ? null : $item))->status(200)->json();
    same([0], array_column($answer['removed'], 'file_position'), 'and a removed one\'s, to bring it back where it was');
});

test(
    'the targets the site works out come with each build - what Auto gives; the tiers an editor sets by hand and a stat switched off are saved, shown to members, left out of what is worked out, and kept by an import',
    function () {
        importFile()->status(200);
        // Hu Tao's and Staff of Homa's stats, as a catalog refresh keeps them.
        db()->update(
            'game_characters',
            [
                'meta' => Json::encode([
                    'stats' => [
                        'base' => ['hp' => 1210.72, 'atk' => 8.29, 'def' => 68.21],
                        'lv80' => ['hp' => 14459.03, 'atk' => 98.95, 'def' => 814.56],
                        'ascension' => ['stat' => 'critDMG_', 'value' => 38.4],
                    ],
                ]),
            ],
            ['key' => 'hutao'],
        );
        db()->update('game_weapons', ['meta' => Json::encode(['stats' => ['base_atk' => 46, 'lv80_atk' => 563, 'secondary' => ['stat' => 'critDMG_', 'value' => 14.4]]])], ['key' => 'staffofhoma']);
        $loaded = load();
        $computed = $loaded['builds'][0]['computed'];
        same('Staff of Homa', $computed['basis']['weapon'], 'worked out for the build\'s first weapon');
        same(['critRate_', 'critDMG_', 'hp'], array_column($computed['rows'], 'stat'), 'the stated CRIT Rate, then the substats in tiers');
        same(null, load('kaedeharakazuha')['builds'][0]['computed'], 'none for a character the catalog has no stats of');

        $payload = payloadOf($loaded, function (array $item, int $i) {
            if ($i === 0) {
                $item['build']['stat_targets'][] = ['stat' => 'critDMG_', 'min' => null, 'max' => null, 'from_rank' => 0, 'tiers' => ['basic' => 180, 'intermediate' => 210, 'advanced' => 240]];
                $item['build']['stat_targets'][] = ['stat' => 'hp', 'min' => null, 'max' => null, 'from_rank' => 0, 'off' => true];
            }

            return $item;
        });
        $answer = save($payload)->status(200)->json();
        same(['stat_targets'], $answer['builds'][0]['edited'], 'the part edited');
        same(['critRate_', 'critDMG_', 'hp'], array_column($answer['builds'][0]['computed']['rows'], 'stat'), 'what is worked out stays whole: Auto\'s values for every stat');
        same(
            [
                ['stat' => 'critRate_', 'min' => 70, 'max' => null, 'from_rank' => 0],
                ['stat' => 'critDMG_', 'min' => null, 'max' => null, 'from_rank' => 0, 'tiers' => ['basic' => 180, 'intermediate' => 210, 'advanced' => 240]],
                ['stat' => 'hp', 'min' => null, 'max' => null, 'from_rank' => 0, 'off' => true],
            ],
            $answer['builds'][0]['build']['stat_targets'],
            'the targets as the editor left them, each of its kind',
        );
        $members = (new Client())->get('/api/builds/genshin/hutao')->status(200)->json('builds.0.targets.rows');
        same(
            [['critRate_', null], ['critDMG_', ['basic' => 180, 'intermediate' => 210, 'advanced' => 240]]],
            array_map(fn(array $row) => [$row['stat'], $row['tiers']], $members),
            'members see the tiers set by hand, and no HP',
        );

        $import = importFile()->status(200)->json();
        same([[], 1], [$import['questions'], $import['edits_kept']], 'the tools\' file again: nothing asked, the edit kept');
        same(3, count(Json::decode(row()['stat_targets'])), 'still the editor\'s three');
    },
);

test('the Builds page lists every character with builds, with what the editors did to them', function () {
    importFile()->status(200);
    $loaded = load();
    $payload = payloadOf($loaded, function (array $item, int $i) {
        if ($i === 0) {
            $item['build']['game_version'] = '5.5';
            $item['notes']['build'] = ['en' => 'A note.'];

            return $item;
        }

        return null;
    });
    $payload['builds'][] = newBuild(array_replace($loaded['builds'][0]['build'], ['role' => 'sub_dps']));
    $payload['builds'] = array_reverse($payload['builds']);
    save($payload)->status(200);

    $editing = editor()->get('/api/admin/builds')->status(200)->json('0.editing');
    same(['Hu Tao', 'Kaedehara Kazuha'], array_column($editing, 'name'), 'the characters with builds, by name');
    same(
        ['key' => 'hutao', 'name' => 'Hu Tao', 'builds' => 2, 'edited' => 1, 'made_here' => 1, 'removed' => 1, 'notes' => 1, 'ordered' => true],
        array_diff_key($editing[0], ['icon_url' => true]),
        'Hu Tao\'s: one edited, one made here, one removed, one with notes, the order the editor\'s',
    );
    same(
        ['builds' => 1, 'edited' => 0, 'made_here' => 0, 'removed' => 0, 'notes' => 0, 'ordered' => false],
        array_intersect_key($editing[1], array_flip(['builds', 'edited', 'made_here', 'removed', 'notes', 'ordered'])),
        'Kazuha\'s: nothing done',
    );
});

finishTests();
