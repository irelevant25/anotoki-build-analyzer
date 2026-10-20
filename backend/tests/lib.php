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
 * test on any request it was not told to answer, and the IAM is the anotoki library's test IAM (T::$iam,
 * Anotoki\Lib\Auth\Testing\TestIam) - a key pair of its own, the tokens it signs, and the files the site's
 * IamClient reads in place of asking an IAM: its key set, its answer about whether this site requires
 * signing in (false at the start of each test: iamRequiresSignIn() changes it) and the accounts it deleted.
 * tests/stand_in_iam.test.php signs in through the library's stand-in IAM itself.
 */

declare(strict_types=1);

define('BUILD_ANALYZER_NO_RUN', true);
require __DIR__ . '/../api/index.php';
foreach (glob(__DIR__ . '/support/*.php') ?: [] as $support) {
    require_once $support;
}

use Anotoki\Lib\Auth\Testing\TestIam;
use App\App;
use App\Config;
use App\Db\Db;
use App\Db\Migrations;
use App\Http\HttpClient;
use App\Support\Clock;
use App\Support\Neutral;
use Tests\Support\FakeHttpClient;

// The owner's config/neutral.local.php (names of other sites) never changes a test's result: a test that needs one
// sets its own with Neutral::setLocalNames().
Neutral::setLocalNames([]);

const TEST_ISSUER = 'https://iam.example.test';
const TEST_CLIENT = 'build-analyzer';

final class T
{
    public static App $app;

    public static FakeHttpClient $http;

    /** The IAM of the tests: its key, its tokens, its answers (Anotoki\Lib\Auth\Testing\TestIam). */
    public static TestIam $iam;

    public static Slim\App $api;

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
    $local = __DIR__ . '/../config/database.local.php';
    if (!is_file($local)) {
        fwrite(STDERR, "The tests need the PostgreSQL server of config/database.local.php. Run setup.php first (see DEV.md).\n");
        exit(1);
    }
    $server = require $local;

    $suffix = getmypid() . '_' . bin2hex(random_bytes(3));
    T::$database = 'build_analyzer_test_' . $suffix;
    T::$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'build-analyzer-test-' . $suffix;
    @mkdir(T::$storage, 0777, true);

    // Before the database is made: a migration that fails below ends the file, and its database must go too.
    register_shutdown_function(static function () use ($server): void {
        try {
            $postgres = Db::pdo($server, 'postgres', false);
            $postgres->exec('DROP DATABASE IF EXISTS "' . T::$database . '" WITH (FORCE)');
        } catch (Throwable $e) {
            fwrite(STDERR, 'could not drop ' . T::$database . ": {$e->getMessage()}\n");
        }
        removeTree(T::$storage);
    });

    Db::ensureDatabase($server, T::$database);
    $pdo = Db::pdo($server, T::$database, false);
    $result = Migrations::migrator($pdo)->apply();
    if ($result['busy'] || $result['error'] !== null) {
        fwrite(STDERR, $result['busy'] ? "the migrations' lock is held: nothing was applied\n" : 'migration ' . ($result['failed']['name'] ?? '') . " failed: {$result['error']}\n");
        exit(1);
    }

    T::$iam = TestIam::create(T::$storage . DIRECTORY_SEPARATOR . 'iam', TEST_ISSUER, TEST_CLIENT, 'test-key');
    // The anotoki library's own lines go where the site's go, as api/index.php sends them: nowhere, unless a test
    // points App\Support\Log at a file of its own.
    \Anotoki\Lib\Support\Log::to(static fn(string $line) => \App\Support\Log::info($line));

    $config = testConfig(['database' => ['database' => T::$database, 'persistent' => false] + $server]);
    T::$app = new App($config, web: true);
    T::$app->set(Db::class, new Db($pdo));
    T::$http = new FakeHttpClient();
    T::$app->set(HttpClient::class, T::$http);
    T::$api = buildAnalyzerApi(T::$app);

    // Every test starts from empty tables, this configuration, no faked answers, nothing cached, a site the IAM
    // does not require sign-in for, an IAM that deleted nobody, and no names added to Neutral's.
    $snapshot = $config->snapshot();
    beforeEach(function () use ($snapshot): void {
        resetDatabase();
        T::$app->config->restore($snapshot);
        T::$http->reset();
        iamRequiresSignIn(false);
        T::$iam->anonymized([]);
        Neutral::setLocalNames([]);
        T::$app->resetScope();
        removeTree(T::$storage . DIRECTORY_SEPARATOR . 'http-cache');
        removeTree(T::$storage . DIRECTORY_SEPARATOR . 'cache');
    });
}

/**
 * What the IAM says about this site from now on (its public answer about the application, read through the
 * file the test IAM keeps - every time, nothing remembered): whether everybody must sign in here; null is an
 * IAM that does not answer at all (the site then fails closed: required).
 */
function iamRequiresSignIn(?bool $required): void
{
    T::$iam->signInRule($required);
}

/**
 * The configuration of a hosted install, with $values replacing sections. Storage is the test's temp folder.
 *
 * @param  array<string, mixed>  $values
 */
