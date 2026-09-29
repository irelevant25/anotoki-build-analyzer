<?php

/**
 * A source's builds moved through a file: the export names no site and comes out the same for the same
 * builds; an import makes the source's builds exactly the file's - written as a sync writes them, recorded
 * as a run of the source, the source left synced - and a file that does not fit the source is refused with
 * nothing written.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Auth\Role;
use App\Cache\Locks;
use App\Db\Db;
use App\Services\Builds\BuildFile;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Builds\BuildSync;
use App\Services\Sync\SyncRunner;
use App\Support\Clock;
use App\Support\Neutral;
use Tests\Support\Client;
use Tests\Support\FakeBuildSource;

bootTests();

beforeEach(function () {
    seedGenshinCatalog();
    // The one source: Source 1 of Genshin Impact, "genshin-1".
    T::$app->config->set('sources', [FakeBuildSource::class]);
});

$admin = fn () => (new Client)->asIam(1, [Role::ADMIN], ['preferred_username' => 'admin']);

/**
 * A build with every field filled, the way a sync stores one; $fields replace some.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function aBuild(string $variant, array $fields = []): array
{
    return $fields + [
        'variant' => $variant,
        'role' => 'DPS',
        'recommended' => true,
        'weapons' => [
            ['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => 1, 'note' => 'Best in slot.', 'known' => true],
            ['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'rank' => 2, 'refinement' => null, 'note' => null, 'known' => true],
        ],
        'sets' => [
            ['rank' => 1, 'kind' => 'artifact', 'choose' => null, 'pieces' => [
                ['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']],
            ], 'note' => null],
            ['rank' => 2, 'kind' => 'artifact', 'choose' => 2, 'pieces' => [
                ['label' => 'Crimson Witch of Flames', 'count' => 2, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']],
                ['label' => 'Elemental Mastery +80 set', 'count' => 2, 'keys' => ['gildeddreams', 'wandererstroupe'], 'names' => ['Gilded Dreams', "Wanderer's Troupe"]],
                ['label' => "Shimenawa's Reminiscence", 'count' => 2, 'keys' => ['shimenawasreminiscence'], 'names' => ["Shimenawa's Reminiscence"]],
            ], 'note' => 'Any two of these.'],
        ],
        'main_stats' => [
            'sands' => [['stat' => 'hp_', 'priority' => 1], ['stat' => 'eleMas', 'priority' => 1]],
            'goblet' => [['stat' => 'pyro_dmg_', 'priority' => 1]],
            'circlet' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 2]],
        ],
        'substats' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1], ['stat' => 'hp_', 'priority' => 2]],
        'talent_priority' => [['talent' => 'normal', 'priority' => 1], ['talent' => 'skill', 'priority' => 2]],
        'stat_targets' => [['stat' => 'critRate_', 'min' => 60, 'max' => 70.5, 'text' => 'CRIT Rate 60-70.5%']],
        'notes' => "Keep the blood blossom up.\nNot only ASCII: ★ é",
        'source_url' => null,
        'source_updated_at' => new DateTimeImmutable('2026-09-19T08:00:00.123456Z'),
        'game_version' => '6.0',
        'fetched_at' => Clock::now()->modify('-2 days'),
        'changed_at' => Clock::now()->modify('-5 days'),
    ];
}

/**
 * Stores a character's builds of Source 1 as a sync does (BuildSync::write()), positioned in this order.
 *
 * @param  list<array<string, mixed>>  $builds  from aBuild()
 */
function storeBuilds(string $character, array $builds): void
{
    $registry = T::$app->get(BuildSourceRegistry::class);
    $rows = [];
    foreach ($builds as $position => $build) {
        $rows[] = ['variant' => $build['variant'], 'data' => contentOf($build, $position), 'fetched_at' => $build['fetched_at'], 'changed_at' => $build['changed_at']];
    }
    T::$app->get(BuildSync::class)->write($registry->model($registry->get('fake.genshin')), idOf('game_characters', $character), $rows);
}

