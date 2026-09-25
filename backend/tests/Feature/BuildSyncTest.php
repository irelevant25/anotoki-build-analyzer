<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\BuildSource;
use App\Models\SyncRun;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Builds\BuildSync;
use App\Services\Builds\Sources\GenshinBuildsGenshin;
use App\Services\Http\Fetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class BuildSyncTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalog;

    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGenshinCatalog();
        $this->set('genshin', 'Blizzard Strayer', 'artifact', ['meta' => ['bonuses' => ['Cryo DMG Bonus +15%', '...']]]);
        $this->set('genshin', 'Finale of the Deep Galleries', 'artifact', ['meta' => ['bonuses' => ['Cryo DMG Bonus +15%', '...']]]);

        $this->pages = [
            'https://genshin-builds.com/en/characters' => $this->island('CharactersList', [
                'characters' => [1, [
                    [0, ['id' => [0, 'hu_tao'], 'name' => [0, 'Hu Tao'], 'element' => [0, ['id' => [0, 'pyro']]]]],
                    [0, ['id' => [0, 'vesna'], 'name' => [0, 'Vesna'], 'upcoming' => [0, true], 'status' => [0, 'speculated']]],
                ]],
            ]),
            'https://genshin-builds.com/en/character/hu_tao' => $this->island('CharacterBuildTabs', [
                'builds' => [1, [[0, [
                    'name' => [0, 'DPS'],
                    'role' => [0, 'DPS'],
                    'recommended' => [0, true],
                    'weapons' => [1, [[0, ['id' => [0, 'staff_of_homa'], 'r' => [0, 0]]], [0, ['id' => [0, 'dragons_bane'], 'r' => [0, '5']]]]],
                    'sets' => [1, [
                        [1, [[0, 'crimson_witch_of_flames']]],
                        [1, [[0, 'crimson_witch_of_flames'], [0, '80elementalmastery_set']]],
                        // Not described in props.artifacts: resolved through the catalog's 2pc bonuses.
                        [1, [[0, 'crimson_witch_of_flames'], [0, '15_cryo_dmg_set']]],
                    ]],
                    'stats' => [0, ['sands' => [1, [[0, 'HP%'], [0, 'Elemental Mastery']]], 'goblet' => [1, [[0, 'Pyro DMG Bonus']]], 'circlet' => [1, [[0, 'CRIT Rate'], [0, 'CRIT DMG']]]]],
                    'stats_priority' => [1, [[0, 'CRIT Rate'], [0, 'CRIT DMG'], [0, 'Elemental Mastery'], [0, 'HP%']]],
                    'talent_priority' => [1, [[0, 'Normal Attack'], [0, 'Skill'], [0, 'Burst']]],
                    'build_notes' => [0, 'Aim for 60-70% <stat>CRIT Rate</stat>.'],
                ]]]],
                'weapons' => [0, [
                    'staff_of_homa' => [0, ['name' => [0, 'Staff of Homa']]],
                    'dragons_bane' => [0, ['name' => [0, "Dragon's Bane"]]],
                ]],
                'artifacts' => [0, [
                    'crimson_witch_of_flames' => [0, ['name' => [0, 'Crimson Witch of Flames']]],
                    '80elementalmastery_set' => [0, ['name' => [0, 'Elemental Mastery +80 set'], 'children' => [1, [
                        [0, ['name' => [0, 'Gilded Dreams']]], [0, ['name' => [0, "Wanderer's Troupe"]]],
                    ]]]],
                ]],
            ]),
        ];

        $this->servePages($this->pages);
        config(['hoyo.sources' => [GenshinBuildsGenshin::class]]);
    }

    /**
     * Replace the HTTP layer with fixed pages (url => html).
     */
    private function servePages(array $pages): void
    {
        $this->app->instance(Fetcher::class, new class($pages) extends Fetcher
        {
            public function __construct(private array $pages) {}

            public function get(string $url, array $options = []): string
            {
                return $this->pages[$url] ?? throw new \RuntimeException("Unexpected URL {$url}");
            }
        });
    }

    public function test_dry_run_compares_with_stored_builds_without_writing(): void
    {
        app(BuildSync::class)->sync(app(BuildSourceRegistry::class)->get('genshin-builds.genshin'), ['force' => true]);
        $runs = SyncRun::query()->count();

        $this->artisan('builds:sync', ['source' => 'genshin-builds.genshin', '--dry-run' => true])
            ->expectsOutputToContain('1 unchanged, 0 changed')
            ->assertSuccessful();

        // The site changes the goblet recommendation: the dry run reports it, the database keeps the old build.
        $url = 'https://genshin-builds.com/en/character/hu_tao';
        $this->servePages([$url => str_replace('Pyro DMG Bonus', 'HP%', $this->pages[$url])] + $this->pages);

        $this->artisan('builds:sync', ['source' => 'genshin-builds.genshin', '--dry-run' => true])
            ->expectsOutputToContain('DPS=changed')
            ->assertSuccessful();

        $this->assertSame($runs, SyncRun::query()->count());
        $this->assertSame([['stat' => 'pyro_dmg_', 'priority' => 1]], Build::query()->firstOrFail()->main_stats['goblet']);
    }

    private function island(string $component, array $props): string
    {
        return '<html><body><astro-island component-url="/_astro/'.$component.'.abc.js" props="'
            .htmlspecialchars(json_encode($props), ENT_QUOTES).'"></astro-island></body></html>';
    }

    public function test_sync_stores_normalized_builds_and_is_idempotent(): void
    {
        $registry = app(BuildSourceRegistry::class);
        $source = $registry->get('genshin-builds.genshin');

        $run = app(BuildSync::class)->sync($source, ['force' => true]);

        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->stats['characters_synced']);
        $this->assertSame(1, $run->stats['characters_skipped']); // Vesna is not released

        $build = Build::query()->firstOrFail();
        $this->assertSame('DPS', $build->variant);
        $this->assertSame(['staffofhoma', 'dragonsbane'], array_column($build->weapons, 'key'));
        $this->assertSame(5, $build->weapons[1]['refinement']);
        $this->assertSame(4, $build->sets[0]['pieces'][0]['count']);
        // "2pc CW + 2pc any +80 EM set"
        $this->assertSame(['gildeddreams', 'wandererstroupe'], $build->sets[1]['pieces'][1]['keys']);
        $this->assertSame(['blizzardstrayer', 'finaleofthedeepgalleries'], $build->sets[2]['pieces'][1]['keys']);
        $this->assertSame('Cryo DMG set', $build->sets[2]['pieces'][1]['label']);
        $this->assertSame([['stat' => 'pyro_dmg_', 'priority' => 1]], $build->main_stats['goblet']);
        $this->assertEquals(60, $build->stat_targets[0]['min']);

        // Nothing changed on the site: a second run creates nothing new.
        $second = app(BuildSync::class)->sync($source, ['force' => true]);
        $this->assertSame(1, $second->stats['builds_unchanged']);
        $this->assertSame(1, Build::query()->count());

        // A recent successful sync is skipped without "force".
        $this->assertNull(app(BuildSync::class)->sync($source));
        $this->assertSame('success', BuildSource::query()->where('key', 'genshin-builds.genshin')->value('last_status'));
    }
}
