<?php

/**
 * A game's builds brought in from a file and written out to one (App\Services\Builds\BuildImport, BuildFile,
 * api/routes/admin_builds.php): the file is the whole truth for its game, a build found again by its identity
 * keeps its id, characters the catalog lacks are skipped, a dry run writes nothing, one import at a time, every
 * text neutral, every import recorded - the catalog refreshed list by list beside it - and the public builds
 * routes that show what was imported.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Auth\Role;
use App\Cache\Cache;
use App\Db\Db;
use App\Services\Builds\BuildFile;
use App\Services\Builds\BuildImport;
use App\Support\Clock;
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
});

function editor(): Client
{
    return (new Client())->asIam(EDITOR, [Role::EDITOR], ['preferred_username' => 'eddie']);
}

/**
 * A build as the file holds it: Hu Tao's main DPS build, unless $fields says otherwise.
 *
 * @param  array<string, mixed>  $fields
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
        'weapons' => [
            ['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'refinement' => 1, 'note' => null],
            ['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'refinement' => 5, 'note' => 'A fine second choice.'],
        ],
        'sets' => [
            [
                'kind' => 'artifact',
                'choose' => null,
                'pieces' => [['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']]],
                'note' => null,
            ],
        ],
        'main_stats' => [
            'sands' => [['stat' => 'hp_', 'priority' => 1]],
            'goblet' => [['stat' => Stats::ELEMENTAL_DMG, 'priority' => 1]],
            'circlet' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1]],
        ],
        'substats' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1], ['stat' => 'hp_', 'priority' => 2]],
        'talent_priority' => [['talent' => 'skill', 'priority' => 1], ['talent' => 'burst', 'priority' => 2]],
        'stat_targets' => [['stat' => 'critRate_', 'min' => 70, 'max' => null, 'text' => 'CRIT Rate 70%', 'from_rank' => 0]],
        'notes' => null,
        'game_version' => '5.4',
    ];
}

/** Kazuha's support build. */
function kazuhaBuild(array $fields = []): array
{
    return fileBuild($fields + ['character' => 'kaedeharakazuha', 'character_name' => 'Kaedehara Kazuha', 'role' => 'support']);
}

/**
 * The file of these builds, made at $exported.
 *
 * @param  list<array<string, mixed>>  $builds
 * @return array<string, mixed>
 */
function buildsFile(array $builds, string $exported = '2026-10-01T10:00:00Z'): array
{
    return ['format' => BuildFile::FORMAT, 'format_version' => 2, 'game' => 'genshin', 'exported_at' => $exported, 'builds' => $builds];
}

function import(array $file, bool $dryRun = false, ?Client $as = null): Answer
{
    return ($as ?? editor())->post('/api/admin/builds/genshin/import' . ($dryRun ? '?dry_run=1' : ''), $file);
}

/**
 * The stored builds, as "character role 'qualifier' C{min_rank} #{position}" => id, by character and position.
 *
 * @return array<string, int>
 */
function stored(): array
{
    $result = [];
    foreach (db()->all('SELECT b.id, c.key, b.role, b.qualifier, b.min_rank, b."position" FROM builds b JOIN game_characters c ON c.id = b.game_character_id ORDER BY c.key, b."position"') as $row) {
        $result["{$row['key']} {$row['role']} '{$row['qualifier']}' C{$row['min_rank']} #{$row['position']}"] = (int) $row['id'];
    }

    return $result;
}

/** [created, updated, unchanged, removed] of an answer. */
function counts(array $answer): array
{
    return [$answer['created'], $answer['updated'], $answer['unchanged'], $answer['removed']];
}

