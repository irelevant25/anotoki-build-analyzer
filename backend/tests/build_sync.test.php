<?php

/**
 * A build source read end to end, from fixture pages (a few lines imitating the site's structure, never
 * copied content): the stored builds, a second run that changes nothing, and the dry run.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Cli;
use App\Commands\SyncBuilds;
use App\Http\Fetcher;
use App\Models\SyncRun;
use App\Repositories\SyncRuns;
use App\Services\Builds\BuildSource;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Builds\Sources\GenshinBuildsGenshin;
use App\Services\Sync\SyncRunner;
use App\Support\Json;

bootTests();

function island(string $component, array $props): string
{
    return '<html><body><astro-island component-url="/_astro/' . $component . '.abc.js" props="' . htmlspecialchars(json_encode($props), ENT_QUOTES) . '"></astro-island></body></html>';
}

$pages = [
    'https://genshin-builds.com/en/characters' => island('CharactersList', [
        'characters' => [
            1,
            [
                [0, ['id' => [0, 'hu_tao'], 'name' => [0, 'Hu Tao'], 'element' => [0, ['id' => [0, 'pyro']]]]],
                [0, ['id' => [0, 'vesna'], 'name' => [0, 'Vesna'], 'upcoming' => [0, true], 'status' => [0, 'speculated']]],
            ],
        ],
    ]),
    'https://genshin-builds.com/en/character/hu_tao' => island('CharacterBuildTabs', [
        'builds' => [
            1,
            [
                [
                    0,
                    [
                        'name' => [0, 'DPS'],
                        'role' => [0, 'DPS'],
                        'recommended' => [0, true],
                        'weapons' => [1, [[0, ['id' => [0, 'staff_of_homa'], 'r' => [0, 0]]], [0, ['id' => [0, 'dragons_bane'], 'r' => [0, '5']]]]],
                        'sets' => [
                            1,
                            [
                                [1, [[0, 'crimson_witch_of_flames']]],
                                [1, [[0, 'crimson_witch_of_flames'], [0, '80elementalmastery_set']]],
                                // Not described in props.artifacts: resolved through the catalog's 2pc bonuses.
                                [1, [[0, 'crimson_witch_of_flames'], [0, '15_cryo_dmg_set']]],
                            ],
                        ],
                        'stats' => [0, ['sands' => [1, [[0, 'HP%'], [0, 'Elemental Mastery']]], 'goblet' => [1, [[0, 'Pyro DMG Bonus']]], 'circlet' => [1, [[0, 'CRIT Rate'], [0, 'CRIT DMG']]]]],
                        'stats_priority' => [1, [[0, 'CRIT Rate'], [0, 'CRIT DMG'], [0, 'Elemental Mastery'], [0, 'HP%']]],
                        'talent_priority' => [1, [[0, 'Normal Attack'], [0, 'Skill'], [0, 'Burst']]],
                        'build_notes' => [0, 'Aim for 60-70% <stat>CRIT Rate</stat>.'],
                    ],
                ],
            ],
        ],
        'weapons' => [
            0,
            [
                'staff_of_homa' => [0, ['name' => [0, 'Staff of Homa']]],
                'dragons_bane' => [0, ['name' => [0, "Dragon's Bane"]]],
            ],
        ],
        'artifacts' => [
            0,
            [
                'crimson_witch_of_flames' => [0, ['name' => [0, 'Crimson Witch of Flames']]],
                '80elementalmastery_set' => [0, ['name' => [0, 'Elemental Mastery +80 set'], 'children' => [1, [[0, ['name' => [0, 'Gilded Dreams']]], [0, ['name' => [0, "Wanderer's Troupe"]]]]]]],
            ],
        ],
    ]),
];

/** The HTTP layer, replaced with fixed pages (url => html). */
function servePages(array $pages): void
{
    // The build sources of the current scope hold the fetcher they were made with.
    T::$app->resetScope();
    T::$app->set(
        Fetcher::class,
        new class ($pages) extends Fetcher {
            public function __construct(private array $pages) {}

            public function get(string $url, array $options = []): string
            {
                return $this->pages[$url] ?? throw new RuntimeException("Unexpected URL {$url}");
            }
        },
    );
}

beforeEach(function () use ($pages) {
    seedGenshinCatalog();
    seedSet('genshin', 'Blizzard Strayer', 'artifact', ['meta' => ['bonuses' => ['Cryo DMG Bonus +15%', '...']]]);
    seedSet('genshin', 'Finale of the Deep Galleries', 'artifact', ['meta' => ['bonuses' => ['Cryo DMG Bonus +15%', '...']]]);
    servePages($pages);
    T::$app->config->set('sources', [GenshinBuildsGenshin::class]);
});

/** A whole sync of one source, stepped through as the Sources page does; null when it was skipped. */
function syncSource(BuildSource $source, array $options = []): ?SyncRun
{
    T::$app->resetScope();
    $runner = T::$app->get(SyncRunner::class);
    ['started' => $started] = $runner->startBuilds([$source], $options);
    if ($started === []) {
        return null;
    }
    $runner->runUntilDone([(int) $started[0]->id]);

    return T::$app->get(SyncRuns::class)->find((int) $started[0]->id);
}

$build = fn() => db()->one('SELECT * FROM builds LIMIT 1');

