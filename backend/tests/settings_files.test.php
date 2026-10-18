<?php

/**
 * Every hand-written settings file (config/*.local.php) is read cleanly or not at all - App\Config::readLocalFile(),
 * the anotoki family's rule of 2026-10-13 and its first five bytes since 2026-10-18 (docs/decisions.md):
 *
 * - a file that does not start with <?php (a byte-order mark, a blank line before it, no <?php line) is never
 *   compiled - with declare(strict_types=1) after such a start PHP could not even compile it, a fatal error no
 *   handler catches: every request a 500 -, and whatever a file prints is caught and never sent;
 * - the database's or the IAM's file passed over leaves the site not set up - never a 500, nothing of it in any
 *   answer, the status included -; the fetch settings fall back to their defaults; the proxies' file trusts no
 *   proxy, never Cloudflare's ranges in its place; the origin secret's fails closed: a secret no request carries,
 *   so a forged CF-Connecting-IP from a Cloudflare address is the edge it came from, never the visitor it names;
 * - a file passed over is said in PHP's error log by its fault and byte count - once a day -, never by its text;
 * - setup reads its files the same way, and shows none of a broken one.
 *
 * Each root here stands in for backend/ (config/ with the committed app.php, storage/): no real settings file is
 * read or written.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Admin\AdminTools;
use App\App;
use App\Config;
use App\Services\Import\Enka\EnkaStore;
use App\Setup\Installer;
use App\Web\ClientAddress;
use Tests\Support\Client;

bootTests();

$server = require __DIR__.'/../config/database.local.php';

// The visitors' imports through the API (the origin secret's test), as tests/visitors.test.php makes them.
beforeEach(function (): void {
    seedGenshinCatalog();
    T::$app->set(EnkaStore::class, new class extends EnkaStore
    {
        public function __construct() {}

        public function genshinAvatar(string $key): ?array
        {
            return ['SkillOrder' => [10461, 10462, 10463], 'ProudMap' => []];
        }
    });
});

const CLOUDFLARE_EDGE = '173.245.48.10';
const ELSEWHERE = '203.0.113.9';
const FORGED = '198.51.100.7';
const BOM = "\xEF\xBB\xBF";

/** The two starts PHP cannot compile before declare(strict_types=1), and the other ways a file does not start with <?php. */
const BAD_STARTS = [
    'a byte-order mark before <?php declare(strict_types=1)' => [BOM, "<?php declare(strict_types=1);\n"],
    'a blank line before <?php declare(strict_types=1)' => ["\n", "<?php declare(strict_types=1);\n"],
    'a byte-order mark before <?php' => [BOM, "<?php\n"],
    'no <?php line' => ['', ''],
];

/** A stand-in for backend/: config/ with the committed app.php and $files, and storage/. */
function settingsRoot(array $files): string
{
    $root = T::$storage.DIRECTORY_SEPARATOR.'settings-'.bin2hex(random_bytes(4));
    @mkdir($root.'/config', 0777, true);
    @mkdir($root.'/storage', 0777, true);
    copy(__DIR__.'/../config/app.php', $root.'/config/app.php');
    foreach ($files as $name => $contents) {
        file_put_contents($root.'/config/'.$name, $contents);
    }

    return $root;
}

/** A file's body as PHP returns it: "return [...];". */
function returning(array $values): string
{
    return 'return '.var_export($values, true).";\n";
}

/** The two files of a set-up installation, on this file's throwaway database (the IAM's address is a test one). */
function setUpFiles(array $server): array
{
    return [
        'database.local.php' => '<?php '.returning(['database' => T::$database, 'persistent' => false] + $server),
        'auth.local.php' => '<?php '.returning(['issuer' => TEST_ISSUER, 'client_id' => TEST_CLIENT]),
    ];
}

/** $body's result, what it printed, and what it said in PHP's error log - each said afresh (no day's stamp kept). */
function watched(string $root, callable $body): array
{
    array_map('unlink', glob($root.'/storage/cache/config-said-*.stamp') ?: []);
    $log = $root.'/php-errors-'.bin2hex(random_bytes(3)).'.log';
    $before = ini_get('error_log');
    ini_set('error_log', $log);
    ob_start();
    try {
        $result = $body();
    } finally {
        $printed = (string) ob_get_clean();
        ini_set('error_log', $before === false ? '' : $before);
    }

    return [$result, $printed, (string) @file_get_contents($log)];
}

