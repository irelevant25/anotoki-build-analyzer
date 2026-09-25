<?php

use App\Services\Builds\Sources\GenshinBuildsGenshin;
use App\Services\Builds\Sources\GenshinGgGenshin;
use App\Services\Builds\Sources\GenshinGgHsr;
use App\Services\Builds\Sources\PrydwenGenshin;
use App\Services\Builds\Sources\PrydwenHsr;

return [

    /*
    |--------------------------------------------------------------------------
    | Outgoing HTTP
    |--------------------------------------------------------------------------
    | Every request to a third-party site identifies itself and waits between
    | requests, so the daily sync stays polite.
    */

    'http' => [
        'user_agent' => env('HOYO_USER_AGENT', 'hoyo-analyzer/0.1 (personal build analyzer)'),
        'browser_user_agent' => env('HOYO_BROWSER_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36'),
        'timeout' => (int) env('HOYO_HTTP_TIMEOUT', 30),
        'delay_ms' => (int) env('HOYO_HTTP_DELAY_MS', 1200),
        'cache_hours' => (int) env('HOYO_HTTP_CACHE_HOURS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Game catalog (names, rarity, elements, icons)
    |--------------------------------------------------------------------------
    | Project Amber / yatta.moe exposes up-to-date lists of characters, weapons
    | and sets for both games, keyed by in-game IDs.
    */

    'catalog' => [
        'genshin' => [
            'api' => 'https://gi.yatta.moe/api/v2/en',
            'assets' => 'https://gi.yatta.moe/assets/UI',
        ],
        'hsr' => [
            'api' => 'https://sr.yatta.moe/api/v2/en',
            'assets' => 'https://sr.yatta.moe/hsr/assets/UI',
        ],
        'refresh_days' => (int) env('HOYO_CATALOG_REFRESH_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enka.Network (showcase import by UID)
    |--------------------------------------------------------------------------
    */

    'enka' => [
        'api' => env('HOYO_ENKA_API', 'https://enka.network/api'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Build sync
    |--------------------------------------------------------------------------
    | builds:sync skips a source that was synced successfully less than
    | "stale_hours" ago, so the scheduler (or a manual run) never hammers a site.
    */

    'sync' => [
        'stale_hours' => (int) env('HOYO_SYNC_STALE_HOURS', 20),
        'daily_at' => env('HOYO_SYNC_DAILY_AT', '04:30'),
        // Timezone of the scheduled times above (the app itself and the database stay in UTC).
        'timezone' => env('HOYO_SYNC_TIMEZONE', 'UTC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Build sources
    |--------------------------------------------------------------------------
    | Scrapers that run in the daily sync. Chosen for having structured,
    | up-to-date data (see README for why game8 / KeqingMains are not used).
    */

    'sources' => [
        GenshinBuildsGenshin::class,
        PrydwenGenshin::class,
        GenshinGgGenshin::class,
        PrydwenHsr::class,
        GenshinGgHsr::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Name aliases
    |--------------------------------------------------------------------------
    | Normalized alias => normalized canonical key. Only needed for nicknames and
    | abbreviations; prefixes/suffixes such as "Kazuha" or "Emblem" are matched
    | automatically when they are unique.
    */

    'aliases' => [
        'genshin' => [
            'characters' => [
                'childe' => 'tartaglia',
                'raiden' => 'raidenshogun',
                'ei' => 'raidenshogun',
                'yae' => 'yaemiko',
                'hutao' => 'hutao',
                'ayaka' => 'kamisatoayaka',
                'ayato' => 'kamisatoayato',
                'kokomi' => 'sangonomiyakokomi',
                'itto' => 'aratakiitto',
                'sara' => 'kujousara',
                'heizou' => 'shikanoinheizou',
                'shinobu' => 'kukishinobu',
                'kazuha' => 'kaedeharakazuha',
            ],
            'weapons' => [
                'thecatch' => 'thecatch',
                'favsword' => 'favoniussword',
                'favlance' => 'favoniuslance',
                'favbow' => 'favoniuswarbow',
                'favgreatsword' => 'favoniusgreatsword',
                'favcodex' => 'favoniuscodex',
                'sacsword' => 'sacrificialsword',
                'sacbow' => 'sacrificialbow',
                'sacgreatsword' => 'sacrificialgreatsword',
                'sacfrags' => 'sacrificialfragments',
                'sacjade' => 'sacrificialjade',
                // Typos seen on build sites.
                'prototypearchiac' => 'prototypearchaic',
                'gestofthemightwolf' => 'gestofthemightywolf',
            ],
            'sets' => [
                'vv' => 'viridescentvenerer',
                'tom' => 'tenacityofthemillelith',
                'eosf' => 'emblemofseveredfate',
                'glad' => 'gladiatorsfinale',
                'cw' => 'crimsonwitchofflames',
                'cwof' => 'crimsonwitchofflames',
                'mh' => 'marechausseehunter',
                'hod' => 'heartofdepth',
                'bs' => 'blizzardstrayer',
                'tf' => 'thunderingfury',
                'gd' => 'gildeddreams',
                'dm' => 'deepwoodmemories',
                'no' => 'noblesseoblige',
                'nwew' => 'nighttimewhispersintheechoingwoods',
                'fohw' => 'fragmentofharmonicwhimsy',
                'sodp' => 'songofdayspast',
                'ohc' => 'oceanhuedclam',
                'gt' => 'goldentroupe',
                'wt' => 'wandererstroupe',
                'eot' => 'echoesofanoffering',
                'shim' => 'shimenawasreminiscence',
                'shimenawa' => 'shimenawasreminiscence',
                'ttds' => 'thundersoother',
                'vermillion' => 'vermillionhereafter',
                'husk' => 'huskofopulentdreams',
                'obsidian' => 'obsidiancodex',
                'sms' => 'scrolloftheheroofcindercity',
                'scroll' => 'scrolloftheheroofcindercity',
                'lotss' => 'longnightsoath',
                'emblemofseveredfates' => 'emblemofseveredfate', // typo seen on genshin.gg
            ],
        ],
        'hsr' => [
            'characters' => [
                'dhil' => 'danhengimbibitorlunae',
                'il' => 'danhengimbibitorlunae',
                'imbibitorlunae' => 'danhengimbibitorlunae',
                'sw' => 'silverwolf',
                'ratio' => 'drratio',
                'topaz' => 'topaznumby',
                'numby' => 'topaznumby',
                'topazandnumby' => 'topaznumby',
                'boothiill' => 'boothill',
                // Alternate versions are named "<Base> • <Name>" on Prydwen but only "<Name>" in game data.
                'march7thevernight' => 'evernight',
                'tingyunfugue' => 'fugue',
                // genshin.gg names Trailblazers by element instead of path.
                'trailblazerphysical' => 'trailblazerdestruction',
                'trailblazerfire' => 'trailblazerpreservation',
                'trailblazerimaginary' => 'trailblazerharmony',
                'trailblazerice' => 'trailblazerremembrance',
                'trailblazerlightning' => 'trailblazerelation',
            ],
            'weapons' => [
                'agroundedascend' => 'agroundedascent', // typo seen on genshin.gg
            ],
            'sets' => [
                // Typos / wrong names seen on genshin.gg.
                'messengeroftraversinghackerspace' => 'messengertraversinghackerspace',
                'poetofmourningdisciple' => 'poetofmourningcollapse',
                'pangalacticcommercialenterprise' => 'pancosmiccommercialenterprise',
                'pcec' => 'pancosmiccommercialenterprise',
                'fleet' => 'fleetoftheageless',
                'glamoth' => 'firmamentfrontlineglamoth',
                'izumo' => 'izumogenseiandtakamadivinerealm',
                'kalpagni' => 'forgeofthekalpagnilantern',
                'lushaka' => 'lushakathesunkenseas',
                'penacony' => 'penaconylandofthedreams',
                'talia' => 'taliakingdomofbanditry',
                'vonwacq' => 'sprightlyvonwacq',
                'salsotto' => 'inertsalsotto',
                'keel' => 'brokenkeel',
                'duran' => 'durandynastyofrunningwolves',
                'belobog' => 'belobogofthearchitects',
                'wastelander' => 'wastelanderofbanditrydesert',
                'genius' => 'geniusofbrilliantstars',
                'musketeer' => 'musketeerofwildwheat',
            ],
        ],
    ],
];
