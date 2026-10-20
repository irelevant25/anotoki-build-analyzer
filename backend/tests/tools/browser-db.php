<?php

/**
 * The browser check's throwaway installation (.claude/skills/verify-ui/scripts/run.mjs runs it; from backend/):
 *
 *   php tests/tools/browser-db.php make <dir> <issuer>   a database build_analyzer_test_browser made afresh from
 *                                                        the migrations, with a small catalog and Hu Tao's two
 *                                                        builds (the second needs C2) imported as a file, and
 *                                                        <dir> as the settings folder BUILD_ANALYZER_TEST_CONFIG
 *                                                        names (database.local.php, auth.local.php -> <issuer>,
 *                                                        storage/)
 *   php tests/tools/browser-db.php sql <dir> <query> [<params as JSON>]   one query; its rows as JSON
 *   php tests/tools/browser-db.php drop <dir>            the database goes
 *
 * The PostgreSQL server (and login) is the one of config/database.local.php, as for the tests; only the
 * database is the check's own, and nothing here touches any database whose name does not begin with
 * build_analyzer_test_. <dir> lies outside the repository (a temp folder the check deletes): the
 * database.local.php written there carries the login, as the tests' in-memory configuration does.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Cache\Cache;
use App\Config;
use App\Db\Db;
use App\Db\Migrations;
use App\Enums\Game;
use App\Models\User;
use App\Repositories\Builds;
use App\Repositories\CatalogRows;
use App\Services\Builds\BuildFile;
use App\Services\Builds\BuildImport;
use App\Services\Catalog\CatalogSync;
use App\Support\Clock;
use App\Support\Stats;

const BROWSER_DATABASE = Config::TEST_DATABASE_PREFIX . 'browser';

[$command, $dir] = [$argv[1] ?? '', rtrim(str_replace('\\', '/', $argv[2] ?? ''), '/')];
if (!in_array($command, ['make', 'sql', 'drop'], true) || $dir === '') {
    fwrite(STDERR, "usage: php tests/tools/browser-db.php make <dir> <issuer> | sql <dir> <query> [<params>] | drop <dir>\n");
    exit(2);
}

/** The server of this checkout's settings, without its database. */
function browserServer(): array
{
    $local = __DIR__ . '/../../config/database.local.php';
    if (!is_file($local)) {
        fwrite(STDERR, "The browser check needs the PostgreSQL server of config/database.local.php. Run setup.php first (see DEV.md).\n");
        exit(1);
    }
    $server = require $local;
    unset($server['database']);

    return $server;
}

/** The check's own settings, as make wrote them - never anything but a throwaway database. */
function browserSettings(string $dir): array
{
    $settings = is_file("{$dir}/database.local.php") ? require "{$dir}/database.local.php" : null;
    if (!is_array($settings) || !str_starts_with((string) ($settings['database'] ?? ''), Config::TEST_DATABASE_PREFIX)) {
        fwrite(STDERR, "{$dir} holds no settings of a " . Config::TEST_DATABASE_PREFIX . " database.\n");
        exit(1);
    }

    return $settings;
}

function browserDrop(array $server, string $name): void
{
    if (!str_starts_with($name, Config::TEST_DATABASE_PREFIX)) {
        throw new RuntimeException("Not a throwaway database: {$name}");
    }
    Db::pdo($server, 'postgres', false)->exec('DROP DATABASE IF EXISTS "' . $name . '" WITH (FORCE)');
}

if ($command === 'drop') {
    $settings = browserSettings($dir);
    browserDrop($settings, (string) $settings['database']);
    echo "dropped {$settings['database']}\n";
    exit(0);
}

