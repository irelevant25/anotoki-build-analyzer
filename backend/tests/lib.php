<?php

/**
 * The test harness: a throwaway database, the API handed requests in-process, a fake network, and signed
 * access tokens.
 *
 * Tests run in-process: the Slim app is built once per file and handed PSR-7 requests, so there is no server
 * to start and nothing listens on a port. Each file gets its own database, build_analyzer_test_<pid>_<random>,
 * made from the migrations and dropped at the end - no other database is touched. The server's address and
 * credentials come from config/database.local.php (setup.php writes it); only the database name is the
 * test's own. Nothing reaches the network: every outgoing request goes to a FakeHttpClient, which fails the
 * test on any request it was not told to answer.
 */

declare(strict_types=1);

define('BUILD_ANALYZER_NO_RUN', true);
require __DIR__.'/../api/index.php';
require_once __DIR__.'/../src/Support/Iam/anotoki_iam.php';
foreach (glob(__DIR__.'/support/*.php') ?: [] as $support) {
    require_once $support;
}

use App\App;
use App\Auth\Role;
use App\Config;
use App\Db\Db;
use App\Db\Migrator;
use App\Http\HttpClient;
use App\Support\Clock;
use Tests\Support\FakeHttpClient;

const TEST_ISSUER = 'https://iam.example.test';
const TEST_CLIENT = 'build-analyzer';

final class T
{
    public static App $app;

    public static FakeHttpClient $http;

    public static Slim\App $api;

    /** @var array{kid: string, private_pem: string, jwks: array<string, mixed>} */
    public static array $key;

    public static string $jwks;

    public static string $database;

    public static string $storage;

    /** @var list<callable> */
    public static array $beforeEach = [];

    public static int $count = 0;

    public static int $failures = 0;
}

/**
 * A database of this file's own, the app configured like a hosted install (sign-in through the IAM, with
 * tokens signed by a key published as a JWKS file), storage in a temp folder.
 */
function bootTests(): void
{
    $local = __DIR__.'/../config/database.local.php';
    if (! is_file($local)) {
        fwrite(STDERR, "The tests need the PostgreSQL server of config/database.local.php. Run setup.php first (see DEVELOPING.md).\n");
        exit(1);
    }
    $server = require $local;

    $suffix = getmypid().'_'.bin2hex(random_bytes(3));
    T::$database = 'build_analyzer_test_'.$suffix;
    T::$storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'build-analyzer-test-'.$suffix;
    @mkdir(T::$storage, 0777, true);

    Migrator::ensureDatabase($server, T::$database);
    $pdo = Db::pdo($server, T::$database, false);
    $result = (new Migrator($pdo, Migrator::directory()))->applyPending(['local_user_id' => 1]);
    if ($result['failed'] !== null) {
        fwrite(STDERR, "migration {$result['failed']} failed: {$result['error']}\n");
        exit(1);
    }

    T::$key = anotoki_iam_test_keypair('test-key');
    T::$jwks = T::$storage.DIRECTORY_SEPARATOR.'jwks.json';
    file_put_contents(T::$jwks, json_encode(T::$key['jwks']));

    $config = testConfig(['database' => ['database' => T::$database, 'persistent' => false] + $server]);
    T::$app = new App($config, web: true);
    T::$app->set(Db::class, new Db($pdo));
    T::$http = new FakeHttpClient;
    T::$app->set(HttpClient::class, T::$http);
    T::$api = buildAnalyzerApi(T::$app);

    // Every test starts from empty tables, this configuration, no faked answers and nothing cached.
    $snapshot = $config->snapshot();
    beforeEach(function () use ($snapshot): void {
        resetDatabase();
        T::$app->config->restore($snapshot);
        T::$http->reset();
        T::$app->resetScope();
        Tests\Support\FakeBuildSource::reset();
        removeTree(T::$storage.DIRECTORY_SEPARATOR.'http-cache');
    });

    register_shutdown_function(static function () use ($server): void {
        try {
            $postgres = Db::pdo($server, 'postgres', false);
            $postgres->exec('DROP DATABASE IF EXISTS "'.T::$database.'" WITH (FORCE)');
        } catch (Throwable $e) {
            fwrite(STDERR, 'could not drop '.T::$database.": {$e->getMessage()}\n");
        }
        removeTree(T::$storage);
    });
}