/** @param array<string, mixed> $build from aBuild() */
function contentOf(array $build, int $position): array
{
    return BuildSync::content(
        role: $build['role'], recommended: $build['recommended'], position: $position, weapons: $build['weapons'], sets: $build['sets'],
        mainStats: $build['main_stats'], substats: $build['substats'], talentPriority: $build['talent_priority'],
        statTargets: $build['stat_targets'], notes: $build['notes'], sourceUrl: $build['source_url'],
        sourceUpdatedAt: $build['source_updated_at'], gameVersion: $build['game_version'],
    );
}

/** Three builds of two characters, the source synced three hours ago. */
function seedSource(): void
{
    storeBuilds('kaedeharakazuha', [aBuild('Main', [
        'role' => null, 'main_stats' => [], 'substats' => [], 'talent_priority' => [], 'stat_targets' => [], 'notes' => null,
        'source_updated_at' => null, 'game_version' => null,
    ])]);
    storeBuilds('hutao', [aBuild('Support', ['recommended' => false]), aBuild('DPS')]);
    db()->run("UPDATE build_sources SET last_synced_at = ?, last_status = 'success' WHERE key = 'fake.genshin'", [Clock::now()->modify('-3 hours')]);
}

/** Every stored build, but for ids and bookkeeping times: what an import has to bring back. */
function storedBuilds(): array
{
    return db()->all('SELECT c.key AS character, b.variant, b.role, b.recommended, b.position, b.weapons::text AS weapons,
            b.sets::text AS sets, b.main_stats::text AS main_stats, b.substats::text AS substats,
            b.talent_priority::text AS talent_priority, b.stat_targets::text AS stat_targets, b.notes, b.source_url,
            b.source_updated_at, b.game_version, b.content_hash, b.fetched_at, b.changed_at
        FROM builds b JOIN game_characters c ON c.id = b.game_character_id ORDER BY c.key, b.variant');
}

function runCount(): int
{
    return (int) db()->value('SELECT count(*) FROM sync_runs');
}

/** Every string in a decoded answer, keys included. */
function stringsIn(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }
    $strings = [];
    foreach (is_array($value) ? $value : [] as $key => $item) {
        $strings[] = (string) $key;
        array_push($strings, ...stringsIn($item));
    }

    return $strings;
}

test('a source without builds exports an empty file', function () use ($admin) {
    $admin()->get('/api/sources/genshin-1/export')->status(200)
        ->path('source', 'genshin-1')
        ->path('synced_at', null)
        ->path('builds', []);
});

