<?php

/**
 * The installer behind setup.php: what it accepts (the database and the IAM, nothing else), the config files
 * it writes (and reads back), where it counts as "this computer", the setup token, the requirements, and
 * the database and IAM checks - "Test connection" in the words every anotoki setup page answers in.
 *
 * Each test works in a folder of its own that stands in for backend/ (config/, storage/), so no real
 * config file is read or written.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Config;
use App\Db\Db;
use App\Db\Migrations;
use App\Http\ConnectionFailed;
use App\Setup\Installer;
use Tests\Support\FakeHttpClient;

bootTests();

$server = require __DIR__ . '/../config/database.local.php';

/**
 * Every file a database is built from, as the bookkeeping table names them: the site's by their names, then
 * the anotoki library's own (its translations set) with their set in front.
 *
 * @return list<string>
 */
function everyFile(): array
{
    $migrator = Migrations::migrator(db()->raw());
    $files = [];
    foreach (Migrations::sets() as $set) {
        foreach ($migrator->files($set) as $name) {
            $files[] = ($set->name === Migrations::SET ? '' : $set->name . '/') . $name;
        }
    }

    return $files;
}

/** A stand-in for backend/: config/ (with the committed app.php) and storage/. */
function installRoot(): string
{
    $root = T::$storage . DIRECTORY_SEPARATOR . 'install-' . bin2hex(random_bytes(4));
    @mkdir($root . '/config', 0777, true);
    @mkdir($root . '/storage', 0777, true);
    copy(__DIR__ . '/../config/app.php', $root . '/config/app.php');

    return $root;
}

/** @return array<string, string> the form as a host's first install fills it */
function form(array $overrides = []): array
{
    return $overrides + [
        'db_host' => 'db.example.test',
        'db_port' => '5432',
        'db_database' => 'anotoki_builder',
        'db_username' => 'build_app',
        'issuer' => 'https://iam.example.test/',
        'client_id' => 'build-analyzer',
    ];
}

test('every password reads back exactly as it was typed', function () {
    $installer = new Installer(installRoot());
    $passwords = ['plain', 'with space', 'dollar$HOME and ${KEY}', 'back\\slash', 'quote "double"', "single 'quote'", '#hash', '<?php ?>', 'ünïcödé', ''];
    foreach ($passwords as $password) {
        [$settings, $errors] = $installer->validate(form(), $password);
        same([], $errors, "no errors for {$password}");
        $installer->writeConfig($settings);

        $written = require $installer->root . '/config/database.local.php';
        same($password, $written['password'], "password: {$password}");
        same($password, $installer->savedPassword(), "saved password: {$password}");
    }
});

test('it takes the database and the IAM, and nothing else: no sign-in switch, no owner, no fetch settings', function () {
    $installer = new Installer(installRoot());
    // What an older page posted beside them is not read.
    [$settings, $errors] = $installer->validate(form(['mode' => 'none', 'local_user_id' => '7', 'user_agent' => 'x', 'delay_ms' => '1', 'step_seconds' => '5']), 's3cr3t');
    same([], $errors, 'errors');
    same(['database', 'auth'], array_keys($settings), 'two files\' worth');
    same(['issuer' => 'https://iam.example.test', 'client_id' => 'build-analyzer'], $settings['auth'], 'auth: where people sign in, only');

    same(['config/database.local.php', 'config/auth.local.php', 'config/fetch.local.php'], $installer->writeConfig($settings, Installer::userAgent(false, 'https://builds.example.test')), 'files');
    $config = Config::load($installer->root);
    same([], $config->problems(), 'problems');
    same(null, $config->get('auth.mode'), 'no mode');
    same(null, $config->get('auth.local_user_id'), 'no owner');
    same('db.example.test', $config->get('database.host'), 'database host');
    same(['build-analyzer/1.0 (https://builds.example.test)', 1200], [$config->get('fetch.user_agent'), $config->get('fetch.delay_ms')], 'fetch.local.php: the defaults, with the site\'s address');
    same(['user_agent', 'delay_ms'], array_keys(require $installer->root . '/config/fetch.local.php'), 'and nothing else in it');
    check(str_starts_with((string) file_get_contents($installer->root . '/config/auth.local.php'), "<?php\n\n// Written by setup.php"), 'a PHP file saying where it came from');
    same('build-analyzer/1.0 (personal build analyzer)', Installer::userAgent(true, 'http://localhost:4600'), 'on this computer');
});