if ($command === 'sql') {
    $settings = browserSettings($dir);
    $db = new Db(Db::pdo($settings, (string) $settings['database'], false));
    $params = isset($argv[4]) ? json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR) : [];
    echo json_encode($db->all($argv[3] ?? 'SELECT 1', $params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// make
$issuer = rtrim((string) ($argv[3] ?? ''), '/');
if (!preg_match('#^http://127\.0\.0\.1:\d+$#', $issuer)) {
    fwrite(STDERR, "make needs the stand-in IAM's address (http://127.0.0.1:<port>).\n");
    exit(2);
}
$server = browserServer();
browserDrop($server, BROWSER_DATABASE);
Db::ensureDatabase($server, BROWSER_DATABASE);
$pdo = Db::pdo($server, BROWSER_DATABASE, false);
$result = Migrations::migrator($pdo)->apply();
if ($result['busy'] || $result['error'] !== null) {
    fwrite(STDERR, $result['busy'] ? "the migrations' lock is held: nothing was applied\n" : 'migration ' . ($result['failed']['name'] ?? '') . " failed: {$result['error']}\n");
    exit(1);
}

// The catalog the tests use (tests/support/seed.php), with what the catalog's provider says of Hu Tao and Kazuha that
// the builds browser filters by (migration 018's columns); Hu Tao's builds as an editor imports them - a main DPS
// build, and a Sub DPS one that needs C2, with an Energy Recharge target from C0 and a lower one from C1 - and a Support
// build of Kazuha's, so the builds browser has two characters to filter.
$db = new Db($pdo);
function db(): Db
{
    return $GLOBALS['db'];
}
require __DIR__ . '/../support/seed.php';
seedGenshinCatalog();
$db->run("UPDATE game_characters SET region = 'Liyue', version = '1.3', released_at = '2021-03-02 00:00:00+00' WHERE key = 'hutao'");
$db->run("UPDATE game_characters SET region = 'Inazuma', version = '1.6', released_at = '2021-06-29 00:00:00+00', weapon_type = 'sword' WHERE key = 'kaedeharakazuha'");
$hutao = static fn(array $fields) => $fields + [
    'character' => 'hutao',
    'character_name' => 'Hu Tao',
    'qualifier' => '',
    'min_rank' => 0,
    'weapons' => [['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'refinement' => null, 'note' => null], ['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'refinement' => 5, 'note' => null]],
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
    'stat_targets' => [['stat' => 'enerRech_', 'min' => 120, 'max' => null, 'text' => '', 'from_rank' => 0], ['stat' => 'enerRech_', 'min' => 110, 'max' => null, 'text' => '', 'from_rank' => 1]],
    'notes' => null,
    'game_version' => '5.4',
];
(new BuildImport($db, new Builds($db), new CatalogRows($db)))->import(
    [
        'format' => BuildFile::FORMAT,
        'format_version' => BuildFile::VERSION,
        'game' => 'genshin',
        'exported_at' => '2026-10-01T10:00:00Z',
        'builds' => [
            $hutao(['role' => 'main_dps']),
            $hutao(['role' => 'sub_dps', 'min_rank' => 2, 'weapons' => [['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'refinement' => 5, 'note' => null]]]),
            $hutao(['character' => 'kaedeharakazuha', 'character_name' => 'Kaedehara Kazuha', 'role' => 'support', 'weapons' => []]),
        ],
    ],
    Game::Genshin,
    // Nobody's: no person is there yet.
    new User(0, 'seed'),
);

// The catalog as refreshed a moment ago (App\Services\Catalog\CatalogSync's state of each list): the upkeep after an
// answer has nothing to ask the catalog's provider, so the check never reaches the network.
$cache = new Cache($db);
foreach (CatalogSync::LISTS as $list) {
    $cache->put(
        "catalog-state:genshin:{$list}",
        ['etag' => null, 'checked_at' => Clock::timestamp(), 'changed_at' => Clock::timestamp(), 'failed_at' => null, 'problem' => null, 'count' => null],
        86400,
    );
}

@mkdir("{$dir}/storage", 0777, true);
file_put_contents("{$dir}/database.local.php", '<?php return ' . var_export(['database' => BROWSER_DATABASE, 'persistent' => false] + $server, true) . ";\n");
file_put_contents("{$dir}/auth.local.php", '<?php return ' . var_export(['issuer' => $issuer, 'client_id' => 'build-analyzer'], true) . ";\n");
echo 'made ' . BROWSER_DATABASE . ' with ' . count($result['applied']) . " migrations\n";
