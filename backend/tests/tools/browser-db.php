<?php

/**
 * The browser check's throwaway installation (.claude/skills/verify-ui/scripts/run.mjs runs it; from backend/):
 *
 *   php tests/tools/browser-db.php make <dir> <issuer>   a database build_analyzer_test_browser made afresh from
 *                                                        the migrations, with a small catalog and one build, and
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

require __DIR__.'/../../vendor/autoload.php';

use App\Config;
use App\Db\Db;
use App\Db\Migrator;
use App\Support\Clock;
use App\Support\Json;

const BROWSER_DATABASE = Config::TEST_DATABASE_PREFIX.'browser';

[$command, $dir] = [$argv[1] ?? '', rtrim(str_replace('\\', '/', $argv[2] ?? ''), '/')];
if (! in_array($command, ['make', 'sql', 'drop'], true) || $dir === '') {
    fwrite(STDERR, "usage: php tests/tools/browser-db.php make <dir> <issuer> | sql <dir> <query> [<params>] | drop <dir>\n");
    exit(2);
}

/** The server of this checkout's settings, without its database. */
function browserServer(): array
{
    $local = __DIR__.'/../../config/database.local.php';
    if (! is_file($local)) {
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
    if (! is_array($settings) || ! str_starts_with((string) ($settings['database'] ?? ''), Config::TEST_DATABASE_PREFIX)) {
        fwrite(STDERR, "{$dir} holds no settings of a ".Config::TEST_DATABASE_PREFIX." database.\n");
        exit(1);
    }

    return $settings;
}

function browserDrop(array $server, string $name): void
{
    if (! str_starts_with($name, Config::TEST_DATABASE_PREFIX)) {
        throw new RuntimeException("Not a throwaway database: {$name}");
    }
    Db::pdo($server, 'postgres', false)->exec('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
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
    echo json_encode($db->all($argv[3] ?? 'SELECT 1', $params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    exit(0);
}

// make
$issuer = rtrim((string) ($argv[3] ?? ''), '/');
if (! preg_match('#^http://127\.0\.0\.1:\d+$#', $issuer)) {
    fwrite(STDERR, "make needs the stand-in IAM's address (http://127.0.0.1:<port>).\n");
    exit(2);
}
$server = browserServer();
browserDrop($server, BROWSER_DATABASE);
Migrator::ensureDatabase($server, BROWSER_DATABASE);
$pdo = Db::pdo($server, BROWSER_DATABASE, false);
$result = (new Migrator($pdo, Migrator::directory()))->applyPending();
if ($result['failed'] !== null) {
    fwrite(STDERR, "migration {$result['failed']} failed: {$result['error']}\n");
    exit(1);
}

// The catalog the tests use (tests/support/seed.php), and one build for Hu Tao from the first Genshin source.
$db = new Db($pdo);
function db(): Db
{
    return $GLOBALS['db'];
}
require __DIR__.'/../support/seed.php';
seedGenshinCatalog();
seedHsrCatalog();
$now = Clock::now();
$source = (int) $db->insert('build_sources', ['key' => 'prydwen.genshin', 'game' => 'genshin', 'name' => 'Source 1', 'base_url' => 'https://example.invalid',
    'enabled' => true, 'last_synced_at' => $now, 'last_status' => 'success', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('builds', [
    'build_source_id' => $source, 'game_character_id' => idOf('game_characters', 'hutao'), 'variant' => 'DPS', 'recommended' => true, 'position' => 0,
    'weapons' => Json::encode([
        ['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => null, 'note' => null, 'known' => true],
        ['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'rank' => 2, 'refinement' => 5, 'note' => null, 'known' => true],
    ]),
    'sets' => Json::encode([
        ['rank' => 1, 'kind' => 'artifact', 'choose' => null, 'note' => null, 'pieces' => [['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']]]],
    ]),
    'main_stats' => Json::encode([
        'sands' => [['stat' => 'hp_', 'priority' => 1]],
        'goblet' => [['stat' => 'pyro_dmg_', 'priority' => 1]],
        'circlet' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1]],
    ]),
    'substats' => Json::encode([['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1], ['stat' => 'hp_', 'priority' => 2]]),
    'content_hash' => str_repeat('b', 64), 'fetched_at' => $now, 'changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);

@mkdir("{$dir}/storage", 0777, true);
file_put_contents("{$dir}/database.local.php", '<?php return '.var_export(['database' => BROWSER_DATABASE, 'persistent' => false] + $server, true).";\n");
file_put_contents("{$dir}/auth.local.php", '<?php return '.var_export(['issuer' => $issuer, 'client_id' => 'build-analyzer'], true).";\n");
echo 'made '.BROWSER_DATABASE.' with '.count($result['applied'])." migrations\n";