test('an import writes the file\'s builds; the same file again changes nothing, every id kept; the export imports as itself', function () {
    $file = buildsFile([fileBuild(), fileBuild(['role' => 'support', 'qualifier' => 'Melt']), kazuhaBuild()]);

    $first = import($file)->status(200)->json();
    same([3, 0, 0, 0], counts($first), 'three created');
    same(
        ['dry_run' => false, 'game' => 'genshin', 'exported_at' => '2026-10-01T10:00:00+00:00', 'older_than_current' => false, 'characters' => 2, 'builds' => 3, 'skipped' => []],
        array_intersect_key($first, array_flip(['dry_run', 'game', 'exported_at', 'older_than_current', 'characters', 'builds', 'skipped'])),
        'what the answer says of the file',
    );
    $ids = stored();
    same(["hutao main_dps '' C0 #0", "hutao support 'Melt' C0 #1", "kaedeharakazuha support '' C0 #0"], array_keys($ids), 'the builds, each character\'s in the file\'s order');

    same([0, 0, 3, 0], counts(import($file)->status(200)->json()), 'the same file again: nothing changed');
    same($ids, stored(), 'every id kept');

    $download = editor()->get('/api/admin/builds/genshin/export')->status(200);
    check(str_contains($download->header('Content-Disposition'), 'attachment; filename="builds-genshin-'), 'a file to save: ' . $download->header('Content-Disposition'));
    $exported = $download->json();
    same(['format', 'format_version', 'game', 'exported_at', 'builds'], array_keys($exported), 'the file\'s fields');
    same(fileBuild(), $exported['builds'][0], 'a build as the file had it: no rank, no "known", nothing of the store\'s');
    same([0, 0, 3, 0], counts(import($exported)->status(200)->json()), 'the export imports as itself');
    same($ids, stored(), 'and keeps every id');
});

test('the file is the whole truth for its game: a build it lacks goes, a character it lacks loses its builds, the order is its', function () {
    import(buildsFile([fileBuild(), fileBuild(['role' => 'support', 'qualifier' => 'Melt']), kazuhaBuild()]))->status(200);
    $before = stored();

    $answer = import(buildsFile([fileBuild(['role' => 'support', 'qualifier' => 'Melt']), fileBuild()]))->status(200)->json();
    same([0, 2, 0, 1], counts($answer), 'Hu Tao\'s two changed places, Kazuha\'s build went');
    same(
        ["hutao support 'Melt' C0 #0" => $before["hutao support 'Melt' C0 #1"], "hutao main_dps '' C0 #1" => $before["hutao main_dps '' C0 #0"]],
        stored(),
        'the order is the file\'s, the ids the same',
    );
    same(
        [
            ['change' => 'updated', 'character' => ['key' => 'hutao', 'name' => 'Hu Tao'], 'role' => 'support', 'qualifier' => 'Melt', 'min_rank' => 0],
            ['change' => 'updated', 'character' => ['key' => 'hutao', 'name' => 'Hu Tao'], 'role' => 'main_dps', 'qualifier' => '', 'min_rank' => 0],
            ['change' => 'removed', 'character' => ['key' => 'kaedeharakazuha', 'name' => 'Kaedehara Kazuha'], 'role' => 'support', 'qualifier' => '', 'min_rank' => 0],
        ],
        $answer['changes'],
        'the changes, by character',
    );
    same(3, $answer['changes_listed'], 'all of them listed');

    // An empty file is a file too: every build goes - the dry run says so first.
    same([0, 0, 0, 2], counts(import(buildsFile([]), dryRun: true)->status(200)->json()), 'the dry run of an empty file');
    import(buildsFile([]))->status(200);
    same([], stored(), 'nothing left');
});

test('a build is found again by its identity: its qualifier in another case is the same build, another constellation another one', function () {
    import(buildsFile([fileBuild(['qualifier' => 'melt'])]))->status(200);
    $id = stored()["hutao main_dps 'melt' C0 #0"];

    same([0, 1, 0, 0], counts(import(buildsFile([fileBuild(['qualifier' => 'Melt'])]))->status(200)->json()), 'the case changed: updated');
    same(["hutao main_dps 'Melt' C0 #0" => $id], stored(), 'the same build, its qualifier as the file spells it');

    same([1, 0, 0, 1], counts(import(buildsFile([fileBuild(['qualifier' => 'Melt', 'min_rank' => 2])]))->status(200)->json()), 'another constellation: another build');
    check(stored()["hutao main_dps 'Melt' C2 #0"] !== $id, 'with an id of its own');
});