function testConfig(array $values = []): Config
{
    $iam = T::$iam->dir;

    return Config::fromArray(
        $values + [
            'database' => [],
            'auth' => [
                'issuer' => TEST_ISSUER,
                'client_id' => TEST_CLIENT,
                'jwks_file' => $iam . '/' . TestIam::JWKS_FILE,
                'application_file' => $iam . '/' . TestIam::APPLICATION_FILE,
                'anonymized_file' => $iam . '/' . TestIam::ANONYMIZED_FILE,
            ],
            'fetch' => ['user_agent' => 'build-analyzer-tests', 'delay_ms' => 0],
            // The upkeep after an answer (the people's scrub, the day's backup, the day's tidying up): the tests that mean
            // to try it switch it on.
            'upkeep' => ['people' => false, 'daily_backup' => false, 'prune' => false],
        ],
        (string) realpath(__DIR__ . '/..'),
        T::$storage,
    );
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
        if (!($e instanceof AssertionFailed)) {
            echo '      at ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    } finally {
        Clock::reset();
    }
}

final class AssertionFailed extends RuntimeException {}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed($message . ' - expected ' . json_encode($expected) . ', got ' . json_encode($actual));
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
        throw new AssertionFailed("{$message} - expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionFailed("{$message} - expected {$class}, nothing was thrown");
}

function finishTests(): never
{
    $failures = T::$failures;
    echo "\n" . T::$count . ' tests, ' . ($failures === 0 ? 'all passed' : "{$failures} failed") . "\n";
    exit($failures === 0 ? 0 : 1);
}

// ── The database ─────────────────────────────────────────────────────────────────────────────────

function db(): Db
{
    return T::$app->db();
}

/**
 * Every table empty again (what a test wrote goes; the schema stays) - the anotoki library's too: the audit
 * log, feedback, and where the people's scrub stands. The languages and the site's strings are not among
 * them: they are what the migrations left, and no table here points at them.
 */
function resetDatabase(): void
{
    // The tables that come and go with a file where they are there - the library's, the builds' imports (016), the
    // build sources' and syncs' (gone with 016): a test's world of its own may be a database before or after a file.
    $there = static fn(string $table): bool => (bool) db()->value('SELECT to_regclass(?) IS NOT NULL', [$table]);
    $some = array_values(array_filter(['anotoki_audit_log', 'anotoki_feedback', 'anotoki_people_anonymized', 'build_imports', 'build_sources', 'sync_runs', 'cache_locks'], $there));
    db()->run(
        'TRUNCATE users, game_characters, game_weapons, game_sets, accounts, import_runs, account_characters,
        account_weapons, account_items, builds, cache' .
            ($some === [] ? '' : ', ' . implode(', ', $some)) .
            ' RESTART IDENTITY CASCADE',
    );
    // Where the people's scrub stands is one row its migration made: back to never run.
    if ($there('anotoki_people_sync')) {
        db()->run('UPDATE anotoki_people_sync SET since = NULL, checked_at = NULL, ok_at = NULL, error = NULL, scrubbed = 0');
    }
}

/**
 * Another throwaway database of this file's own, empty: <the file's database>_<suffix>; dropped at the end.
 * $options are CREATE DATABASE's own, after the name - a collation of the test's choosing (tests/migrations.test.php);
 * literals of the test's, never input.
 *
 * @param  array<string, mixed>  $server  config/database.local.php
 */
function scratchDatabase(array $server, string $suffix, string $options = ''): PDO
{
    $name = T::$database . '_' . $suffix;
    $postgres = Db::pdo($server, 'postgres', false);
    $postgres->exec('DROP DATABASE IF EXISTS "' . $name . '" WITH (FORCE)');
    $postgres->exec('CREATE DATABASE "' . $name . '"' . ($options === '' ? '' : ' ' . $options));
    register_shutdown_function(static function () use ($server, $name): void {
        try {
            Db::pdo($server, 'postgres', false)->exec('DROP DATABASE IF EXISTS "' . $name . '" WITH (FORCE)');
        } catch (Throwable $e) {
            fwrite(STDERR, "could not drop {$name}: {$e->getMessage()}\n");
        }
    });

    return Db::pdo($server, $name, false);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

// ── Tokens ───────────────────────────────────────────────────────────────────────────────────────

/**
 * A signed access token of the test IAM's (the IAM's claims: this issuer, this client, ten minutes, a session,
 * signed in now, the profile - preferred_username "user{id}"); $claims override them (exp, aud, iss ... null
 * takes one out), $typ is the header's, and $iam signs with another key.
 *
 * @param  list<string>  $roles
 * @param  array<string, mixed>  $claims
 */
function iamToken(int $userId, array $roles = [], array $claims = [], string $typ = 'at+jwt', ?TestIam $iam = null): string
{
    $iam ??= T::$iam;

    return $iam->sign($iam->claims($userId, $roles, $claims), $typ);
}

/** Another IAM's key under the same kid as the tests' IAM's: a token it signs is nobody's here. */
function strangerIam(): TestIam
{
    return TestIam::create(T::$storage . DIRECTORY_SEPARATOR . 'stranger-iam', TEST_ISSUER, TEST_CLIENT, 'test-key');
}
