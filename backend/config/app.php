<?php

// ---------------------------------------------------------------------------
// config/app.php - the application's own settings, the same everywhere
// ---------------------------------------------------------------------------
// What differs per installation (the database, how people sign in, how the app
// identifies itself to other sites) lives in config/*.local.php, which setup.php
// writes; see App\Config. Nothing here is a secret or a fallback for those.
// ---------------------------------------------------------------------------

return [
    /*
    | The game catalog (names, rarity, elements, icons): up-to-date lists keyed by in-game IDs. Refreshed list by
    | list on the admin panel's Builds page, or by sync-catalog.php when older than refresh_days. An import that
    | names an in-game ID the catalog lacks may refresh that one list, at most once per game and list per
    | import_refresh_minutes (for every user together).
    */
    'catalog' => [
        'genshin' => [
            'api' => 'https://gi.yatta.moe/api/v2/en',
            'assets' => 'https://gi.yatta.moe/assets/UI',
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
        // Imports by UID per user and minute (a visitor's: per address, see client_address).
        'per_minute' => 10,
    ],

    /*
    | File imports per user and minute (a visitor's: per address). A member's file may be up to 20 MB.
    */
    'imports' => [
        'file_per_minute' => 20,
    ],

    /*
    | A signed-in person's own accounts as a file (GET /api/me/export, POST /api/me/import): imports a
    | minute per person (a dry run counts too), and the largest file - the import refuses a larger body
    | before reading it as an accounts file, and the export refuses to write one, so every export imports.
    | PHP has taken the body by then (and Slim's body parser has decoded one sent as application/json):
    | what bounds a request's body is the host's PHP post_max_size, so keep this at or below it (a larger
    | body arrives empty: "No file arrived"). The export is compact JSON; a few thousand artifacts take
    | well under 2 MB.
    */
    'me' => [
        'import_per_minute' => 5,
        'file_max_mb' => 8,
    ],

    /*
    | Visitors: people who use the site without signing in. What they import is kept under a visitor
    | account (App\Auth\Visitors), made by their first import that goes through and deleted keep_days after
    | it was last used. Everything here bounds what strangers can make the site store and fetch:
    |
    |   new_per_hour_per_address  new visitors an hour from one address (an IPv6 address counts by its /64)
    |   new_per_hour              new visitors an hour from everywhere together
    |   max_visitors              visitors at once; past it nobody new gets one (429 too_many_visitors)
    |   max_accounts              game accounts one visitor may keep
    |   file_max_kb               the largest file a visitor may import (checked once PHP has taken the
    |                             body: post_max_size is what bounds any request's body)
    |   showcase_per_minute_all   showcase imports a minute by all visitors together (the outside service
    |                             sees no more than members already cause)
    |   sweep_batch, sweep_timeout_ms   how many unseen visitors one clean-up deletes, and how long its
    |                             statement may take - it runs inside somebody's request, after the answer
    |   move_lock_timeout_ms      how long the move at sign-in waits for an import that is still writing
    |                             (then 503 visitor_move_retry, and the page asks again)
    |
    | Watch the count on the People page (or SELECT count(*) FROM users WHERE id < 0) after a launch, and
    | lower these if the database grows faster than it should.
    */
    'visitors' => [
        'keep_days' => 30,
        'new_per_hour_per_address' => 10,
        'new_per_hour' => 60,
        'max_visitors' => 1000,
        'max_accounts' => 5,
        'file_max_kb' => 5120,
        'showcase_per_minute_all' => 20,
        'sweep_batch' => 20,
        'sweep_timeout_ms' => 2000,
        'move_lock_timeout_ms' => 5000,
    ],

    /*
    | Which address a request comes from, for the limits counted per address (visitors, and imports by
    | somebody who is not signed in) and for the anotoki library's admin tools (the feedback's limits, the
    | error log's client hash) - one rule for both, the library's (Anotoki\Lib\Support\ClientAddress).
    | REMOTE_ADDR, always - unless the connection comes from one of trusted_proxies (CIDR ranges, v4 and
    | v6: Cloudflare in front of the site), and then the address in CF-Connecting-IP, which only Cloudflare
    | is believed to write. A forwarding header from anybody else is whatever the sender wrote, so it is
    | never read on its own (X-Forwarded-For included). The address is never kept: the counters keep a keyed
    | hash of it (App\Web\ClientAddress), and so do the library's tools.
    |
    | The site is behind Cloudflare (the owner's decision of 2026-10-11): trusted_proxies are Cloudflare's
    | published ranges, from https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6,
    | fetched 2026-10-11 (15 IPv4, 7 IPv6 - the library's ClientAddress::CLOUDFLARE of 0.5.0 is the same
    | list). CF-Connecting-IP is believed only on a connection whose REMOTE_ADDR is in one of them; from
    | anywhere else the connection's own address is counted. They change rarely - refresh them from those
    | two addresses when Cloudflare announces a change (docs/operations.md). An installation that is not
    | behind Cloudflare (this computer, another host) may override them in config/client_address.local.php
    | (gitignored): return ['trusted_proxies' => []] - the proxies alone, never another header, and never a
    | range wider than /8 (IPv6: /16): the site is "not set up" until it is narrowed (App\Config::check()).
    |
    | CF-Connecting-IP and no other header, whatever a setting says (the owner's rule of 2026-10-11):
    | Cloudflare writes it whole over whatever the visitor sent, one address, while X-Real-IP and
    | True-Client-IP pass through it as the visitor wrote them and X-Forwarded-For starts with what they
    | wrote. A CF-Connecting-IP that is not one address is not believed either: the proxy's own address is
    | counted then.
    */
    'client_address' => [
        'trusted_proxies' => [
            // IPv4 (https://www.cloudflare.com/ips-v4, 2026-10-11)
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // IPv6 (https://www.cloudflare.com/ips-v6, 2026-10-11)
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ],
    ],

    /*
    | What ordinary traffic does after an answer - the host has no cron (api/middleware/upkeep.php):
    |
    |   people        the scrub of the accounts the anotoki IAM deleted: at most once an hour, the IAM's list
    |                 read and each account nobody's here from then on (App\People\SitePeople)
    |   daily_backup  the day's backup of the database (anotoki-lib's backups, storage/backups: 14 kept)
    |   prune         the day's tidying up: what has expired in the cache table, the downloaded files older
    |                 than three days (App\Cache\Prune)
    |
    | All on wherever the site runs; the tests switch them off, but where they mean to try them.
    */
    'upkeep' => [
        'people' => true,
        'daily_backup' => true,
        'prune' => true,
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
    ],
];