test('characters the catalog lacks are skipped and named; a file with none it has is refused, and nothing changes', function () {
    $newcomer = fn(string $role) => fileBuild(['character' => 'newcomer', 'character_name' => 'The Newcomer', 'role' => $role]);

    $answer = import(buildsFile([fileBuild(), $newcomer('main_dps'), $newcomer('support')]))->status(200)->json();
    same([['key' => 'newcomer', 'name' => 'The Newcomer', 'builds' => 2]], $answer['skipped'], 'skipped, with how many builds');
    same([1, 1, 1], [$answer['created'], $answer['characters'], $answer['builds']], 'Hu Tao\'s build alone');

    $before = [stored(), (int) db()->value('SELECT count(*) FROM build_imports')];
    $refused = import(buildsFile([$newcomer('main_dps')]))->status(422);
    same("None of the file's characters is in this installation's catalog. Refresh the catalog on the Builds page, then import again.", $refused->json('message'), 'refused: it would only remove');
    same($before, [stored(), (int) db()->value('SELECT count(*) FROM build_imports')], 'nothing changed, nothing recorded');
});

test('a dry run says what an import would do and writes nothing', function () {
    import(buildsFile([fileBuild(), kazuhaBuild()]))->status(200);
    $before = [stored(), (int) db()->value('SELECT count(*) FROM build_imports'), (int) db()->value("SELECT count(*) FROM anotoki_audit_log WHERE action = 'builds.imported'")];

    $dry = import(buildsFile([fileBuild(['substats' => [['stat' => 'critRate_', 'priority' => 1]]]), fileBuild(['role' => 'sub_dps'])]), dryRun: true)->status(200)->json();
    same(true, $dry['dry_run'], 'a dry run');
    same([1, 1, 0, 1], counts($dry), 'what it would do');
    same(['updated', 'created', 'removed'], array_column($dry['changes'], 'change'), 'its changes: Hu Tao\'s as the file has them, then Kazuha\'s');
    same(
        $before,
        [stored(), (int) db()->value('SELECT count(*) FROM build_imports'), (int) db()->value("SELECT count(*) FROM anotoki_audit_log WHERE action = 'builds.imported'")],
        'nothing written, nothing recorded',
    );
});

