<?php

/**
 * A game's builds brought in from a file and written out to one (App\Services\Builds\BuildImport, BuildFile,
 * api/routes/admin_builds.php): the file is the whole truth for its game, a build found again by its identity
 * keeps its id, characters the catalog lacks are skipped - once their list was refreshed -, a dry run writes
 * nothing, one import at a time, every text neutral, every import recorded, and the public builds routes that show
 * what was imported. The catalog's own refresh: catalog_refresh.test.php.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Auth\Role;
use App\Cache\Cache;
use App\Db\Db;
use App\Services\Builds\BuildFile;
use App\Services\Builds\BuildImport;
use App\Support\Clock;
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
    // A character or a weapon the catalog lacks has its list refreshed first: the provider is closed here, but where
    // a test says otherwise.
    T::$http->fake(['genshin.anotoki.eu/api/catalog/*' => FakeHttpClient::status(423)]);
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
        'teams' => [],
        'notes' => null,
        'game_version' => '5.4',
    ];
}

/**
 * Hu Tao's build with these teams - every field in the file's order, as the export writes it.
 *
 * @param  list<array<string, mixed>>  $teams
 * @return array<string, mixed>
 */
function withTeams(array $teams, array $build = []): array
{
    return array_replace(fileBuild($build), ['teams' => $teams]);
}

/**
 * A team member as the file holds it, its fields in the file's order.
 *
 * @param  list<array{key: string, name: string}>  $alternatives
 * @return array<string, mixed>
 */
function member(string $key, string $name, ?string $role = null, int $minRank = 0, array $alternatives = []): array
{
    return ['key' => $key, 'name' => $name, 'role' => $role, 'min_rank' => $minRank, 'alternatives' => $alternatives];
}

/**
 * A team of Hu Tao's: with Kazuha - Traveler (Anemo) can take his place - and Xingqiu at C6, whom the catalog here
 * does not know; or these members.
 *
 * @param  list<array<string, mixed>>|null  $members
 * @return array<string, mixed>
 */