/** A request as the site would see it, from $connection, with $headers. */
function requestFrom(string $connection, array $headers = []): Psr\Http\Message\ServerRequestInterface
{
    $request = (new Slim\Psr7\Factory\ServerRequestFactory)->createServerRequest('POST', 'http://localhost/api/imports/file', ['REMOTE_ADDR' => $connection]);
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }

    return $request;
}

/** One request to an app of the test's own: [status, body, every header's value in one line, what it printed]. */
function askApp(Slim\App $api, string $method, string $path): array
{
    $request = (new Slim\Psr7\Factory\ServerRequestFactory)->createServerRequest($method, 'http://localhost'.$path, ['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('Host', 'localhost')->withHeader('Accept', 'application/json');
    ob_start();
    try {
        $response = $api->handle($request);
    } finally {
        $printed = (string) ob_get_clean();
    }
    $headers = implode("\n", array_map(fn (array $values): string => implode(', ', $values), $response->getHeaders()));

    return [$response->getStatusCode(), (string) $response->getBody(), $headers, $printed];
}

test('readLocalFile() at the edges of its first five bytes: <?PHP is PHP; an empty, a four-byte or a short-tag file is passed over uncompiled; one PHP cannot open is said before any require', function () {
    $folder = T::$storage.DIRECTORY_SEPARATOR.'edges-'.bin2hex(random_bytes(3));
    @mkdir($folder, 0777, true);
    $level = ob_get_level();
    $write = function (string $name, string $contents) use ($folder): string {
        file_put_contents("{$folder}/{$name}", $contents);

        return "{$folder}/{$name}";
    };
    same(['value' => ['a' => 1], 'problem' => null], Config::readLocalFile($write('upper.php', "<?PHP return ['a' => 1];\n")), '<?PHP, as PHP reads it');
    same(['value' => ['a' => 1], 'problem' => null], Config::readLocalFile($write('mixed.php', "<?pHp return ['a' => 1];\n")), '<?pHp too');
    foreach (['empty' => '', 'four bytes' => '<?ph', 'a short tag' => "<?= 1;\n"] as $what => $contents) {
        same(['value' => null, 'problem' => Config::NOT_PHP], Config::readLocalFile($write(str_replace(' ', '-', $what).'.php', $contents)), "{$what}: no file");
    }
    same(['value' => null, 'problem' => 'could not be read (PHP may not open it)'], Config::readLocalFile("{$folder}/missing.php"), 'a file that is not there');
    // A folder in a file's place: PHP may not open it (Windows), or reads nothing from it (elsewhere) - no file either way.
    $read = Config::readLocalFile($folder);
    check($read['value'] === null && in_array($read['problem'], ['could not be read (PHP may not open it)', Config::NOT_PHP], true), 'a folder: no file - '.json_encode($read));
    // Read cleanly - or not at all.
    same(['value' => null, 'problem' => 'could not be read (ParseError on line 1)'], Config::readLocalFile($write('parse.php', "<?php return ['a' => 'x' 'y'];\n")), 'one PHP cannot read: by the kind of error and its line');
    same(['value' => null, 'problem' => 'could not be read (Error on line 2)'], Config::readLocalFile($write('constant.php', "<?php\nreturn ['a' => no_such_constant];\n")), 'one PHP cannot run');
    same(['value' => null, 'problem' => 'prints 6 bytes of its own (text outside its PHP: text after a closing tag, or something it echoes)'],
        Config::readLocalFile($write('echo.php', "<?php echo 'hello!'; return ['a' => 1];\n")), 'one that prints');
    same(['value' => null, 'problem' => 'prints 4 bytes of its own (text outside its PHP: text after a closing tag, or something it echoes)'],
        Config::readLocalFile($write('buffer.php', "<?php ob_start(); echo 'left'; return ['a' => 1];\n")), 'a buffer of its own left open: caught too');
    same($level, ob_get_level(), 'the caller\'s buffers as they were');
    removeTree($folder);
});

test('PHP itself: a byte-order mark or a blank line before <?php declare(strict_types=1) is a fatal error no handler catches (this is why the first five bytes are read first)', function () {
    $folder = T::$storage.DIRECTORY_SEPARATOR.'fatal-'.bin2hex(random_bytes(3));
    @mkdir($folder, 0777, true);
    foreach (['a byte-order mark' => BOM, 'a blank line' => "\n"] as $what => $before) {
        $file = "{$folder}/settings.php";
        file_put_contents($file, $before."<?php declare(strict_types=1);\nreturn ['password' => 'not-printed'];\n");
        $script = "{$folder}/require.php";
        file_put_contents($script, "<?php\ntry { ob_start(); require ".var_export($file, true)."; } catch (Throwable \$e) { echo 'caught'; }\n");
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        $code = proc_close($process);
        check($code === 255 && str_contains($out, 'strict_types') && ! str_contains($out, 'caught'), "{$what}: required, a fatal error ({$code}): {$out}");
        same(['value' => null, 'problem' => Config::NOT_PHP], Config::readLocalFile($file), "{$what}: through the guard, passed over - this process goes on");
    }
    removeTree($folder);
});

test('the attack it stops: a database.local.php saved with a byte-order mark and no <?php line holds the password - no answer carries it, the status included; the site is not set up, said by the file\'s fault', function () use ($server) {
    $password = 'pw-'.bin2hex(random_bytes(8));
    $files = setUpFiles($server);
    $files['database.local.php'] = BOM.returning(['database' => T::$database, 'password' => $password, 'persistent' => false] + $server);
    $root = settingsRoot($files);

    [$config, $printed, $log] = watched($root, fn (): Config => Config::load($root));
    same('', $printed, 'loaded: nothing of the file printed');
    same(['config/database.local.php '.Config::NOT_PHP], $config->problems(), 'not set up: the file and its fault, never a value');
    check(str_contains($log, 'config/database.local.php '.Config::NOT_PHP) && ! str_contains($log, $password), 'said in PHP\'s error log by its fault, never its text: '.$log);

    $api = buildAnalyzerApi(new App($config));
    foreach ([['GET', '/api/site-status'], ['GET', '/api/meta'], ['GET', '/api/accounts'], ['POST', '/api/feedback'], ['GET', '/api/translations/en']] as [$method, $path]) {
        [$status, $body, $headers, $printed] = askApp($api, $method, $path);
        same('', $printed, "{$path}: nothing printed ahead of the answer");
        check($status !== 500 && str_starts_with($body, '{'), "{$path}: an answer of its own, never a 500: {$status} {$body}");
        check(! str_contains($body, $password) && ! str_contains($headers, $password), "{$path}: no password in the answer");
    }
    [$status, $body] = askApp($api, 'GET', '/api/site-status');
    same([200, 'not_set_up'], [$status, json_decode($body, true)['state'] ?? null], 'the status says not set up');
    [$status, $body] = askApp($api, 'GET', '/api/accounts');
    same([503, 'not_set_up'], [$status, json_decode($body, true)['code'] ?? null], 'and every other path is 503 not_set_up');
    removeTree($root);
});

test('a byte-order mark or a blank line before <?php declare(strict_types=1) in the database\'s or the IAM\'s file, and every other bad start or a file that prints: never compiled, never a 500 - not set up, nothing of it printed or answered', function () use ($server) {
    $marker = 'by-hand-'.bin2hex(random_bytes(6));
    foreach (['database.local.php', 'auth.local.php'] as $name) {
        $cases = [];
        foreach (BAD_STARTS as $what => [$before, $start]) {
            $cases[$what] = [$before.$start."// {$marker}\n".substr(setUpFiles($server)[$name], strlen('<?php ')), Config::NOT_PHP];
        }
        $cases['text after a closing tag'] = ["<?php \$kept = 1; ?>\n{$marker}\n".setUpFiles($server)[$name],
            'prints '.(strlen($marker) + 1).' bytes of its own (text outside its PHP: text after a closing tag, or something it echoes)'];
        foreach ($cases as $what => [$contents, $fault]) {
            $root = settingsRoot([$name => $contents] + setUpFiles($server));
            [$config, $printed, $log] = watched($root, fn (): Config => Config::load($root));
            same('', $printed, "{$name}, {$what}: nothing printed");
            same(["config/{$name} {$fault}"], $config->problems(), "{$name}, {$what}: not set up, by the file's fault");
            check(str_contains($log, "config/{$name} {$fault}") && ! str_contains($log, $marker) && ! str_contains($log, 'strict_types'),
                "{$name}, {$what}: said by its fault, never its text nor PHP's words: {$log}");
            [$status, $body, , $printed] = askApp(buildAnalyzerApi(new App($config)), 'GET', '/api/site-status');
            same(['', 200, 'not_set_up'], [$printed, $status, json_decode($body, true)['state'] ?? null], "{$name}, {$what}: the status says not set up, nothing printed");
            check(! str_contains($body, $marker), "{$name}, {$what}: nothing of the file in the answer");
            removeTree($root);
        }
    }

    // Mended, the same root is set up again - and a passed-over file is said once a day, not on every load.
    $root = settingsRoot(['database.local.php' => BOM.setUpFiles($server)['database.local.php']] + setUpFiles($server));
    [, , $log] = watched($root, function () use ($root): void {
        Config::load($root);
        Config::load($root);
    });
    same(1, substr_count($log, 'config/database.local.php '.Config::NOT_PHP), 'said once, though read twice: '.$log);
    file_put_contents($root.'/config/database.local.php', setUpFiles($server)['database.local.php']);
    same([], Config::load($root)->problems(), 'mended: set up');
    removeTree($root);
});

test('the attack it stops: a cloudflare.local.php that cannot be read cleanly never makes the site believe a forged CF-Connecting-IP from a Cloudflare address - it fails closed, a secret no request carries (the file\'s own value too), and the site keeps running', function () use ($server) {
    $secret = 'origin-'.bin2hex(random_bytes(20));
    $body = returning(['origin_secret' => $secret]);
    $cases = [];
    foreach (BAD_STARTS as $what => [$before, $start]) {
        $cases[$what] = [$before.$start.$body, Config::NOT_PHP];
    }
    $cases['text after a closing tag'] = ["<?php \$kept = 1; ?>\n{$secret}\n<?php {$body}",
        'prints '.(strlen($secret) + 1).' bytes of its own (text outside its PHP: text after a closing tag, or something it echoes)'];
    $cases['a parse error'] = ["<?php return ['origin_secret' => '{$secret}' '{$secret}'];\n", 'could not be read (ParseError on line 1)'];

    T::$app->config->set('visitors.new_per_hour_per_address', 1);
    $edgeAt = 10;
    foreach ($cases as $what => [$contents, $fault]) {
        $root = settingsRoot(['cloudflare.local.php' => $contents] + setUpFiles($server));
        [$config, $printed, $log] = watched($root, fn (): Config => Config::load($root));
        same('', $printed, "{$what}: nothing of the file printed");
        same([], $config->problems(), "{$what}: the site set up - the file is optional");
        $read = $config->originSecret();
        check(is_string($read) && preg_match('/^[0-9a-f]{64}$/D', $read) === 1 && $read !== $secret, "{$what}: a secret no request carries, not the file's, never none");
        same($read, Config::load($root)->originSecret(), "{$what}: the same one all through the process");
        foreach ([
            'without X-Anotoki-Origin' => [CLOUDFLARE_EDGE, ['CF-Connecting-IP' => FORGED], CLOUDFLARE_EDGE],
            'with the file\'s own value' => [CLOUDFLARE_EDGE, ['CF-Connecting-IP' => FORGED, 'X-Anotoki-Origin' => $secret], CLOUDFLARE_EDGE],
            'from outside Cloudflare\'s ranges' => [ELSEWHERE, ['CF-Connecting-IP' => FORGED], ELSEWHERE],
        ] as $how => [$connection, $headers, $counted]) {
            same($counted, ClientAddress::counted(requestFrom($connection, $headers), $config), "{$what}, {$how}: fails closed - the connection counted, never the address it claims");
        }
        check(str_contains($log, "config/cloudflare.local.php {$fault}") && str_contains($log, 'cannot be read cleanly: no request carries its secret'),
            "{$what}: said in PHP's error log by its fault, and that it fails closed: {$log}");
        check(! str_contains($log, $secret) && ! str_contains($log, 'strict_types'), "{$what}: never the secret, nor PHP's words");

        // Through the API, with what load() made of the file: each new address a Worker writes in is the one edge,
        // counted already - the visitors' limit and the admin tools alike.
        T::$app->config->set('cloudflare.origin_secret', $config->get('cloudflare.origin_secret'));
        $edge = '173.245.48.'.(++$edgeAt);
        (new Client($edge))->withHeaders(['CF-Connecting-IP' => '198.51.100.'.$edgeAt, 'X-Anotoki-Origin' => $secret])->post('/api/imports/file', ['content' => visitorFile()])->status(201);
        $again = (new Client($edge))->withHeaders(['CF-Connecting-IP' => '198.51.100.'.($edgeAt + 100), 'X-Anotoki-Origin' => $secret])->post('/api/imports/file', ['content' => visitorFile()]);
        same([429, 'too_many_visitors'], [$again->status, $again->json('code')], "{$what}: another address claimed through the same edge, the file's value with it - the edge, counted already");
        same($edge, AdminTools::context(T::$app)->clientKey(requestFrom($edge, ['CF-Connecting-IP' => FORGED, 'X-Anotoki-Origin' => $secret])), "{$what}: the admin tools read the edge too");
        removeTree($root);
    }

    // A file read cleanly that names no secret is no secret, as without the file - said in the log, by its name.
    foreach (['an empty value' => "<?php return ['origin_secret' => ''];\n", 'another key' => "<?php return ['secret' => '{$secret}'];\n", 'no array' => "<?php return '{$secret}';\n"] as $what => $contents) {
        $root = settingsRoot(['cloudflare.local.php' => $contents] + setUpFiles($server));
        [$config, , $log] = watched($root, fn (): Config => Config::load($root));
        same([[], null], [$config->problems(), $config->originSecret()], "{$what}: no secret, the site set up");
        same(FORGED, ClientAddress::counted(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => FORGED]), $config), "{$what}: CF-Connecting-IP from Cloudflare's ranges alone, as with no file");
        check(str_contains($log, 'config/cloudflare.local.php holds no origin_secret') && ! str_contains($log, $secret), "{$what}: said by the file's name, never the value: {$log}");
        removeTree($root);
    }
});