test('fetch.local.php is written only when there is none: one edited by hand is kept', function () {
    $installer = new Installer(installRoot());
    [$settings] = $installer->validate(form(), 'pw');
    $installer->writeConfig($settings);
    $edited = "<?php return ['user_agent' => 'build-analyzer/1.0 (mine)', 'delay_ms' => 5000];\n";
    file_put_contents($installer->root . '/config/fetch.local.php', $edited);

    same(['config/database.local.php', 'config/auth.local.php'], $installer->writeConfig($settings), 'only the two');
    same($edited, file_get_contents($installer->root . '/config/fetch.local.php'), 'kept as it was');
    same(5000, Config::load($installer->root)->get('fetch.delay_ms'), 'and read');
});

test('cloudflare.local.php - the origin secret - is the owner\'s alone: the installer neither writes nor reads it, and one put there by hand is kept', function () {
    $installer = new Installer(installRoot());
    $secret = 'origin-' . bin2hex(random_bytes(20));
    $mine = "<?php return ['origin_secret' => '{$secret}'];\n";
    file_put_contents($installer->root . '/config/cloudflare.local.php', $mine);
    [$settings] = $installer->validate(form(), 'pw');

    same(['config/database.local.php', 'config/auth.local.php', 'config/fetch.local.php'], $installer->writeConfig($settings), 'its own three, and no other');
    same(['config/database.local.php', 'config/auth.local.php'], $installer->writeConfig($settings), 'again: its own two');
    same($mine, file_get_contents($installer->root . '/config/cloudflare.local.php'), 'the origin secret kept as it was');
    $config = Config::load($installer->root);
    same([[], $secret], [$config->problems(), $config->originSecret()], 'and read, the site set up');
});

test('it refuses what it cannot use', function () {
    $installer = new Installer(installRoot());
    $cases = [
        'a port that is not a number' => ['db_port' => '54a'],
        'no database' => ['db_database' => ''],
        'no username' => ['db_username' => ' '],
        'a line break in the host' => ['db_host' => "db\nhost"],
        'a client id with capitals' => ['client_id' => 'Build Analyzer'],
        'no client id' => ['client_id' => ''],
        'an IAM that is not a URL' => ['issuer' => 'iam'],
        'an IAM that is not on the web' => ['issuer' => 'ftp://iam.example.test'],
        'no IAM' => ['issuer' => ''],
    ];
    foreach ($cases as $case => $override) {
        [, $errors] = $installer->validate(form($override), 'p');
        check($errors !== [], "refused: {$case}");
    }

    [, $errors] = $installer->validate(form(), "two\nlines");
    check($errors !== [], 'a password with a line break');
    // An older page's "sign-in off" does not let an IAM go unnamed.
    [, $errors] = $installer->validate(form(['mode' => 'none', 'issuer' => 'nonsense', 'client_id' => 'No!']), 'p');
    same(2, count($errors), 'the IAM is always needed');
});

test('the form starts from the family\'s defaults; the saved values are shown on this computer only, never the password', function () {
    $installer = new Installer(installRoot());

    $fresh = $installer->defaults(true, 'http://localhost:8400');
    same(
        ['db_host' => '127.0.0.1', 'db_port' => '5432', 'db_database' => 'anotoki_builder', 'db_username' => 'postgres', 'issuer' => 'http://localhost:4300', 'client_id' => 'build-analyzer'],
        $fresh,
        'development values on this computer - and nothing else is asked',
    );
    $hosted = $installer->defaults(false, 'https://builds.example.com');
    same(
        ['127.0.0.1', '5432', 'anotoki_builder', '', 'https://iam.example.com'],
        [$hosted['db_host'], $hosted['db_port'], $hosted['db_database'], $hosted['db_username'], $hosted['issuer']],
        'a host\'s values: its IAM beside it',
    );

    [$settings] = $installer->validate(form(['db_database' => 'my_builds', 'db_port' => '5433']), 'hidden-pass');
    $installer->writeConfig($settings);
    $again = $installer->defaults(true, 'http://localhost:8400');
    same(['my_builds', '5433', 'build_app', 'https://iam.example.test'], [$again['db_database'], $again['db_port'], $again['db_username'], $again['issuer']], 'saved values, on this computer');
    check(!in_array('hidden-pass', $again, true) && !array_key_exists('db_password', $again), 'no password in the form');

    $host = $installer->defaults(false, 'https://builds.example.com');
    same(['anotoki_builder', '5432', '', 'https://iam.example.com'], [$host['db_database'], $host['db_port'], $host['db_username'], $host['issuer']], 'a host shows nothing it holds');
});