test('files the import refuses, each with its first problem - and nothing written', function () {
    $without = function (string $field): array {
        $build = fileBuild();
        unset($build[$field]);

        return $build;
    };
    $sands = fn(string $stat) => fileBuild(['main_stats' => ['sands' => [['stat' => $stat, 'priority' => 1]]]]);
    $cases = [
        'not a builds file' => [['format' => 'something else'], 'This is not a builds file of the build analyzer.'],
        'format version 1' => [['format_version' => 1] + buildsFile([]), 'This builds file has format version 1 (the builds of one source). This installation reads version 2'],
        'a newer format' => [['format_version' => 3] + buildsFile([]), 'This builds file has format version 3, and this installation reads version 2. Update this installation, then import again.'],
        'another game' => [['game' => 'hsr'] + buildsFile([]), 'This builds file names no game this installation knows.'],
        'a field of the file missing' => [array_diff_key(buildsFile([]), ['exported_at' => true]), "The file's exported_at is missing."],
        'a time that is none' => [buildsFile([], '2026-02-30T10:00:00Z'), "The file's exported_at must be a time in ISO 8601 from 1970 on"],
        'a field of a build missing' => [buildsFile([$without('notes')]), "The file's builds[0] (hutao, main_dps) is not valid: notes is missing."],
        'a field the format does not have' => [buildsFile([fileBuild(['variant' => 'DPS'])]), 'variant is not a field of this format.'],
        'a role the game does not have' => [buildsFile([fileBuild(['role' => 'healer'])]), "The file's builds[0] is not valid: role must be main_dps, sub_dps or support."],
        'a qualifier of two lines' => [buildsFile([fileBuild(['qualifier' => "Melt\nVape"])]), 'qualifier must be one line of text.'],
        'a qualifier too long' => [buildsFile([fileBuild(['qualifier' => str_repeat('x', 41)])]), 'qualifier must not be longer than 40 characters.'],
        'a constellation the game does not have' => [buildsFile([fileBuild(['min_rank' => 7])]), 'min_rank must be a whole number from 0 to 6.'],
        'a refinement the game does not have' => [
            buildsFile([fileBuild(['weapons' => [['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'refinement' => 6, 'note' => null]]])]),
            'weapons[0].refinement must be a whole number from 1 to 5, or null.',
        ],
        'a main stat the slot cannot have' => [buildsFile([$sands('critRate_')]), 'main_stats.sands[0].stat must be hp_, atk_, def_, eleMas or enerRech_.'],
        'a slot whose main stat never varies' => [
            buildsFile([fileBuild(['main_stats' => ['flower' => [['stat' => 'hp', 'priority' => 1]]]])]),
            'main_stats.flower is not a slot whose main stat varies (sands, goblet, circlet).',
        ],
        'a substat twice' => [
            buildsFile([fileBuild(['substats' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critRate_', 'priority' => 2]]])]),
            'substats[1].stat repeats an earlier entry of its list.',
        ],
        'a substat no piece rolls' => [buildsFile([fileBuild(['substats' => [['stat' => 'pyro_dmg_', 'priority' => 1]]])]), 'substats[0].stat must be '],
        'a target from one constellation twice' => [
            buildsFile([
                fileBuild([
                    'stat_targets' => [
                        ['stat' => 'enerRech_', 'min' => 200, 'max' => null, 'text' => '', 'from_rank' => 1],
                        ['stat' => 'enerRech_', 'min' => 180, 'max' => null, 'text' => '', 'from_rank' => 1],
                    ],
                ]),
            ]),
            'stat_targets[1].stat repeats stat_targets[0] from the same from_rank.',
        ],
        'a target above its own max' => [
            buildsFile([fileBuild(['stat_targets' => [['stat' => 'enerRech_', 'min' => 280, 'max' => 260, 'text' => '', 'from_rank' => 0]]])]),
            'stat_targets[0].min must not be greater than its max.',
        ],
        'the same build twice' => [
            buildsFile([fileBuild(), fileBuild()]),
            "The file's builds[1] (hutao, main_dps) is not valid: its character, role, qualifier and min_rank are those of builds[0] too.",
        ],
        'the same build twice, the case of its qualifier aside' => [buildsFile([fileBuild(['qualifier' => 'Melt']), fileBuild(['qualifier' => 'MELT'])]), 'are those of builds[0] too.'],
        'a set of three pieces' => [
            buildsFile([fileBuild(['sets' => [['kind' => 'artifact', 'choose' => null, 'pieces' => [['label' => 'X', 'count' => 3, 'keys' => ['x'], 'names' => ['X']]], 'note' => null]]])]),
            'sets[0].pieces[0].count must be 2 or 4.',
        ],
    ];
    foreach ($cases as $case => [$file, $problem]) {
        $message = (string) import($file)->status(422)->json('message');
        check(str_contains($message, $problem), "{$case}: {$message}");
    }
    same([[], 0], [stored(), (int) db()->value('SELECT count(*) FROM build_imports')], 'nothing written');

    // What PHP makes of a body larger than its post_max_size: nothing.
    same(
        'No file arrived. A file larger than this server accepts arrives empty.',
        editor()
            ->request('POST', '/api/admin/builds/genshin/import', null, ['body' => ''])
            ->status(422)
            ->json('message'),
        'an empty body',
    );
});

test('every text is made neutral on the way in - a note, a qualifier, a weapon\'s - so no site is ever named', function () {
    Neutral::setLocalNames(['Fictional Builds']);
    $build = fileBuild([
        'qualifier' => 'Fictional Builds pick',
        'notes' => 'From Fictional Builds: see https://fictional.example/hu-tao for more.',
        'weapons' => [['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'refinement' => 1, 'note' => 'Fictional Builds likes it.']],
    ]);
    import(buildsFile([$build]))->status(200);

    $row = db()->one('SELECT qualifier, notes, weapons FROM builds');
    same(['The source pick', 'From the source: see the source page for more.'], [$row['qualifier'], $row['notes']], 'stored neutral');
    check(str_contains($row['weapons'], 'The source likes it.'), 'a weapon\'s note too: ' . $row['weapons']);
    $public = (new Client())->get('/api/builds/genshin/hutao')->status(200);
    check(!str_contains(strtolower($public->body), 'fictional'), 'the API names no site: ' . $public->body);
    check(!str_contains(strtolower(editor()->get('/api/admin/builds/genshin/export')->body), 'fictional'), 'nor does the export');
});

test('a time from another computer\'s clock is never later than now here', function () {
    import(buildsFile([fileBuild()], '2099-01-01T00:00:00Z'))->status(200);
    $now = Clock::timestamp();
    foreach (['SELECT checked_at FROM builds', 'SELECT changed_at FROM builds', 'SELECT file_exported_at FROM build_imports'] as $sql) {
        check(Db::time(db()->value($sql))->getTimestamp() <= $now, "{$sql}: not in the future");
    }
});

test('every import is recorded: a row of build_imports, the audit log\'s builds.imported, the Builds page\'s list with who made it', function () {
    import(buildsFile([fileBuild(), kazuhaBuild()], '2026-10-01T10:00:00Z'))->status(200);

    $entries = db()->all("SELECT actor_id, target_type, target_id, details FROM anotoki_audit_log WHERE action = 'builds.imported'");
    same([[EDITOR, 'game', 'genshin']], array_map(fn(array $row) => [(int) $row['actor_id'], $row['target_type'], $row['target_id']], $entries), 'the audit log, by the editor, of the game');
    $details = array_intersect_key(json_decode($entries[0]['details'], true), array_flip(['created', 'updated', 'removed', 'characters', 'skipped']));
    ksort($details);
    same(['characters' => 2, 'created' => 2, 'removed' => 0, 'skipped' => 0, 'updated' => 0], $details, 'what it did');

    $page = (new Client())
        ->asIam(ADMIN, [Role::ADMIN], ['preferred_username' => 'ada'])
        ->get('/api/admin/builds')
        ->status(200)
        ->json('0');
    same(['genshin', 2, 2], [$page['game'], $page['characters'], $page['builds']], 'the counts');
    same(['id' => EDITOR, 'name' => 'eddie'], $page['imports'][0]['imported_by'], 'who imported it');
    same(['characters' => 2, 'builds' => 2, 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'skipped' => 0], $page['imports'][0]['summary'], 'what it did - and nobody\'s name');
    same('2026-10-01T10:00:00+00:00', $page['imports'][0]['file_exported_at'], 'when its file was made');
    same(['characters', 'weapons', 'sets'], array_column($page['catalog'], 'list'), 'the catalog\'s lists beside them');

    // A file older than the one the builds came from says so; a newer one does not.
    same(true, import(buildsFile([fileBuild()], '2026-09-01T10:00:00Z'), dryRun: true)->status(200)->json('older_than_current'), 'an older file, said so');
    same(true, import(buildsFile([fileBuild()], '2026-09-01T10:00:00Z'))->status(200)->json('older_than_current'), 'and imported, said so too');
    same(false, import(buildsFile([fileBuild()], '2026-10-02T10:00:00Z'))->status(200)->json('older_than_current'), 'a newer one');
});

test('one import at a time: while another holds the lock an import is refused, busy, and writes nothing', function () {
    $other = Db::pdo((array) T::$app->config->get('database'), T::$database, false);
    $other->query('SELECT pg_advisory_lock(' . BuildImport::LOCK . ')');

    try {
        same('Another import of builds is being written. Try again in a moment.', import(buildsFile([fileBuild()]))->status(409)->json('message'), 'busy');
        same([], stored(), 'nothing written');
        // A dry run takes no lock.
        same([1, 0, 0, 0], counts(import(buildsFile([fileBuild()]), dryRun: true)->status(200)->json()), 'a dry run goes through');
    } finally {
        $other->query('SELECT pg_advisory_unlock(' . BuildImport::LOCK . ')');
    }
    import(buildsFile([fileBuild()]))->status(200);
    same(1, count(stored()), 'once free, it goes through');
});

test('who may: an editor or an admin - nobody signs in first, anybody else is refused', function () {
    $routes = [
        ['GET', '/api/admin/builds', null],
        ['GET', '/api/admin/builds/genshin/export', null],
        ['POST', '/api/admin/builds/genshin/import', buildsFile([fileBuild()])],
        ['POST', '/api/admin/catalog/genshin/characters', []],
    ];
    foreach ($routes as [$method, $url, $body]) {
        (new Client())->request($method, $url, $body)->status(401)->path('code', 'sign_in_required');
        (new Client())
            ->asIam(MEMBER, [])
            ->request($method, $url, $body)
            ->status(403)
            ->path('code', 'editor_only');
    }
    same([], stored(), 'nothing written by them');

    $admin = (new Client())->asIam(ADMIN, [Role::ADMIN]);
    $admin->get('/api/admin/builds')->status(200);
    $admin->get('/api/admin/builds/genshin/export')->status(200);
    $admin->get('/api/admin/builds/chess/export')->status(404);
    $admin->post('/api/admin/catalog/genshin/relics')->status(404);
});

test('what everybody reads: per game the counts, the characters with their roles, a character\'s builds in their order', function () {
    import(
        buildsFile([
            fileBuild(),
            fileBuild(['role' => 'support', 'qualifier' => 'Melt', 'min_rank' => 2]),
            kazuhaBuild(['weapons' => [['key' => 'deathmatch', 'name' => 'Deathmatch', 'refinement' => null, 'note' => null]]]),
        ]),
    )->status(200);

    $games = (new Client())->get('/api/builds')->status(200);
    same([['game' => 'genshin', 'characters' => 2, 'builds' => 3, 'checked_at' => '2026-10-01T10:00:00+00:00', 'changed_at' => '2026-10-01T10:00:00+00:00']], $games->json(), 'per game');
    check(!str_contains($games->body, 'eddie'), 'nobody named');

    $characters = array_column((new Client())->get('/api/builds/genshin')->status(200)->json(), null, 'key');
    same([2, ['main_dps', 'support']], [$characters['hutao']['builds'], $characters['hutao']['roles']], 'Hu Tao\'s');
    same(['support'], $characters['kaedeharakazuha']['roles'], 'Kazuha\'s');

    $builds = (new Client())->get('/api/builds/genshin/hutao')->status(200)->json('builds');
    same([['main_dps', '', 0, 0], ['support', 'Melt', 2, 1]], array_map(fn($b) => [$b['role'], $b['qualifier'], $b['min_rank'], $b['position']], $builds), 'in their order, each its variation');
    same([true, true], array_column($builds[0]['weapons'], 'known'), 'weapons the catalog knows');
    same(0, $builds[0]['stat_targets'][0]['from_rank'], 'a target from C0');
    check(!array_key_exists('source', $builds[0]) && !array_key_exists('variant', $builds[0]), 'no source, no variant');

    $kazuha = (new Client())->get('/api/builds/genshin/kaedeharakazuha')->status(200)->json('builds.0.weapons.0');
    same(['deathmatch', false], [$kazuha['key'], $kazuha['known']], 'a weapon the catalog does not know is kept, marked so');
});

test('the catalog refreshed list by list: written, kept, audited; one refresh of a list at a time; a provider that cannot be read', function () {
    T::$http->fake([
        'gi.yatta.moe/api/v2/en/avatar' => FakeHttpClient::json([
            'data' => [
                'items' => [
                    '10000046' => ['name' => 'Hu Tao', 'element' => 'Fire', 'rank' => 5, 'weaponType' => 'WEAPON_POLE'],
                    '10000999' => ['name' => 'The Newcomer', 'element' => 'Water', 'rank' => 4, 'weaponType' => 'WEAPON_BOW'],
                ],
            ],
        ]),
        'gi.yatta.moe/api/v2/en/reliquary' => FakeHttpClient::status(404),
    ]);

    $answer = editor()->post('/api/admin/catalog/genshin/characters')->status(200)->json();
    same(['written' => 2, 'list' => 'characters', 'entries' => 4], array_intersect_key($answer, array_flip(['written', 'list', 'entries'])), 'two written; the catalog has four characters now');
    check($answer['refreshed_at'] !== null, 'refreshed now');
    $lists = array_column(editor()->get('/api/admin/builds')->status(200)->json('0.catalog'), null, 'list');
    same([true, null], [$lists['characters']['refreshed_at'] !== null, $lists['weapons']['refreshed_at']], 'kept for each list');
    same(['list' => 'characters', 'entries' => 2], json_decode((string) db()->value("SELECT details FROM anotoki_audit_log WHERE action = 'catalog.refreshed'"), true), 'audited');

    // The newcomer's builds import now.
    same([], import(buildsFile([fileBuild(['character' => 'thenewcomer', 'character_name' => 'The Newcomer'])]))->status(200)->json('skipped'), 'a character the refresh brought');

    T::$app->get(Cache::class)->add('catalog-refresh:genshin:weapons', Clock::timestamp(), 120);
    same('This list is being refreshed. Try again in a moment.', editor()->post('/api/admin/catalog/genshin/weapons')->status(409)->json('message'), 'one refresh of a list at a time');

    same('The game data provider could not be read. Try again later.', editor()->post('/api/admin/catalog/genshin/sets')->status(502)->json('message'), 'a provider that cannot be read');
    editor()->post('/api/admin/catalog/genshin/sets')->status(502);
});

finishTests();
