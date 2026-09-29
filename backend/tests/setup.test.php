<?php

/**
 * The installer behind setup.php: what it accepts, the config files it writes (and reads back), where it
 * counts as "this computer", the setup token, the requirements, and the database and IAM checks.
 *
 * Each test works in a folder of its own that stands in for backend/ (config/, storage/), so no real
 * config file is read or written.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Config;
use App\Db\Db;
use App\Http\ConnectionFailed;
use App\Setup\Installer;
use Tests\Support\FakeHttpClient;

bootTests();

$server = require __DIR__.'/../config/database.local.php';

/** A stand-in for backend/: config/ (with the committed app.php) and storage/. */
function installRoot(): string
{
    $root = T::$storage.DIRECTORY_SEPARATOR.'install-'.bin2hex(random_bytes(4));
    @mkdir($root.'/config', 0777, true);
    @mkdir($root.'/storage', 0777, true);
    copy(__DIR__.'/../config/app.php', $root.'/config/app.php');

    return $root;
}

/** @return array<string, string> the form as a host's first install fills it */
function form(array $overrides = []): array
{
    return $overrides + [
        'db_host' => 'db.example.test', 'db_port' => '5432', 'db_database' => 'build_analyzer', 'db_username' => 'build_app',
        'mode' => 'iam', 'issuer' => 'https://iam.example.test/', 'client_id' => 'build-analyzer', 'local_user_id' => '7',
        'user_agent' => 'build-analyzer/1.0 (https://builds.example.test)',
        'delay_ms' => '1200', 'cache_hours' => '12', 'step_seconds' => '20', 'stale_hours' => '20',
    ];
}

test('every password reads back exactly as it was typed', function () {
    $installer = new Installer(installRoot());
    $passwords = ['plain', 'with space', 'dollar$HOME and ${KEY}', 'back\\slash', 'quote "double"', "single 'quote'", '#hash', '<?php ?>', 'ünïcödé', ''];
    foreach ($passwords as $password) {
        [$settings, $errors] = $installer->validate(form(), $password, false);
        same([], $errors, "no errors for {$password}");
        $installer->writeConfig($settings);

        $written = require $installer->root.'/config/database.local.php';
        same($password, $written['password'], "password: {$password}");
        same($password, $installer->savedPassword(), "saved password: {$password}");
    }
});

test('a host\'s install signs in through its IAM, and makes a set-up installation', function () {
    $installer = new Installer(installRoot());
    // "none" is not offered on a host: whatever the form says, sign-in stays on.
    [$settings, $errors] = $installer->validate(form(['mode' => 'none']), 's3cr3t', false);
    same([], $errors, 'errors');
    same(['mode' => 'iam', 'local_user_id' => 7, 'issuer' => 'https://iam.example.test', 'client_id' => 'build-analyzer'], $settings['auth'], 'auth');
    same(['user_agent' => 'build-analyzer/1.0 (https://builds.example.test)', 'delay_ms' => 1200, 'cache_hours' => 12, 'step_seconds' => 20, 'stale_hours' => 20],
        $settings['fetch'], 'fetch');

    same(['config/database.local.php', 'config/auth.local.php', 'config/fetch.local.php'], $installer->writeConfig($settings), 'files');
    $config = Config::load($installer->root);
    same([], $config->problems(), 'problems');
    same('iam', $config->get('auth.mode'), 'mode');
    same('db.example.test', $config->get('database.host'), 'database host');
    check(str_starts_with((string) file_get_contents($installer->root.'/config/auth.local.php'), "<?php\n\n// Written by setup.php"), 'a PHP file saying where it came from');
});

test('on this computer sign-in can be switched off', function () {
    $installer = new Installer(installRoot());
    [$settings, $errors] = $installer->validate(form(['mode' => 'none', 'issuer' => '', 'client_id' => '', 'local_user_id' => '1']), 'pw', true);
    same([], $errors, 'errors');
    same(['mode' => 'none', 'local_user_id' => 1, 'issuer' => 'http://localhost:4300', 'client_id' => 'build-analyzer'], $settings['auth'], 'auth');

    $installer->writeConfig($settings);
    same('none', Config::load($installer->root)->get('auth.mode'), 'mode');
});

test('it refuses what it cannot use', function () {
    $installer = new Installer(installRoot());
    $cases = [
        'a port that is not a number' => ['db_port' => '54a'],
        'no database' => ['db_database' => ''],
        'no username' => ['db_username' => ' '],
        'a line break in the host' => ['db_host' => "db\nhost"],
        'a client id with capitals' => ['client_id' => 'Build Analyzer'],
        'an IAM that is not a URL' => ['issuer' => 'iam'],
        'an IAM that is not on the web' => ['issuer' => 'ftp://iam.example.test'],
        'no owner' => ['local_user_id' => '0'],
        'an owner that is not a number' => ['local_user_id' => 'me'],
        'no user agent' => ['user_agent' => ''],
        'steps too short to finish a request' => ['step_seconds' => '5'],
        'a delay that is not a number' => ['delay_ms' => '1.5'],
    ];
    foreach ($cases as $case => $override) {
        [, $errors] = $installer->validate(form($override), 'p', false);
        check($errors !== [], "refused: {$case}");
    }

    [, $errors] = $installer->validate(form(), "two\nlines", false);
    check($errors !== [], 'a password with a line break');

    // On this computer with sign-in off, the IAM fields do not matter - the owner still does.
    [, $errors] = $installer->validate(form(['mode' => 'none', 'issuer' => 'nonsense', 'client_id' => 'No!']), 'p', true);
    same([], $errors, 'IAM fields are not checked when sign-in is off');
    [, $errors] = $installer->validate(form(['mode' => 'none', 'local_user_id' => '']), 'p', true);
    check($errors !== [], 'the owner is needed when sign-in is off');
});