test('a client_address.local.php that cannot be read cleanly trusts no proxy - every request counts its own connection - never Cloudflare\'s ranges in its place; a fetch.local.php falls back to the defaults; the site keeps running', function () use ($server) {
    foreach (BAD_STARTS as $what => [$before, $start]) {
        $root = settingsRoot([
            'client_address.local.php' => $before.$start.returning(['trusted_proxies' => ['173.245.48.0/20']]),
            'fetch.local.php' => $before.$start.returning(['user_agent' => 'mine', 'delay_ms' => 5000]),
        ] + setUpFiles($server));
        [$config, $printed, $log] = watched($root, fn (): Config => Config::load($root));
        same(['', []], [$printed, $config->problems()], "{$what}: nothing printed, the site set up");
        same([], $config->get('client_address.trusted_proxies'), "{$what}: no proxy trusted");
        same(CLOUDFLARE_EDGE, ClientAddress::counted(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => FORGED]), $config), "{$what}: a forged CF-Connecting-IP from a Cloudflare address is the connection");
        same([Config::FETCH_DEFAULTS['user_agent'], Config::FETCH_DEFAULTS['delay_ms']], [$config->get('fetch.user_agent'), $config->get('fetch.delay_ms')], "{$what}: the fetch settings' defaults");
        check(str_contains($log, 'config/client_address.local.php '.Config::NOT_PHP.': it is passed over, nothing in it read - no proxy is trusted')
            && str_contains($log, 'config/fetch.local.php '.Config::NOT_PHP.': it is passed over, nothing in it read - the defaults stand in'), "{$what}: both said: {$log}");
        removeTree($root);
    }
    // Read cleanly, the proxies' file is taken as ever.
    $root = settingsRoot(['client_address.local.php' => '<?php '.returning(['trusted_proxies' => ['10.0.0.0/8']])] + setUpFiles($server));
    same(['10.0.0.0/8'], Config::load($root)->get('client_address.trusted_proxies'), 'read cleanly: its proxies');
    removeTree($root);
});