function team(string $name = 'Vaporize', ?array $members = null, ?string $note = 'Swirl the Pyro onto the rain.'): array
{
    return [
        'name' => $name,
        'members' => $members ?? [
            member('hutao', 'Hu Tao', 'main_dps'),
            member('kaedeharakazuha', 'Kaedehara Kazuha', 'support', 0, [['key' => 'traveleranemo', 'name' => 'Traveler (Anemo)']]),
            member('xingqiu', 'Xingqiu', 'sub_dps', 6),
        ],
        'note' => $note,
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
    return ['format' => BuildFile::FORMAT, 'format_version' => BuildFile::VERSION, 'game' => 'genshin', 'exported_at' => $exported, 'builds' => $builds];
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

test('a build\'s teams: stored as the file has them and exported the same; teams gained, lost or changed update the build', function () {
    $melt = team('Melt', [member('hutao', 'Hu Tao', 'main_dps'), member('kaedeharakazuha', 'Kaedehara Kazuha', 'support')], null);
    $file = buildsFile([withTeams([team(), $melt]), kazuhaBuild()]);

    $first = import($file)->status(200)->json();
    same([2, 0, 0, 0], counts($first), 'two created');
    same(2, $first['teams'], 'the answer counts the teams');

    $exported = editor()->get('/api/admin/builds/genshin/export')->status(200)->json();
    same(3, $exported['format_version'], 'format version 3');
    same($file['builds'][0], $exported['builds'][0], 'Hu Tao\'s build with its teams as the file had them, in its order');
    same([], $exported['builds'][1]['teams'], 'Kazuha\'s without');
    same([0, 0, 2, 0], counts(import($exported)->status(200)->json()), 'the export imports as itself');

    $teams = fn() => db()->value("SELECT b.teams FROM builds b JOIN game_characters c ON c.id = b.game_character_id WHERE c.key = 'hutao'");
    same([0, 1, 1, 0], counts(import(buildsFile([fileBuild(), kazuhaBuild()]))->status(200)->json()), 'Hu Tao\'s build lost its teams: updated');
    same(null, $teams(), 'its teams NULL, not []');
    same([0, 1, 1, 0], counts(import(buildsFile([withTeams([team()]), kazuhaBuild()]))->status(200)->json()), 'it gained one: updated');
    same([0, 1, 1, 0], counts(import(buildsFile([withTeams([team('Vape')]), kazuhaBuild()]))->status(200)->json()), 'the team renamed: updated');
    same([0, 1, 1, 0], counts(import(buildsFile([withTeams([$melt, team('Vape')]), kazuhaBuild()]))->status(200)->json()), 'another team before it: updated - the order is the preference');
});

test('a build without teams keeps the content hash it had before teams came: the first import of a version 3 file changes only the builds that gained teams', function () {
    import(buildsFile([fileBuild(), kazuhaBuild(), withTeams([team()], ['role' => 'support'])]))->status(200);
    foreach (db()->all('SELECT weapons, sets, main_stats, substats, talent_priority, stat_targets, notes, game_version, teams, content_hash FROM builds ORDER BY id') as $row) {
        // 0.5's content: these fields, in this order, nothing of teams.
        $content = [];
        foreach (['weapons', 'sets', 'main_stats', 'substats', 'talent_priority', 'stat_targets'] as $field) {
            $content[$field] = Json::decode($row[$field]);
        }
        $content += ['notes' => $row['notes'], 'game_version' => $row['game_version']];
        if ($row['teams'] === null) {
            same(hash('sha256', Json::encode($content)), $row['content_hash'], 'a build without teams: the hash 0.5 gave it');
        } else {
            same(hash('sha256', Json::encode($content + ['teams' => Json::decode($row['teams'])])), $row['content_hash'], 'a build with teams: 0.5\'s content, then its teams');
        }
    }
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
        'format version 1' => [['format_version' => 1] + buildsFile([]), 'This builds file has format version 1 (the builds of one source). This installation reads version 3'],
        'format version 2' => [
            ['format_version' => 2] + buildsFile([]),
            'This builds file has format version 2 (builds without their teams). This installation reads version 3: make the file again, or export it again from this installation.',
        ],
        'a newer format' => [['format_version' => 4] + buildsFile([]), 'This builds file has format version 4, and this installation reads version 3. Update this installation, then import again.'],
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
        // The teams (format version 3).
        'teams as null' => [buildsFile([array_replace(fileBuild(), ['teams' => null])]), "The file's builds[0] (hutao, main_dps) is not valid: teams must be a list."],
        'a team without the build\'s own character' => [
            buildsFile([withTeams([team(members: [member('kaedeharakazuha', 'Kaedehara Kazuha'), member('xingqiu', 'Xingqiu')])])]),
            "teams[0].members must have the build's own character (hutao) among them.",
        ],
        'a team of one' => [buildsFile([withTeams([team(members: [member('hutao', 'Hu Tao')])])]), 'teams[0].members must have at least 2 entries.'],
        'a team of five' => [
            buildsFile([
                withTeams([
                    team(members: [member('hutao', 'Hu Tao'), member('kaedeharakazuha', 'Kaedehara Kazuha'), member('xingqiu', 'Xingqiu'), member('zhongli', 'Zhongli'), member('yelan', 'Yelan')]),
                ]),
            ]),
            'teams[0].members must not have more than 4 entries.',
        ],
        'a member twice' => [
            buildsFile([withTeams([team(members: [member('hutao', 'Hu Tao'), member('xingqiu', 'Xingqiu'), member('xingqiu', 'Xingqiu')])])]),
            'teams[0].members[2].key repeats an earlier entry of its list.',
        ],
        'a stand-in who is a member too' => [
            buildsFile([withTeams([team(members: [member('hutao', 'Hu Tao'), member('xingqiu', 'Xingqiu', null, 0, [['key' => 'hutao', 'name' => 'Hu Tao']])])])]),
            'teams[0].members[1].alternatives[0].key is a member of the same team.',
        ],
        'a stand-in for the build\'s own character' => [
            buildsFile([withTeams([team(members: [member('hutao', 'Hu Tao', null, 0, [['key' => 'yoimiya', 'name' => 'Yoimiya']]), member('xingqiu', 'Xingqiu')])])]),
            "teams[0].members[0].alternatives must be empty: the build's own character has no stand-in in its team.",
        ],
        'two teams of the same members' => [buildsFile([withTeams([team('Vaporize'), team('Vape', array_reverse(team()['members']))])]), 'teams[1].members are those of teams[0] too.'],
        'a team\'s name of two lines' => [buildsFile([withTeams([team("Vape\nMelt")])]), 'teams[0].name must be one line of text.'],
        'a team without a name' => [buildsFile([withTeams([team('  ')])]), 'teams[0].name must not be empty.'],
        'a team\'s name too long' => [buildsFile([withTeams([team(str_repeat('x', 61))])]), 'teams[0].name must not be longer than 60 characters.'],
        'a member\'s role the game does not have' => [
            buildsFile([withTeams([team(members: [member('hutao', 'Hu Tao'), member('xingqiu', 'Xingqiu', 'healer')])])]),
            'teams[0].members[1].role must be main_dps, sub_dps, support, or null.',
        ],
        'a member\'s constellation the game does not have' => [
            buildsFile([withTeams([team(members: [member('hutao', 'Hu Tao'), member('xingqiu', 'Xingqiu', null, 7)])])]),
            'teams[0].members[1].min_rank must be a whole number from 0 to 6.',
        ],
        'a member that is no key' => [
            buildsFile([withTeams([team(members: [member('hutao', 'Hu Tao'), member('Xing Qiu', 'Xingqiu')])])]),
            'teams[0].members[1].key must be a key of lowercase letters and digits',
        ],
        'a team field the format does not have' => [buildsFile([withTeams([team() + ['tier' => 'S']])]), 'teams[0].tier is not a field of this format.'],
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

test('every text is made neutral on the way in - a note, a qualifier, a weapon\'s, a team\'s - so no site is ever named', function () {
    Neutral::setLocalNames(['Fictional Builds']);
    $build = withTeams(
        [
            team(
                'Fictional Builds Vape',
                [member('hutao', 'Hu Tao'), member('xingqiu', 'Xingqiu of Fictional Builds', null, 0, [['key' => 'yelan', 'name' => 'Yelan (Fictional Builds)']])],
                'As Fictional Builds plays it: https://fictional.example/teams',
            ),
        ],
        [
            'qualifier' => 'Fictional Builds pick',
            'notes' => 'From Fictional Builds: see https://fictional.example/hu-tao for more.',
            'weapons' => [['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'refinement' => 1, 'note' => 'Fictional Builds likes it.']],
        ],
    );
    import(buildsFile([$build]))->status(200);

    $row = db()->one('SELECT qualifier, notes, weapons, teams FROM builds');
    same(['The source pick', 'From the source: see the source page for more.'], [$row['qualifier'], $row['notes']], 'stored neutral');
    check(str_contains($row['weapons'], 'The source likes it.'), 'a weapon\'s note too: ' . $row['weapons']);
    check(!str_contains(strtolower($row['teams']), 'fictional'), 'a team\'s name, note and members\' names too: ' . $row['teams']);
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
    // As the catalog's provider describes Hu Tao (migration 018's columns; tests/support/seed.php seeds the oldest schema too).
    db()->run("UPDATE game_characters SET released_at = '2021-03-02 00:00:00+00', region = 'Liyue', version = '1.3' WHERE key = 'hutao'");
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
    same(
        ['element' => 'pyro', 'rarity' => 5, 'weapon_type' => 'polearm', 'released_at' => '2021-03-02', 'region' => 'Liyue', 'version' => '1.3', 'version_name' => null],
        array_intersect_key($characters['hutao'], array_flip(['element', 'rarity', 'weapon_type', 'released_at', 'region', 'version', 'version_name'])),
        'what the builds browser filters by',
    );
    same(['support'], $characters['kaedeharakazuha']['roles'], 'Kazuha\'s');

    $builds = (new Client())->get('/api/builds/genshin/hutao')->status(200)->json('builds');
    same([['main_dps', '', 0, 0], ['support', 'Melt', 2, 1]], array_map(fn($b) => [$b['role'], $b['qualifier'], $b['min_rank'], $b['position']], $builds), 'in their order, each its variation');
    same([true, true], array_column($builds[0]['weapons'], 'known'), 'weapons the catalog knows');
    same(0, $builds[0]['stat_targets'][0]['from_rank'], 'a target from C0');
    check(!array_key_exists('source', $builds[0]) && !array_key_exists('variant', $builds[0]), 'no source, no variant');

    $kazuha = (new Client())->get('/api/builds/genshin/kaedeharakazuha')->status(200)->json('builds.0.weapons.0');
    same(['deathmatch', false], [$kazuha['key'], $kazuha['known']], 'a weapon the catalog does not know is kept, marked so');
});

test('a character or a weapon the catalog lacks: its list refreshed first - a dry run too, so it shows what the import does -, once per interval, and skipped only if still not there', function () {
    T::$http->fake([
        'genshin.anotoki.eu/api/catalog/characters' => FakeHttpClient::json([
            ['game_id' => 10000046, 'name' => 'Hu Tao', 'element' => 'Pyro', 'weapon_type' => 'Polearm', 'rarity' => 5, 'is_traveler' => false],
            ['game_id' => 10000999, 'name' => 'The Newcomer', 'element' => 'Hydro', 'weapon_type' => 'Bow', 'rarity' => 4, 'is_traveler' => false],
        ]),
        'genshin.anotoki.eu/api/catalog/weapons' => FakeHttpClient::json([['game_id' => 15599, 'name' => 'A Bow of Tomorrow', 'type' => 'Bow', 'rarity' => 5]]),
    ]);
    $newcomer = fileBuild([
        'character' => 'thenewcomer',
        'character_name' => 'The Newcomer',
        'weapons' => [['key' => 'abowoftomorrow', 'name' => 'A Bow of Tomorrow', 'refinement' => 1, 'note' => null]],
    ]);

    same([], import(buildsFile([fileBuild(), $newcomer]), dryRun: true)->status(200)->json('skipped'), 'the dry run: a character the refresh brought');
    same(['https://genshin.anotoki.eu/api/catalog/characters', 'https://genshin.anotoki.eu/api/catalog/weapons'], T::$http->sent, 'each list asked once');
    same([2, 0, 0, 0], counts(import(buildsFile([fileBuild(), $newcomer]))->status(200)->json()), 'the import: both');
    same(true, (new Client())->get('/api/builds/genshin/thenewcomer')->status(200)->json('builds.0.weapons.0.known'), 'its weapon known too');

    $someoneElse = fileBuild(['character' => 'someoneelse', 'character_name' => 'Someone Else']);
    same([['key' => 'someoneelse', 'name' => 'Someone Else', 'builds' => 1]], import(buildsFile([fileBuild(), $newcomer, $someoneElse]))->status(200)->json('skipped'), 'still not there: skipped');
    same(2, count(T::$http->sent), 'the list not asked again within the interval');
});

test('a teammate the catalog lacks never skips a build: the characters\' list is asked for first, once, and the answer names who is still missing', function () {
    $answer = import(buildsFile([withTeams([team()])]))->status(200)->json();
    same([1, 0, 0, 0], counts($answer), 'the build imported');
    same([], $answer['skipped'], 'nothing skipped');
    same([['key' => 'xingqiu', 'name' => 'Xingqiu']], $answer['teammates_unknown'], 'Xingqiu named - Kazuha and Traveler (Anemo) are in the catalog');
    same(1, $answer['teammates_unknown_total'], 'how many');
    same(['https://genshin.anotoki.eu/api/catalog/characters'], T::$http->sent, 'the characters\' list asked for, once');

    import(buildsFile([withTeams([team()])]))->status(200);
    same(1, count(T::$http->sent), 'not again within the interval');

    // A skipped build's own team says nothing of what is imported: its character is named among the skipped alone.
    $theirs = withTeams([team('Duo', [member('someoneelse', 'Someone Else'), member('yelan', 'Yelan')])], ['character' => 'someoneelse', 'character_name' => 'Someone Else']);
    $answer = import(buildsFile([withTeams([team()]), $theirs]), dryRun: true)->status(200)->json();
    same([['key' => 'someoneelse', 'name' => 'Someone Else', 'builds' => 1]], $answer['skipped'], 'the other character skipped');
    same(
        [['xingqiu'], 1, 1],
        [array_column($answer['teammates_unknown'], 'key'), $answer['teammates_unknown_total'], $answer['teams']],
        'the teammates and teams of the builds kept alone - neither it nor Yelan',
    );
});

test('the teammates the catalog lacks: the first 50 named, and how many there are', function () {
    // Twenty teams of Hu Tao's, each with three newcomers: 60 the catalog lacks.
    $teams = [];
    foreach (range(0, 19) as $t) {
        $teams[] = team("Team {$t}", [member('hutao', 'Hu Tao'), member("newcomer{$t}a", "Newcomer {$t} A"), member("newcomer{$t}b", "Newcomer {$t} B"), member("newcomer{$t}c", "Newcomer {$t} C")]);
    }
    $answer = import(buildsFile([withTeams($teams)]), dryRun: true)->status(200)->json();
    same([BuildImport::MAX_UNKNOWN_TEAMMATES, 60], [count($answer['teammates_unknown']), $answer['teammates_unknown_total']], 'the first 50 of the 60');
});

finishTests();