test('the form starts from the saved values, never the password', function () {
    $installer = new Installer(installRoot());

    $fresh = $installer->defaults(true, 'http://localhost:8400');
    same(['127.0.0.1', '5432', 'build_analyzer', 'postgres', 'none', 'http://localhost:4300'],
        [$fresh['db_host'], $fresh['db_port'], $fresh['db_database'], $fresh['db_username'], $fresh['mode'], $fresh['issuer']], 'development values on this computer');
    $hosted = $installer->defaults(false, 'https://builds.example.com');
    same(['', 'iam', 'https://iam.example.com'], [$hosted['db_username'], $hosted['mode'], $hosted['issuer']], 'a host\'s values: its IAM beside it');

    [$settings] = $installer->validate(form(['db_database' => 'my_builds', 'delay_ms' => '2500']), 'hidden-pass', false);
    $installer->writeConfig($settings);
    $again = $installer->defaults(true, 'http://localhost:8400');
    same(['my_builds', 'build_app', '2500', 'https://iam.example.test'], [$again['db_database'], $again['db_username'], $again['delay_ms'], $again['issuer']], 'saved values');
    check(! in_array('hidden-pass', $again, true) && ! array_key_exists('db_password', $again), 'no password in the form');
});

test('"this computer" is a loopback client asking for a local name', function () {
    same(true, Installer::local(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8400']), 'localhost');
    same(true, Installer::local(['REMOTE_ADDR' => '::1', 'HTTP_HOST' => '[::1]:8400']), 'IPv6');
    same(true, Installer::local(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:4600']), 'through the dev proxy');
    same(false, Installer::local(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'attacker.example']), 'a name rebound to 127.0.0.1');
    same(false, Installer::local(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'localhost']), 'a remote client');
    same(false, Installer::local([]), 'nothing known');
});

test('the setup token is made once, read back, and removed with setup.php', function () {
    $installer = new Installer(installRoot());
    same(null, $installer->token(), 'no token yet');

    $token = $installer->makeToken();
    check(is_string($token) && strlen($token) === 48, 'a long random token');
    same($token, $installer->token(), 'read back');

    $self = $installer->root.'/setup.php';
    file_put_contents($self, '<?php // the installer');
    same(true, $installer->removeSetupFiles($self), 'setup.php is gone');
    check(! is_file($self) && ! is_file($installer->tokenFile()), 'both files removed');
});

test('the requirements name PHP 8.4, the extensions and the writable folders', function () {
    $installer = new Installer(installRoot());
    $checks = array_column($installer->requirements(), 'ok', 'label');

    foreach (['PHP 8.4 or newer', 'PHP extension pdo_pgsql', 'PHP extension openssl', 'PHP extension curl', 'PHP extension dom', 'config/ writable', 'storage/ writable'] as $label) {
        same(true, $checks[$label] ?? null, $label);
    }
    same(false, $checks['vendor/ uploaded'], 'no vendor/ in the stand-in folder');
});

test('the IAM check reads the OpenID configuration', function () {
    $http = new FakeHttpClient;
    $installer = new Installer(installRoot(), $http);
    $discovery = fn (string $issuer) => FakeHttpClient::json(['issuer' => $issuer, 'jwks_uri' => $issuer.'/.well-known/jwks.json']);

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
    $http->fake(['down.example.test/*' => fn () => throw new ConnectionFailed('Could not resolve host')]);
    $down = $installer->testIam('https://down.example.test');
    same(false, $down['ok'], 'no answer');
    check(str_contains($down['message'], 'No answer from https://down.example.test'), $down['message']);
    same(false, $installer->testIam('not a url')['ok'], 'not a URL, nothing sent');
});

test('the database check says what it found, and never repeats the password', function () use ($server) {
    $installer = new Installer(installRoot());

    $ours = $installer->testDatabase(['database' => T::$database] + $server);
    same(true, $ours['ok'], $ours['message']);
    check(str_contains($ours['message'], '2 migration(s) applied, 0 waiting'), $ours['message']);

    $missing = $installer->testDatabase(['database' => T::$database.'_missing'] + $server);
    same(true, $missing['ok'], $missing['message']);
    check(str_contains($missing['message'], 'does not exist yet'), $missing['message']);

    // A server that trusts local connections accepts any password; one that checks them says why it refused.
    $wrong = 'Wrong-Pass-'.bin2hex(random_bytes(4));
    $refused = $installer->testDatabase(['database' => T::$database, 'password' => $wrong] + $server);
    if (! $refused['ok']) {
        check(str_starts_with($refused['message'], 'Cannot connect: '), $refused['message']);
    }
    check(! str_contains($refused['message'], $wrong), 'the password is not in the message');
});

test('installing creates the database and builds it, and a second run finds it up to date', function () use ($server) {
    $installer = new Installer(installRoot());
    $database = ['database' => T::$database.'_install'] + $server;
    register_shutdown_function(static function () use ($server, $database): void {
        Db::pdo($server, 'postgres', false)->exec('DROP DATABASE IF EXISTS "'.$database['database'].'" WITH (FORCE)');
    });

    same(['Created the database "'.$database['database'].'".', 'Applied 2 migration(s).'], $installer->buildDatabase($database, 1), 'first run');
    same(['The database is up to date.'], $installer->buildDatabase($database, 1), 'second run');
    $pdo = Db::pdo($server, $database['database'], false);
    same(1, (int) $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_name = 'builds'")->fetchColumn(), 'the tables are there');
});

finishTests();