test('"this computer" is a loopback client asking for a local name', function () {
    same(true, Installer::local(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8400']), 'localhost');
    same(true, Installer::local(['REMOTE_ADDR' => '::1', 'HTTP_HOST' => '[::1]:8400']), 'IPv6');
    same(true, Installer::local(['REMOTE_ADDR' => '::ffff:127.0.0.1', 'HTTP_HOST' => 'app.localhost']), 'IPv4-mapped, a .localhost name');
    same(true, Installer::local(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:4600']), 'through the dev proxy');
    same(false, Installer::local(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'attacker.example']), 'a name rebound to 127.0.0.1');
    same(false, Installer::local(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.elsewhere.example']), 'a name that only looks local');
    same(false, Installer::local(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'localhost']), 'a remote client');
    same(false, Installer::local([]), 'nothing known');
    same(['localhost', '::1', 'builds.example.test'], [Installer::hostOf('LocalHost:4600'), Installer::hostOf('[::1]:8400'), Installer::hostOf('builds.example.test')], 'hosts without ports');
});

test('the setup token is made once, read back, and removed with setup.php', function () {
    $installer = new Installer(installRoot());
    same(null, $installer->token(), 'no token yet');

    $token = $installer->makeToken();
    check(is_string($token) && strlen($token) === 48, 'a long random token');
    same($token, $installer->token(), 'read back');

    $self = $installer->root . '/setup.php';
    file_put_contents($self, '<?php // the installer');
    same(true, $installer->removeSetupFiles($self), 'setup.php is gone');
    check(!is_file($self) && !is_file($installer->tokenFile()), 'both files removed');
});

test('the requirements name PHP 8.4, the extensions and the writable folders', function () {
    $installer = new Installer(installRoot());
    $checks = array_column($installer->requirements(), 'ok', 'label');

    foreach (
        [
            'PHP 8.4 or newer',
            'PHP extension pdo_pgsql',
            'PHP extension openssl',
            'PHP extension mbstring',
            'PHP extension curl',
            'PHP extension dom',
            'PHP extension zlib',
            'config/ writable',
            'storage/ writable',
        ]
        as $label
    ) {
        same(true, $checks[$label] ?? null, $label);
    }
    // zlib: the backups are compressed with it (anotoki-lib's Backups) - a host without it could make none.
    $zlib = array_values(array_filter($installer->requirements(), fn(array $check): bool => $check['label'] === 'PHP extension zlib'));
    check(str_contains((string) ($zlib[0]['detail'] ?? ''), 'backups'), 'zlib says what it is for: ' . json_encode($zlib));
    same(false, $checks['vendor/ uploaded'], 'no vendor/ in the stand-in folder');
});

test('the IAM check reads the OpenID configuration', function () {
    $http = new FakeHttpClient();
    $installer = new Installer(installRoot(), $http);
    $discovery = fn(string $issuer) => FakeHttpClient::json(['issuer' => $issuer, 'jwks_uri' => $issuer . '/.well-known/jwks.json']);

    $http->fake(['iam.example.test/.well-known/openid-configuration' => $discovery('https://iam.example.test')]);
    $ok = $installer->testIam('https://iam.example.test/');
    same(true, $ok['ok'], $ok['message']);
    same(['https://iam.example.test/.well-known/openid-configuration'], $http->sent, 'the address asked');

    $http->fake(['other.example.test/*' => $discovery('https://iam.example.test')]);
    $moved = $installer->testIam('https://other.example.test');
    same(false, $moved['ok'], 'another issuer');
    check(str_contains($moved['message'], 'calls itself https://iam.example.test'), $moved['message']);

    $http->fake(['missing.example.test/*' => FakeHttpClient::status(404)]);
    same(false, $installer->testIam('https://missing.example.test')['ok'], 'a 404');
    $http->fake(['page.example.test/*' => '<html>not json</html>']);
    same(false, $installer->testIam('https://page.example.test')['ok'], 'not a configuration');
    $http->fake(['down.example.test/*' => fn() => throw new ConnectionFailed('Could not resolve host')]);
    $down = $installer->testIam('https://down.example.test');
    same(false, $down['ok'], 'no answer');
    check(str_contains($down['message'], 'No answer from https://down.example.test'), $down['message']);
    same(false, $installer->testIam('not a url')['ok'], 'not a URL, nothing sent');
});

test('"Test connection" answers in the family\'s words: up to date, empty, updates to apply, not there yet', function () use ($server) {
    $installer = new Installer(installRoot());

    same(
        ['ok' => true, 'message' => 'Connected to the database "' . T::$database . '": it is up to date.'],
        $installer->testDatabase(['database' => T::$database] + $server),
        'a database with every migration',
    );

    $emptyName = T::$database . '_empty';
    $empty = scratchDatabase($server, 'empty');
    same(
        ['ok' => true, 'message' => "Connected to the database \"{$emptyName}\": it is empty - Save makes the tables."],
        $installer->testDatabase(['database' => $emptyName] + $server),
        'an empty database',
    );

    // The first file applied (from a folder holding it alone): the others wait - the library's own too.
    $files = Migrations::migrator($empty)->files(Migrations::set());
    $first = T::$storage . DIRECTORY_SEPARATOR . 'first-migration-only';
    @mkdir($first, 0777, true);
    copy(Migrations::directory() . '/' . $files[0], $first . '/' . $files[0]);
    same(null, Migrations::migrator($empty, $first, library: false)->apply()['error'], 'the first file applied');
    same(
        ['ok' => true, 'message' => "Connected to the database \"{$emptyName}\": " . (count(everyFile()) - 1) . ' update(s) to apply - Save applies them.'],
        $installer->testDatabase(['database' => $emptyName] + $server),
        'a database with updates to apply',
    );
    $empty = null;

    $mayCreate = (bool) Db::pdo($server, 'postgres', false)->query('SELECT rolcreatedb OR rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn();
    $missingName = T::$database . '_missing';
    $missing = $installer->testDatabase(['database' => $missingName] + $server);
    same(
        $mayCreate
            ? ['ok' => true, 'message' => "Connected to the server. The database \"{$missingName}\" does not exist yet - Save creates it."]
            : ['ok' => false, 'message' => "Connected to the server, but there is no database \"{$missingName}\" and this user may not create one. Make it in the hosting panel first."],
        $missing,
        'a database not there yet',
    );
    same(
        0,
        (int) Db::pdo($server, 'postgres', false)
            ->query("SELECT count(*) FROM pg_database WHERE datname = '{$missingName}'")
            ->fetchColumn(),
        'testing made nothing',
    );

    // A server that trusts local connections accepts any password; one that checks them says why it refused.
    $wrong = 'Wrong-Pass-' . bin2hex(random_bytes(4));
    $refused = $installer->testDatabase(['database' => T::$database, 'username' => 'build_analyzer_test_nobody', 'password' => $wrong] + $server);
    same(false, $refused['ok'], $refused['message']);
    check(str_starts_with($refused['message'], 'Cannot connect: '), 'said as the family says it: ' . $refused['message']);
    check(!str_contains($refused['message'], $wrong), 'the password is not in the message');
    check(!str_contains($refused['message'], 'SQLSTATE'), 'without the driver\'s noise: ' . $refused['message']);
});

test('"Test connection" for a login that may not create a database, and for a database the login may not open', function () use ($server) {
    $admin = Db::pdo($server, 'postgres', false);
    if (!(bool) $admin->query('SELECT rolcreaterole OR rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn()) {
        echo "      (skipped: the test login may not make the throwaway login these answers need)\n";

        return;
    }

    // A throwaway login of this run's own, with no right to make databases, and a throwaway database nobody
    // but its owner may open. Both go at the end of the run.
    $login = T::$database . '_nocreate';
    $password = bin2hex(random_bytes(16));
    $admin->exec('CREATE ROLE "' . $login . '" LOGIN NOCREATEDB NOSUPERUSER NOCREATEROLE PASSWORD ' . $admin->quote($password));
    register_shutdown_function(static function () use ($server, $login): void {
        try {
            Db::pdo($server, 'postgres', false)->exec('DROP ROLE IF EXISTS "' . $login . '"');
        } catch (Throwable $e) {
            fwrite(STDERR, "could not drop the login {$login}: {$e->getMessage()}\n");
        }
    });
    $closed = T::$database . '_closed';
    scratchDatabase($server, 'closed');
    $admin->exec('REVOKE CONNECT ON DATABASE "' . $closed . '" FROM PUBLIC');

    $installer = new Installer(installRoot());
    $as = fn(string $name): array => $installer->testDatabase(['database' => $name, 'username' => $login, 'password' => $password] + $server);

    $never = T::$database . '_never';
    same(
        ['ok' => false, 'message' => "Connected to the server, but there is no database \"{$never}\" and this user may not create one. Make it in the hosting panel first."],
        $as($never),
        'a database not there, and a login that may not make it',
    );

    $shut = $as($closed);
    $opening = "The server is there, but \"{$login}\" cannot open the database \"{$closed}\": ";
    same(false, $shut['ok'], 'a database the login may not open: ' . $shut['message']);
    check(str_starts_with($shut['message'], $opening), 'said as the family says it: ' . $shut['message']);
    check(strlen($shut['message']) > strlen($opening), 'with the server\'s reason');
    check(!str_contains($shut['message'], $password), 'no answer repeats the login\'s password');
});

test('installing creates the database and builds it, and a second run finds it up to date', function () use ($server) {
    $installer = new Installer(installRoot());
    $database = ['database' => T::$database . '_install'] + $server;
    register_shutdown_function(static function () use ($server, $database): void {
        Db::pdo($server, 'postgres', false)->exec('DROP DATABASE IF EXISTS "' . $database['database'] . '" WITH (FORCE)');
    });

    $files = everyFile();
    same(['Created the database "' . $database['database'] . '".', 'Applied ' . count($files) . ' migration(s).'], $installer->buildDatabase($database), 'first run');
    same(['The database is up to date.'], $installer->buildDatabase($database), 'second run');
    $pdo = Db::pdo($server, $database['database'], false);
    same(1, (int) $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_name = 'builds'")->fetchColumn(), 'the tables are there');
    same($files, $pdo->query('SELECT filename FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'every file recorded, in the order it ran');
});

test('installing while another update is applied to the database applies nothing, and says so', function () use ($server) {
    $installer = new Installer(installRoot());
    $database = ['database' => T::$database . '_busy'] + $server;
    scratchDatabase($server, 'busy');
    // Another apply under way: the Migrations page, or migration.php, holds the lock every apply takes.
    $other = Db::pdo($server, $database['database'], false);
    $other->query('SELECT pg_advisory_lock(' . Migrations::LOCK . ')');
    try {
        $refused = throws(RuntimeException::class, fn() => $installer->buildDatabase($database), 'refused');
        same(
            'Another update is being applied to this database right now (the Migrations page or migration.php). Nothing was applied: wait a moment, then save again.',
            $refused->getMessage(),
            'what it says',
        );
        same(false, (bool) Db::pdo($server, $database['database'], false)->query("SELECT to_regclass('public.migrations') IS NOT NULL")->fetchColumn(), 'nothing made');
    } finally {
        $other->query('SELECT pg_advisory_unlock(' . Migrations::LOCK . ')');
    }
    same('Applied ' . count(everyFile()) . ' migration(s).', $installer->buildDatabase($database)[0], 'and once it is free, the whole database - the library\'s files too');
});

finishTests();