test('a sync stores normalized builds, and running it again changes nothing', function () use ($build) {
    $source = T::$app->get(BuildSourceRegistry::class)->get('genshin-builds.genshin');

    $run = syncSource($source, ['force' => true]);

    same('success', $run->status, 'status');
    same(1, $run->stats['characters_synced'], 'synced');
    same(1, $run->stats['characters_skipped'], 'Vesna is not released');

    $stored = $build();
    $weapons = Json::decode($stored['weapons']);
    $sets = Json::decode($stored['sets']);
    same('DPS', $stored['variant'], 'variant');
    same(['staffofhoma', 'dragonsbane'], array_column($weapons, 'key'), 'weapons');
    same(5, $weapons[1]['refinement'], 'refinement');
    same(4, $sets[0]['pieces'][0]['count'], '4pc');
    // "2pc CW + 2pc any +80 EM set"
    same(['gildeddreams', 'wandererstroupe'], $sets[1]['pieces'][1]['keys'], 'an EM set group');
    same(['blizzardstrayer', 'finaleofthedeepgalleries'], $sets[2]['pieces'][1]['keys'], 'a Cryo set group');
    same('Cryo DMG set', $sets[2]['pieces'][1]['label'], 'its label');
    same([['stat' => 'pyro_dmg_', 'priority' => 1]], Json::decode($stored['main_stats'])['goblet'], 'goblet');
    same(60.0, (float) Json::decode($stored['stat_targets'])[0]['min'], 'a target from the notes');

    // Nothing changed on the site: a second run creates nothing new.
    $second = syncSource($source, ['force' => true]);
    same(1, $second->stats['builds_unchanged'], 'unchanged');
    same(1, (int) db()->value('SELECT count(*) FROM builds'), 'one build');

    // A recent successful sync is skipped without "force".
    same(null, syncSource($source), 'skipped');
    same('success', db()->value("SELECT last_status FROM build_sources WHERE key = 'genshin-builds.genshin'"), 'source status');

    // The source's row carries its neutral name, never the site's.
    same('Source 1', db()->value("SELECT name FROM build_sources WHERE key = 'genshin-builds.genshin'"), 'stored name');
    $log = (string) db()->value('SELECT log FROM sync_runs ORDER BY id LIMIT 1');
    check(str_contains($log, 'Source 1 (Genshin Impact): 2 characters listed.'), $log);
});

test('a dry run compares with the stored builds and writes nothing', function () use ($pages) {
    syncSource(T::$app->get(BuildSourceRegistry::class)->get('genshin-builds.genshin'), ['force' => true]);
    $runs = (int) db()->value('SELECT count(*) FROM sync_runs');

    Cli::capture();
    $status = SyncBuilds::run(T::$app, ['sync-builds.php', 'genshin-builds.genshin', '--dry-run']);
    $output = Cli::captured();
    same(0, $status, $output);
    check(str_contains($output, '1 unchanged, 0 changed'), $output);

    // The site changes the goblet recommendation: the dry run reports it, the database keeps the old build.
    $url = 'https://genshin-builds.com/en/character/hu_tao';
    servePages([$url => str_replace('Pyro DMG Bonus', 'HP%', $pages[$url])] + $pages);

    Cli::capture();
    SyncBuilds::run(T::$app, ['sync-builds.php', 'genshin-1', '--dry-run', '--cache-hours=96']);
    $output = Cli::captured();
    check(str_contains($output, 'DPS=changed'), $output);
    same(96, T::$app->config->get('fetch.cache_hours'), 'the page cache of this run');

    same($runs, (int) db()->value('SELECT count(*) FROM sync_runs'), 'no run was made');
    same([['stat' => 'pyro_dmg_', 'priority' => 1]], Json::decode(db()->value('SELECT main_stats FROM builds'))['goblet'], 'the stored build is unchanged');
});

test('the content hash is the first version\'s, so its builds do not all look changed', function () {
    // A build as the Laravel version stored it (its hash was computed with Carbon dates), read by the dry
    // run of this version: unchanged.
    $source = (int) db()->insert('build_sources', ['key' => 'genshin-builds.genshin', 'game' => 'genshin', 'name' => 'x', 'base_url' => 'https://genshin-builds.com']);
    $data = [
        'role' => 'DPS',
        'recommended' => true,
        'position' => 0,
        'weapons' => [['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => null, 'note' => null, 'known' => true]],
        'sets' => [],
        'main_stats' => [],
        'substats' => [],
        'talent_priority' => null,
        'stat_targets' => null,
        'notes' => null,
        'source_url' => 'https://genshin-builds.com/en/character/hu_tao',
        'source_updated_at' => '2026-09-19T08:00:00.000000Z', // what Carbon's JSON looked like
        'game_version' => '6.0',
    ];
    $hash = hash('sha256', json_encode($data));

    $method = new ReflectionMethod(App\Services\Builds\BuildSync::class, 'contentHash');
    $mine = $method->invoke(T::$app->make(App\Services\Builds\BuildSync::class), array_replace($data, ['source_updated_at' => new DateTimeImmutable('2026-09-19 10:00:00+02:00')]));
    same($hash, $mine, 'the same hash for the same build');
    check($source > 0, 'source');
});

finishTests();