test('an export holds every build of the source in a stable order, and names no site', function () use ($admin) {
    seedSource();
    // A build copied from a page that names its site, kept with the page's address.
    storeBuilds('traveleranemo', [aBuild('Prydwen pick', [
        'role' => 'Prydwen DPS',
        'weapons' => [['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => null, 'note' => 'Best in slot per genshin-builds.com', 'known' => true]],
        'sets' => [['rank' => 1, 'kind' => 'artifact', 'choose' => null, 'note' => 'See https://keqingmains.com/traveler', 'pieces' => [
            ['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']],
        ]]],
        'stat_targets' => [['stat' => 'critRate_', 'min' => 60, 'max' => null, 'text' => 'CRIT Rate 60% (Prydwen)']],
        'notes' => 'Copied from https://www.prydwen.gg/genshin-impact/characters/traveler - thanks Prydwen!',
        'source_url' => 'https://www.prydwen.gg/genshin-impact/characters/traveler',
    ])]);

    $answer = $admin()->get('/api/sources/genshin-1/export')->status(200);
    same('attachment; filename="builds-genshin-1-'.Clock::now()->format('Y-m-d').'.json"', $answer->header('Content-Disposition'), 'the file name');
    check(str_starts_with($answer->header('Content-Type'), 'application/json'), $answer->header('Content-Type'));

    $file = $answer->json();
    same(['format', 'format_version', 'source', 'game', 'exported_at', 'synced_at', 'builds'], array_keys($file), 'the fields of the file');
    same(['build-analyzer.builds', 1, 'genshin-1', 'genshin'], [$file['format'], $file['format_version'], $file['source'], $file['game']], 'what the file is');
    same(BuildFile::time(Db::time(db()->value('SELECT last_synced_at FROM build_sources'))), $file['synced_at'], 'when the builds were synced');
    same(['character', 'character_name', 'variant', 'role', 'recommended', 'position', 'weapons', 'sets', 'main_stats', 'substats',
        'talent_priority', 'stat_targets', 'notes', 'source_updated_at', 'game_version', 'fetched_at', 'changed_at'], array_keys($file['builds'][0]), 'the fields of a build');

    // By character key, then position (Support was listed before DPS), then variant.
    same(['hutao/Support', 'hutao/DPS', 'kaedeharakazuha/Main', 'traveleranemo/The source pick'],
        array_map(fn (array $build) => $build['character'].'/'.$build['variant'], $file['builds']), 'the order');
    same('Hu Tao', $file['builds'][0]['character_name'], 'the name, for people reading the file');
    same('2026-09-19T08:00:00.123456Z', $file['builds'][0]['source_updated_at'], 'times to the microsecond');
    same([], $file['builds'][2]['main_stats'], 'no main stats: an object, {} in the file');
    check(str_contains($answer->body, '"main_stats": {}'), 'main_stats is written as an object');

    // No site in any value or field name, and no page address.
    foreach (stringsIn($file) as $text) {
        check(! Neutral::mentionsAny($text) && ! preg_match('~https?://|www\.~i', $text), "the export names a site: {$text}");
    }
    foreach (['source_url', 'base_url', 'fake-builds'] as $word) {
        check(! str_contains($answer->body, $word), "the export holds {$word}");
    }

    // The same builds a minute later: the same file, but for when it was exported.
    Clock::travel(60);
    $again = $admin()->get('/api/sources/genshin-1/export')->json();
    check($again['exported_at'] !== $file['exported_at'], 'exported again');
    unset($file['exported_at'], $again['exported_at']);
    same($file, $again, 'the same file');
});

test('an import brings the exported builds back exactly, and removes what the file does not have', function () use ($admin) {
    seedSource();
    $client = $admin();
    $file = $client->get('/api/sources/genshin-1/export')->json();
    $exported = storedBuilds();
    same(3, count($exported), 'three builds exported');

    // Since the export: a build changed (as an older version of it), one deleted, a variant and a character added.
    db()->run("UPDATE builds SET notes = 'an older text', content_hash = 'older' WHERE variant = 'DPS'");
    db()->run("DELETE FROM builds WHERE variant = 'Main'");
    storeBuilds('traveleranemo', [aBuild('Anemo')]);
    db()->insert('builds', ['build_source_id' => (int) db()->value('SELECT id FROM build_sources'), 'game_character_id' => idOf('game_characters', 'hutao'),
        'variant' => 'Vape', 'weapons' => '[]', 'sets' => '[]', 'main_stats' => '{}', 'substats' => '[]', 'content_hash' => 'x']);

    $client->post('/api/sources/genshin-1/import', $file)->status(200)
        ->path('created', 1)
        ->path('updated', 1)
        ->path('unchanged', 1)
        ->path('removed', 2)
        ->path('characters', 2)
        ->path('skipped', []);

    same($exported, storedBuilds(), 'the builds as they were exported');
});

test('an import is a run of the source, and leaves the source synced when the file\'s builds were', function () use ($admin) {
    $client = $admin();
    // Builds from a real sync, whose run shows the stats a sync keeps.
    $client->post('/api/sources/genshin-1/sync')->status(200);
    $sync = $client->post('/api/sync/step')->path('did.status', 'success')->json('did');
    $file = $client->get('/api/sources/genshin-1/export')->json();

    // The file's builds were synced three hours ago; here the source never synced them.
    $file['synced_at'] = BuildFile::time(Clock::now()->modify('-3 hours'));
    db()->run("UPDATE build_sources SET last_synced_at = NULL, last_status = 'failed', last_error = 'HTTP 403'");

    $answer = $client->post('/api/sources/genshin-1/import', $file)->status(200)
        ->path('created', 0)
        ->path('updated', 0)
        ->path('unchanged', 2)
        ->path('removed', 0)
        ->path('source.key', 'genshin-1')
        ->path('source.name', 'Source 1')
        ->path('source.last_status', 'success')
        ->path('source.last_error', null)
        ->path('source.builds', 2)
        ->path('source.characters', 2)
        ->path('source.running', false)
        ->path('source.latest_run.started_by.name', 'admin');

    $run = db()->one('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1');
    same((int) $run['id'], $answer->json('source.latest_run.id'), 'the latest run is the import');
    same(['builds', 'genshin', 'success', 1], [$run['kind'], $run['game'], $run['status'], (int) $run['started_by']], 'a successful builds run, the admin\'s');
    same(null, $run['state'], 'finished');
    $stats = json_decode($run['stats'], true);
    $keys = array_keys($stats);
    $syncKeys = array_keys($sync['stats']);
    sort($keys);
    sort($syncKeys);
    same($syncKeys, $keys, 'the stats a sync keeps');
    same([2, 2, 0, false], [$stats['characters_listed'], $stats['characters_synced'], $stats['characters_skipped'], $stats['partial_run']], 'characters');

    $log = explode("\n", $run['log']);
    same('Started by admin.', $log[0], 'the first line');
    check(str_starts_with($log[1], 'Source 1 (Genshin Impact): imported from a file exported '), $run['log']);
    same('Done: success. 0 created, 0 updated, 2 unchanged, 0 removed, 0 errors.', end($log), 'the last line');

    // Synced when the file says, so "Sync all" leaves the source alone as it would after a sync.
    same($file['synced_at'], BuildFile::time(Db::time(db()->value('SELECT last_synced_at FROM build_sources'))), 'last synced');
    $client->post('/api/sync/builds')->status(200)->path('started', [])->path('skipped.0.reason', 'synced 3 hours ago');
});

test('the file is the whole truth: the builds of characters it lacks go, and an empty file empties the source', function () use ($admin) {
    seedSource();
    $client = $admin();
    $file = $client->get('/api/sources/genshin-1/export')->json();

    $huTaoOnly = ['builds' => array_values(array_filter($file['builds'], fn (array $build) => $build['character'] === 'hutao'))] + $file;
    $client->post('/api/sources/genshin-1/import', $huTaoOnly)->status(200)
        ->path('removed', 1)->path('unchanged', 2)->path('characters', 1);
    same(['hutao', 'hutao'], db()->column('SELECT c.key FROM builds b JOIN game_characters c ON c.id = b.game_character_id'), 'Hu Tao\'s builds only');
    $log = (string) db()->value('SELECT log FROM sync_runs ORDER BY id DESC LIMIT 1');
    check(str_contains($log, '  Kaedehara Kazuha: not in the file, 1 build(s) removed.'), $log);

    // Nothing synced on the other side: the source counts as synced when the file was made.
    $client->post('/api/sources/genshin-1/import', ['builds' => [], 'synced_at' => null] + $file)->status(200)
        ->path('removed', 2)->path('characters', 0)->path('source.builds', 0);
    same(0, (int) db()->value('SELECT count(*) FROM builds'), 'no builds');
    same($file['exported_at'], BuildFile::time(Db::time(db()->value('SELECT last_synced_at FROM build_sources'))), 'last synced');
});

test('characters this catalog lacks are skipped and named, and a file of nothing else is refused', function () use ($admin) {
    seedSource();
    $client = $admin();
    $file = $client->get('/api/sources/genshin-1/export')->json();
    $newcomer = ['character' => 'someonenew', 'character_name' => 'Someone New'] + $file['builds'][0];

    $client->post('/api/sources/genshin-1/import', ['builds' => [...$file['builds'], $newcomer]] + $file)->status(200)
        ->path('skipped', [['key' => 'someonenew', 'name' => 'Someone New']])
        ->path('characters', 2)
        ->path('unchanged', 3)
        ->path('source.last_status', 'success');

    $run = db()->one('SELECT stats, log FROM sync_runs ORDER BY id DESC LIMIT 1');
    $stats = json_decode($run['stats'], true);
    same(1, $stats['characters_skipped'], 'skipped');
    same(['genshin:characters' => ['Someone New']], $stats['unmatched'], 'unmatched, as a sync names them');
    check(str_contains($run['log'], '  ! Someone New (someonenew): not in the catalog, 1 build(s) skipped. Refresh the catalog, then import again.'), $run['log']);
    same(0, (int) db()->value("SELECT count(*) FROM game_characters WHERE key = 'someonenew'"), 'an import adds nothing to the catalog');

    // Only characters the catalog lacks: a catalog nobody refreshed yet, not a source without builds.
    $builds = storedBuilds();
    $runs = runCount();
    $client->post('/api/sources/genshin-1/import', ['builds' => [$newcomer]] + $file)->status(422)
        ->path('message', "None of the file's characters is in this installation's catalog. Refresh the catalog, then import again.");
    same($builds, storedBuilds(), 'nothing removed');
    same($runs, runCount(), 'no run');
});

test('a file for another source or game, or no builds file at all, is refused and changes nothing', function () use ($admin) {
    seedSource();
    $client = $admin();
    $file = $client->get('/api/sources/genshin-1/export')->json();
    $builds = storedBuilds();
    $source = db()->one('SELECT last_synced_at, last_status, last_error FROM build_sources');

    $refused = [
        'This file holds the builds of genshin-2, not of Source 1 (genshin-1).' => ['source' => 'genshin-2'] + $file,
        // Whatever text the file holds there, the answer does not repeat it.
        'This file holds the builds of another source, not of Source 1 (genshin-1).' => ['source' => 'prydwen.genshin'] + $file,
        'This file holds Honkai: Star Rail builds, and Source 1 is a Genshin Impact source.' => ['game' => 'hsr', 'source' => 'hsr-1'] + $file,
        'This builds file names no game this installation knows.' => ['game' => 'zzz'] + $file,
        'This is not a builds file exported by the build analyzer.' => ['format' => 'GOOD'] + $file,
        'This builds file has format version 2, and this installation reads version 1. Update it, then import again.' => ['format_version' => 2] + $file,
        'This builds file has no format version this installation reads.' => ['format_version' => '1'] + $file,
    ];
    foreach ($refused as $message => $body) {
        $client->post('/api/sources/genshin-1/import', $body)->status(422)->path('message', $message);
    }

    $raw = fn (string $body) => $client->request('POST', '/api/sources/genshin-1/import', null, ['body' => $body]);
    $raw('{"format": "build-analyzer.builds"')->status(422)->path('message', 'This is not a builds file exported by the build analyzer.');
    $raw('[1, 2]')->status(422)->path('message', 'This is not a builds file exported by the build analyzer.');
    $raw('')->status(422)->path('message', "No file arrived. A file larger than this server accepts (PHP's post_max_size) arrives empty.");
    $raw(str_repeat(' ', BuildFile::MAX_BYTES + 1))->status(422)->path('message', 'The file is larger than 16 MB, far more than the builds of a source take.');

    same($builds, storedBuilds(), 'the builds are as they were');
    same(0, runCount(), 'no run');
    same($source, db()->one('SELECT last_synced_at, last_status, last_error FROM build_sources'), 'the source is as it was');
});

test('a malformed build is refused with the whole file, naming the first problem', function () use ($admin) {
    seedSource();
    $client = $admin();
    $file = $client->get('/api/sources/genshin-1/export')->json();
    $builds = storedBuilds();
    $in = 'The file\'s builds[0] (hutao, "Support") is not valid: ';

    $cases = [
        "The file's builds must be a list." => function (array &$f) { $f['builds'] = ['one' => $f['builds'][0]]; },
        "The file's builds must not have more than 5000 entries." => function (array &$f) { $f['builds'] = array_fill(0, 5001, 0); },
        "The file's builds[0] must be an object." => function (array &$f) { $f['builds'][0] = 'a build'; },
        "The file's synced_at must be a time in ISO 8601, such as 2026-09-29T14:03:12Z, or null." => function (array &$f) { $f['synced_at'] = 'yesterday'; },
        "The file's exported_at must be a time in ISO 8601, such as 2026-09-29T14:03:12Z." => function (array &$f) { $f['exported_at'] = '2026-02-30T10:00:00Z'; },
        "The file's teams is not a field of this format." => function (array &$f) { $f['teams'] = []; },
        "The file's builds[0] is not valid: character must be a key of lowercase letters and digits, at most 120 long." => function (array &$f) { $f['builds'][0]['character'] = 'Hu Tao'; },
        "The file's builds[0] is not valid: variant must not be longer than 120 characters." => function (array &$f) { $f['builds'][0]['variant'] = str_repeat('x', 121); },
        $in.'notes is missing.' => function (array &$f) { unset($f['builds'][0]['notes']); },
        $in.'source_url is not a field of this format.' => function (array &$f) { $f['builds'][0]['source_url'] = 'https://example.org/'; },
        // A field named like a site is not repeated either.
        $in.'the source is not a field of this format.' => function (array &$f) { $f['builds'][0]['prydwen'] = true; },
        $in.'recommended must be true or false.' => function (array &$f) { $f['builds'][0]['recommended'] = 1; },
        $in.'position must be a whole number from 0 to 32767.' => function (array &$f) { $f['builds'][0]['position'] = -1; },
        $in.'weapons must be a list.' => function (array &$f) { $f['builds'][0]['weapons'] = ['key' => 'staffofhoma']; },
        $in.'weapons[1].refinement must be a whole number from 0 to 99, or null.' => function (array &$f) { $f['builds'][0]['weapons'][1]['refinement'] = '5'; },
        $in.'sets[0].kind must be artifact.' => function (array &$f) { $f['builds'][0]['sets'][0]['kind'] = 'relic'; },
        $in.'sets[1].choose must be a whole number from 1 to 3, or null.' => function (array &$f) { $f['builds'][0]['sets'][1]['choose'] = 4; },
        $in.'sets[0].pieces must not be empty.' => function (array &$f) { $f['builds'][0]['sets'][0]['pieces'] = []; },
        $in.'sets[0].pieces must not have more than 8 entries.' => function (array &$f) { $f['builds'][0]['sets'][0]['pieces'] = array_fill(0, 9, $f['builds'][0]['sets'][0]['pieces'][0]); },
        $in.'sets[1].pieces[1].keys[0] must be a key of lowercase letters and digits, at most 500 long.' => function (array &$f) { $f['builds'][0]['sets'][1]['pieces'][1]['keys'][0] = 'Gilded Dreams'; },
        $in.'main_stats must be an object.' => function (array &$f) { $f['builds'][0]['main_stats'] = [[['stat' => 'hp_', 'priority' => 1]]]; },
        $in.'main_stats.body is not a slot of Genshin Impact.' => function (array &$f) { $f['builds'][0]['main_stats'] = ['body' => [['stat' => 'spd', 'priority' => 1]]]; },
        $in.'substats[0].priority must be a whole number from 1 to 99.' => function (array &$f) { $f['builds'][0]['substats'][0]['priority'] = 1.5; },
        $in.'talent_priority[0].talent must be normal, skill or burst.' => function (array &$f) { $f['builds'][0]['talent_priority'][0]['talent'] = 'ultimate'; },
        $in.'stat_targets[0].min must be a number from -1000000 to 1000000, or null.' => function (array &$f) { $f['builds'][0]['stat_targets'][0]['min'] = 5_000_000; },
        $in.'source_updated_at must be a time in ISO 8601, such as 2026-09-29T14:03:12Z, or null.' => function (array &$f) { $f['builds'][0]['source_updated_at'] = 'last week'; },
        'The file\'s builds[1] (hutao, "Support") is not valid: its character and variant are those of builds[0] too.' => function (array &$f) { $f['builds'][1] = $f['builds'][0]; },
        // The first problem may be in a later build: it is named.
        'The file\'s builds[2] (kaedeharakazuha, "Main") is not valid: weapons[0].known must be true or false.' => function (array &$f) { $f['builds'][2]['weapons'][0]['known'] = 'yes'; },
    ];
    foreach ($cases as $message => $break) {
        $broken = $file;
        $break($broken);
        $client->post('/api/sources/genshin-1/import', $broken)->status(422)->path('message', $message);
    }

    // A number too large for a float arrives as INF.
    $json = preg_replace('/"min":60\b/', '"min":1E400', (string) json_encode($file), 1);
    $client->request('POST', '/api/sources/genshin-1/import', null, ['body' => $json])->status(422)
        ->path('message', $in.'stat_targets[0].min must be a number from -1000000 to 1000000, or null.');

    same($builds, storedBuilds(), 'nothing written');
    same(0, runCount(), 'no run');
});

test('no import while the source syncs, or while a step runs', function () use ($admin) {
    seedSource();
    $client = $admin();
    $file = $client->get('/api/sources/genshin-1/export')->json();
    $builds = storedBuilds();

    // A sync of the source is going (started and not stepped yet, as when its tab was closed).
    $runId = $client->post('/api/sources/genshin-1/sync')->status(200)->json('started.0.id');
    $client->post('/api/sources/genshin-1/import', $file)->status(409)
        ->path('message', 'Source 1 is being synced. Let that sync finish or cancel it, then import.');
    $client->post("/api/sync-runs/{$runId}/cancel")->status(200);

    // A step holds the lock: the import waits a moment for it, then gives up.
    $lock = T::$app->get(Locks::class)->lock(SyncRunner::STEP_LOCK, 60);
    check($lock->get(), 'took the lock');
    try {
        $client->post('/api/sources/genshin-1/import', $file)->status(409)->path('message', 'A sync step is still running. Try again in a moment.');
    } finally {
        $lock->release();
    }

    same($builds, storedBuilds(), 'nothing written');
    same(1, runCount(), 'only the cancelled sync');
    $client->post('/api/sources/genshin-1/import', $file)->status(200)->path('unchanged', 3);
});

test('only an admin exports or imports', function () use ($admin) {
    seedSource();
    $file = $admin()->get('/api/sources/genshin-1/export')->json();
    $member = (new Client)->asIam(3, [Role::MEMBER]);

    $member->get('/api/sources/genshin-1/export')->status(403)->path('message', 'Only an admin can do this.');
    $member->post('/api/sources/genshin-1/import', ['builds' => []] + $file)->status(403)->path('message', 'Only an admin can do this.');
    (new Client)->get('/api/sources/genshin-1/export')->status(401);

    same(3, (int) db()->value('SELECT count(*) FROM builds'), 'nothing removed');
    same(0, runCount(), 'no run');
});

test('the page address stays behind: an imported build hashes without it, and so does the next import', function () use ($admin) {
    $build = aBuild('DPS', ['source_url' => 'https://fake-builds.example/hu-tao']);
    storeBuilds('hutao', [$build]);
    $synced = db()->one('SELECT content_hash, source_url FROM builds');
    $client = $admin();
    $file = $client->get('/api/sources/genshin-1/export')->json();

    $client->post('/api/sources/genshin-1/import', $file)->status(200)->path('updated', 1);
    $imported = db()->one('SELECT content_hash, source_url FROM builds');
    same(null, $imported['source_url'], 'no address');
    check($imported['content_hash'] !== $synced['content_hash'], 'hashed without it');

    // The hash of that content without an address, as a sync computes it.
    $hash = new ReflectionMethod(BuildSync::class, 'contentHash');
    same($hash->invoke(T::$app->make(BuildSync::class), contentOf(['source_url' => null] + $build, 0)), $imported['content_hash'], 'the hash');

    $client->post('/api/sources/genshin-1/import', $file)->status(200)->path('unchanged', 1)->path('updated', 0);
});

test('a build\'s times come from the file when they are times, else they are now', function () use ($admin) {
    storeBuilds('hutao', [aBuild('DPS')]);
    $client = $admin();
    $file = $client->get('/api/sources/genshin-1/export')->json();
    $entry = $file['builds'][0];
    db()->run('DELETE FROM builds');

    $file['builds'] = [
        ['variant' => 'Not times', 'fetched_at' => 'last week', 'changed_at' => null] + $entry,
        // A clock ahead of this one, and a time left out.
        ['variant' => 'Future', 'fetched_at' => BuildFile::time(Clock::now()->modify('+1 day'))] + array_diff_key($entry, ['changed_at' => true]),
        ['variant' => 'Times'] + $entry,
    ];
    $client->post('/api/sources/genshin-1/import', $file)->status(200)->path('created', 3);

    $times = [];
    foreach (db()->all('SELECT variant, fetched_at, changed_at FROM builds') as $row) {
        $times[$row['variant']] = [Db::time($row['fetched_at']), Db::time($row['changed_at'])];
    }
    foreach (['Not times', 'Future'] as $variant) {
        near(Clock::timestamp(), $times[$variant][0]->getTimestamp(), 5, "{$variant}: fetched now");
        near(Clock::timestamp(), $times[$variant][1]->getTimestamp(), 5, "{$variant}: changed now");
    }
    same([$entry['fetched_at'], $entry['changed_at']], array_map(BuildFile::time(...), $times['Times']), 'the file\'s times');
});

finishTests();