/**
 * The configuration of a hosted install, with $values replacing sections. Storage is the test's temp folder.
 *
 * @param  array<string, mixed>  $values
 */
function testConfig(array $values = []): Config
{
    return Config::fromArray($values + [
        'database' => [],
        'auth' => ['mode' => 'iam', 'issuer' => TEST_ISSUER, 'client_id' => TEST_CLIENT, 'local_user_id' => 1, 'jwks_file' => T::$jwks],
        'fetch' => ['user_agent' => 'build-analyzer-tests', 'delay_ms' => 0],
    ], (string) realpath(__DIR__.'/..'), T::$storage);
}

// ── Running and checking ─────────────────────────────────────────────────────────────────────────

function beforeEach(callable $hook): void
{
    T::$beforeEach[] = $hook;
}

function test(string $name, callable $body): void
{
    T::$count++;

    try {
        foreach (T::$beforeEach as $hook) {
            $hook();
        }
        $body();
        echo "ok    {$name}\n";
    } catch (Throwable $e) {
        T::$failures++;
        echo "FAIL  {$name}\n      {$e->getMessage()}\n";
        if (! $e instanceof AssertionFailed) {
            echo '      at '.$e->getFile().':'.$e->getLine()."\n";
        }
    } finally {
        Clock::reset();
    }
}

final class AssertionFailed extends RuntimeException {}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new AssertionFailed($message);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed($message.' - expected '.json_encode($expected).', got '.json_encode($actual));
    }
}

function near(float $expected, float $actual, float $delta, string $message): void
{
    if (abs($expected - $actual) > $delta) {
        throw new AssertionFailed("{$message} - expected {$expected} +/- {$delta}, got {$actual}");
    }
}

function throws(string $class, callable $body, string $message): Throwable
{
    try {
        $body();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("{$message} - expected {$class}, got ".get_class($e).': '.$e->getMessage());
    }
    throw new AssertionFailed("{$message} - expected {$class}, nothing was thrown");
}

function finishTests(): never
{
    $failures = T::$failures;
    echo "\n".T::$count.' tests, '.($failures === 0 ? 'all passed' : "{$failures} failed")."\n";
    exit($failures === 0 ? 0 : 1);
}

// ── The database ─────────────────────────────────────────────────────────────────────────────────

function db(): Db
{
    return T::$app->db();
}

/** Every table empty again (what a test wrote goes; the schema stays). */
function resetDatabase(): void
{
    db()->run('TRUNCATE users, game_characters, game_weapons, game_sets, accounts, import_runs, account_characters,
        account_weapons, account_items, build_sources, builds, sync_runs, cache, cache_locks RESTART IDENTITY CASCADE');
}

function removeTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

// ── Tokens ───────────────────────────────────────────────────────────────────────────────────────

/**
 * A signed access token; $claims override the defaults (exp, aud, iss ...), $key signs with another key.
 *
 * @param  list<string>  $roles
 * @param  array<string, mixed>  $claims
 * @param  array{kid: string, private_pem: string}|null  $key
 */
function iamToken(int $userId, array $roles = [Role::MEMBER], array $claims = [], string $typ = 'at+jwt', ?array $key = null): string
{
    $key ??= T::$key;
    $config = ['issuer' => TEST_ISSUER, 'client_id' => TEST_CLIENT];

    return anotoki_iam_test_sign(anotoki_iam_test_claims($config, $userId, $roles, $claims), $key['private_pem'], $key['kid'], $typ);
}