test('setup reads its files the same way: a database.local.php that cannot be read cleanly shows no value, keeps no password, prints nothing - and the page names it by its fault; a broken token file is no token', function () use ($server) {
    $password = 'pw-'.bin2hex(random_bytes(8));
    foreach (BAD_STARTS as $what => [$before, $start]) {
        $root = settingsRoot([
            'database.local.php' => $before.$start.returning(['host' => 'db.example.test', 'port' => '5432', 'database' => 'anotoki_builder', 'username' => 'me', 'password' => $password]),
            'auth.local.php' => '<?php '.returning(['issuer' => TEST_ISSUER, 'client_id' => TEST_CLIENT]),
            'setup-token.local.php' => $before.$start.returning(['token' => str_repeat('a', 48)]),
        ]);
        $installer = new Installer($root);
        ob_start();
        try {
            $read = [$installer->saved(), $installer->savedPassword(), $installer->passedOver(), $installer->token()];
        } finally {
            $printed = (string) ob_get_clean();
        }
        same('', $printed, "{$what}: nothing printed");
        same([['issuer' => TEST_ISSUER, 'client_id' => TEST_CLIENT], null, ['config/database.local.php '.Config::NOT_PHP], null], $read,
            "{$what}: the IAM's values alone, no password, the database's file named by its fault, no token");
        check(! str_contains(json_encode($read), $password), "{$what}: never the password");
        removeTree($root);
    }
});

/** A small GOOD file, as a visitor's first import sends it. */
function visitorFile(): string
{
    return (string) json_encode(['format' => 'GOOD', 'version' => 2, 'source' => 'Some Exporter', 'characters' => [
        ['key' => 'HuTao', 'level' => 80, 'constellation' => 0, 'ascension' => 5, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]],
    ], 'weapons' => [], 'artifacts' => []]);
}

finishTests();
