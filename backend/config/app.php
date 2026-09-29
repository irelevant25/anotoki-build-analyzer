<?php

// ---------------------------------------------------------------------------
// config/app.php - the application's own settings, the same everywhere
// ---------------------------------------------------------------------------
// What differs per installation (the database, how people sign in, how the app
// identifies itself to other sites) lives in config/*.local.php, which setup.php
// writes; see App\Config. Nothing here is a secret or a fallback for those.
// ---------------------------------------------------------------------------

use App\Services\Builds\Sources\GenshinBuildsGenshin;
use App\Services\Builds\Sources\GenshinGgGenshin;
use App\Services\Builds\Sources\GenshinGgHsr;
use App\Services\Builds\Sources\PrydwenGenshin;
use App\Services\Builds\Sources\PrydwenHsr;

return [

    /*
    | Build sources: the scrapers the build sync runs, chosen for structured, up-to-date data (see
    | docs/build-sources.md for the sites that were rejected and why).
    |
    | The UI never names a site: each source is shown as "Source N" of its game, numbered in this order,
    | and the API calls it "{game}-{N}". Add a new source at the end of its game so the others keep their
    | numbers.
    */
    'sources' => [
        GenshinBuildsGenshin::class,
        PrydwenGenshin::class,
        GenshinGgGenshin::class,
        PrydwenHsr::class,
        GenshinGgHsr::class,
    ],

    /*
    | The game catalog (names, rarity, elements, icons): up-to-date lists for both games, keyed by in-game
    | IDs. Refreshed by a catalog run when older than refresh_days. An import that names an in-game ID the
    | catalog lacks may refresh that one list, at most once per game and list per import_refresh_minutes
    | (for every user together).
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
        'refresh_days' => 3,
        'import_refresh_minutes' => 60,
    ],

    /*
    | Showcase imports by UID, and the game-data file that tells which Genshin skill is which.
    */
    'showcase' => [
        'api' => 'https://enka.network/api',
        'genshin_avatars' => 'https://raw.githubusercontent.com/EnkaNetwork/API-docs/master/store/gi/avatars.json',
        // Imports by UID per user and minute.
        'per_minute' => 10,
    ],

    /*
    | Name aliases: normalized alias => normalized canonical key. Only needed for nicknames, abbreviations and
    | site typos; prefixes and suffixes such as "Kazuha" or "Emblem" are matched automatically when unique.
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
