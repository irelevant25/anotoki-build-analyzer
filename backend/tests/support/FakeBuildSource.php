<?php

namespace Tests\Support;

use App\Enums\Game;
use App\Http\FetchDeferred;
use App\Http\Fetcher;
use App\Services\Builds\BuildSource;
use App\Services\Builds\CharacterRef;
use App\Services\Builds\ScrapedBuild;
use App\Support\Clock;

/**
 * A build site for stepped-sync tests: no pages, no network. $defer makes a character's page "not yet"
 * (as the fetcher does when a crawl delay does not fit into a step) a number of times; $viaFetcher reads
 * each page through the real Fetcher instead (the tests fake the HTTP answers).
 */
class FakeBuildSource implements BuildSource
{
    /** @var list<string> released characters on the site (plus one unreleased) */
    public static array $characters = ['Hu Tao', 'Kaedehara Kazuha'];

    /** @var array<string, int> character name => deferrals left */
    public static array $defer = [];

    public static float $deferSeconds = 10;

    public static bool $viaFetcher = false;

    /** @var list<string> characters whose page was read, in order */
    public static array $fetched = [];

    public function __construct(private readonly Fetcher $fetcher) {}

    public static function reset(): void
    {
        self::$characters = ['Hu Tao', 'Kaedehara Kazuha'];
        self::$defer = [];
        self::$deferSeconds = 10;
        self::$viaFetcher = false;
        self::$fetched = [];
    }

    public function key(): string
    {
        return 'fake.genshin';
    }

    public function game(): Game
    {
        return Game::Genshin;
    }

    public function name(): string
    {
        return 'Fake Builds';
    }

    public function baseUrl(): string
    {
        return 'https://fake-builds.example';
    }

    public function delayMs(): int
    {
        return 10_000;
    }

    public function listCharacters(): array
    {
        $refs = array_map(function (string $name) {
            $slug = strtolower(str_replace(' ', '-', $name));

            return new CharacterRef($slug, $name, "https://fake-builds.example/{$slug}");
        }, self::$characters);
        $refs[] = new CharacterRef('someone-new', 'Someone New', 'https://fake-builds.example/new', released: false);

        return $refs;
    }

    public function fetchBuilds(CharacterRef $character): array
    {
        if ((self::$defer[$character->name] ?? 0) > 0) {
            self::$defer[$character->name]--;

            throw new FetchDeferred('fake-builds.example', Clock::microtime() + self::$deferSeconds);
        }
        if (self::$viaFetcher) {
            $this->fetcher->get($character->url, ['delay_ms' => 0]);
        }
        self::$fetched[] = $character->name;

        return [new ScrapedBuild(
            variant: 'Main',
            recommended: true,
            weapons: [['name' => 'Staff of Homa', 'refinement' => 1]],
            sets: [['kind' => 'artifact', 'pieces' => [['names' => ['Crimson Witch of Flames'], 'label' => 'Crimson Witch of Flames', 'count' => 4]]]],
            mainStats: ['goblet' => [['stat' => 'pyro_dmg_', 'priority' => 1]]],
            substats: [['stat' => 'critRate_', 'priority' => 1]],
        )];
    }
}
