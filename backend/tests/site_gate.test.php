<?php

/**
 * The site gate (anotoki/lib's SiteState and SiteGate; api/middleware/site_gate.php) on real requests, in each
 * state the site can be in - not set up and never installed (the setup page offered), not set up but installed
 * (never again), an update waiting (503 update_pending everywhere but the open paths), the database out of
 * reach, ready - and GET /api/site-status, which says which. The Migrations page's routes answer while an
 * update waits, to an ADMIN judged from the token alone: on a database with nothing in it yet, an ADMIN
 * builds it from the page.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Admin\Schema as AdminSchema;
use Anotoki\Lib\Backups\Http\BackupsRoutes;
use Anotoki\Lib\ErrorLog\Http\ErrorLogRoutes;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\People\Schema as PeopleSchema;
use Anotoki\Lib\Translations\Http\TranslationsRoutes;
use Anotoki\Lib\Translations\Schema;
use App\App;
use App\Auth\Role;
use App\Config;
use App\Db\Db;
use App\Db\Migrations;
use App\Http\HttpClient;
use Tests\Support\Client;

bootTests();

$server = require __DIR__ . '/../config/database.local.php';

const UPDATE_PENDING = ['code' => 'update_pending', 'message' => 'The site is being updated. Try again in a few minutes.'];

/**
 * One request to an app of a test's own (T::$api is the file's): its status, body, and the headers that say
 * what kind of answer it is.
 *
 * @return array{status: int, body: mixed, type: string, cache: string, retry: string}
 */
function ask(Slim\App $api, string $method, string $path, ?string $token = null): array
{
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])->withHeader('Host', 'localhost');
    if ($token !== null) {
        $request = $request->withHeader('Authorization', 'Bearer ' . $token);
    }
    $response = $api->handle($request);

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getBody(), true),
        'type' => $response->getHeaderLine('Content-Type'),
        'cache' => $response->getHeaderLine('Cache-Control'),
        'retry' => $response->getHeaderLine('Retry-After'),
    ];
}

/** An installation with no config/*.local.php - and storage/installed.lock when setup.php once finished on a real host. */
function notSetUp(string $name, bool $installed): Slim\App
{
    $root = T::$storage . DIRECTORY_SEPARATOR . $name;
    @mkdir($root . '/config', 0777, true);
    @mkdir($root . '/storage', 0777, true);
    copy(__DIR__ . '/../config/app.php', $root . '/config/app.php');
    if ($installed) {
        file_put_contents($root . '/storage/installed.lock', json_encode(['installed_at' => gmdate('c'), 'site' => 'https://builds.example.test']));
    }
    $config = Config::load($root);
    check(!$config->isSetUp(), 'not set up');

    return buildAnalyzerApi(new App($config));
}

/**
 * $body while an update waits: the database has every migration, and the app's Migrator sees one more file on
 * disk (900_waiting.sql), as if just uploaded. Everything is put back after, applied or not.
 */
function whileAnUpdateWaits(callable $body): void
{
    $dir = T::$storage . DIRECTORY_SEPARATOR . 'one-more';
    @mkdir($dir, 0777, true);
    foreach (glob(Migrations::directory() . '/*.sql') ?: [] as $file) {
        copy($file, $dir . '/' . basename($file));
    }
    file_put_contents($dir . '/900_waiting.sql', 'CREATE TABLE waiting (id INT);');
    T::$app->set(Migrator::class, Migrations::migrator(db()->raw(), $dir));
    try {
        $body();
    } finally {
        T::$app->forget(Migrator::class);
        db()->run("DELETE FROM migrations WHERE filename = '900_waiting.sql'");
        db()->run('DROP TABLE IF EXISTS waiting');
    }
}

/** What every path but the status answers, as nobody and with a token. */
const SOME_PATHS = [
    ['GET', '/api/meta'],
    ['GET', '/api/translations/en'],
    ['GET', '/api/accounts'],
    ['GET', '/api/builds/genshin'],
    ['POST', '/api/imports/file'],
    ['GET', '/api/admin/migrations'],
    ['POST', '/api/admin/migrations/apply'],
    ['GET', '/api/nothing-here'],
];

test('the paths open while an update waits are the sign-in settings, the strings, the Migrations page\'s, the error log\'s and the backups\' - nothing else', function () {
    same(
        [
            '/api/meta',
            '~^/api/translations/[^/]+$~D',
            '~^/api/admin/migrations(/file|/apply)?$~D',
            '~^/api/admin/errors(/[0-9a-f]{12})?$~D',
            '~^/api/admin/backups(/proof|/[A-Za-z0-9-]{20,80}(/(ticket|file|preview))?)?$~D',
        ],
        siteOpenPaths(),
        'the open paths',
    );
    same([...ErrorLogRoutes::openPaths(), ...BackupsRoutes::openPaths()], array_slice(siteOpenPaths(), 3), 'the admin tools\' as the library opens its own routes: never a prefix, never a restore');
    same(TranslationsRoutes::openPath(), siteOpenPaths()[1], 'the strings\' as the library opens its own route: one segment, never a prefix');
});

test('ready: the status says so, and every path answers as ever', function () {
    $status = (new Client())->get('/api/site-status')->status(200);
    same(['state' => 'ready', 'installed' => false], $status->json(), 'the status: nothing else in it');
    same(['application/json; charset=utf-8', 'no-store'], [$status->header('Content-Type'), $status->header('Cache-Control')], 'JSON, never kept');
    (new Client())
        ->get('/api/meta')
        ->status(200)
        ->path('auth.issuer', TEST_ISSUER);
    (new Client())->get('/api/builds/genshin')->status(200);
    (new Client())->asIam(7, [])->get('/api/accounts')->status(200);
    (new Client())
        ->asIam(1, [Role::ADMIN])
        ->get('/api/admin/migrations')
        ->status(200)
        ->path('pending', [])
        ->path('sets', [AdminSchema::SET, Migrations::SET, Schema::SET, PeopleSchema::SET]);
    (new Client())->get('/api/nothing-here')->status(404)->path('code', 'not_found');
});

test('not set up, never installed: every path answers 503 not_set_up with the setup page; the status says so', function () {
    $api = notSetUp('never-installed', false);
    same(['status' => 200, 'body' => ['state' => 'not_set_up', 'installed' => false]], array_intersect_key(ask($api, 'GET', '/api/site-status'), ['status' => 0, 'body' => 0]), 'the status');

    foreach (SOME_PATHS as [$method, $path]) {
        foreach ([null, iamToken(1, [Role::ADMIN])] as $token) {
            $answer = ask($api, $method, $path, $token);
            same(
                [503, ['code' => 'not_set_up', 'installed' => false, 'message' => 'This site is not set up yet. Its setup page connects it to its database.', 'setup' => '/setup.php']],
                [$answer['status'], $answer['body']],
                "{$method} {$path}" . ($token ? ', an admin' : ''),
            );
            same(['application/json; charset=utf-8', 'no-store', ''], [$answer['type'], $answer['cache'], $answer['retry']], "{$method} {$path}: JSON, never kept");
        }
    }
});

test('not set up, but installed once (storage/installed.lock): the same 503 - and never the setup page again', function () {
    $api = notSetUp('installed', true);
    same(['state' => 'not_set_up', 'installed' => true], ask($api, 'GET', '/api/site-status')['body'], 'the status');

    foreach (SOME_PATHS as [$method, $path]) {
        $answer = ask($api, $method, $path);
        same(
            [503, ['code' => 'not_set_up', 'installed' => true, 'message' => 'The site is not available right now. Try again in a few minutes.']],
            [$answer['status'], $answer['body']],
            "{$method} {$path}: no setup page",
        );
    }
});

test('an update waits: 503 update_pending with Retry-After everywhere - but meta, the strings and the Migrations page\'s API', function () {
    whileAnUpdateWaits(function (): void {
        same(['state' => 'update_pending', 'installed' => false], (new Client())->get('/api/site-status')->status(200)->json(), 'the status');

        $people = [
            'nobody' => new Client(),
            'a member' => (new Client())->asIam(7, []),
            'an editor' => (new Client())->asIam(4, [Role::EDITOR]),
            'an admin' => (new Client())->asIam(1, [Role::ADMIN]),
        ];
        $closed = [
            ['GET', '/api/accounts'],
            ['GET', '/api/builds/genshin'],
            ['GET', '/api/builds/genshin/hutao'],
            ['GET', '/api/builds'],
            ['GET', '/api/admin/builds'],
            ['GET', '/api/admin/builds/genshin/export'],
            ['POST', '/api/admin/builds/genshin/import'],
            ['GET', '/api/admin/builds/genshin/catalog'],
            ['GET', '/api/admin/builds/genshin/characters/hutao'],
            ['PUT', '/api/admin/builds/genshin/characters/hutao'],
            ['POST', '/api/admin/catalog/genshin/characters'],
            ['POST', '/api/imports/file'],
            ['GET', '/api/icons/c/1'],
            ['GET', '/api/me/export'],
            ['POST', '/api/visitor/move'],
            ['GET', '/api/admin/people'],
            ['POST', '/api/admin/people/sync'],
            ['GET', '/api/admin/translations'],
            ['GET', '/api/admin/audit'],
            ['GET', '/api/admin/feedback'],
            ['GET', '/api/admin/feedback/unread'],
            ['POST', '/api/feedback'],
            ['POST', '/api/admin/backups/20261011-120000Z-manual-0123456789abcdef0123456789abcdef/restore'],
            ['GET', '/api/admin/errorsx'],
            ['GET', '/api/admin/errors/0123456789ab/x'],
            ['GET', '/api/admin/backups/x'],
            ['GET', '/api/admin/backups/../../accounts'],
            ['PUT', '/api/admin/translations'],
            ['GET', '/api/admin/languages'],
            ['DELETE', '/api/admin/people/5'],
            ['GET', '/api/nothing-here'],
            ['GET', '/api/translations'],
            ['GET', '/api/translations/en/more'],
            ['GET', '/api/admin/migrationsx'],
            // A newline at the end of an open path's address is not that path.
            ['GET', '/api/admin/migrations%0A'],
            ['GET', '/api/admin/migrations/apply%0A'],
            ['GET', '/api/meta%0A'],
            // Nor is a crafted path under one: the gate reads the path decoded, as the router does, and neither
            // resolves dot segments - an open prefix would let these into the app.
            ['GET', '/api/admin/migrations/../x'],
            ['GET', '/api/admin/migrations/%2e%2e/x'],
            ['GET', '/api/admin/migrations/../accounts'],
            ['GET', '/api/admin/migrations/%2E%2E/%2e%2e/builds/genshin'],
            ['GET', '/api/admin/migrations%2F..%2Faccounts'],
            ['GET', '/api/admin/migrations/..%2F..%2Fadmin%2Fpeople'],
            ['POST', '/api/admin/migrations/apply/..'],
            ['GET', '/api/admin/migrations/./file'],
            ['GET', '/api/admin/migrations/file/x'],
            ['GET', '/api/admin/migrations/'],
            ['GET', '/api/admin/migrations/x'],
            ['GET', '/api/translations/en%2F..%2F..%2Faccounts'],
            ['GET', '/api/translations/..%2Fmeta'],
            ['GET', '/api/meta/..'],
        ];
        foreach ($people as $who => $client) {
            foreach ($closed as [$method, $url]) {
                $answer = $client->request($method, $url, $method === 'GET' ? null : []);
                same([503, UPDATE_PENDING, '60'], [$answer->status, $answer->json(), $answer->header('Retry-After')], "{$who}: {$method} {$url}");
            }
        }
        same(0, (int) db()->value('SELECT count(*) FROM users'), 'nobody was recorded: no route was reached');

        // Open: what the pages load before anything, so that people can sign in and read the page in their language.
        (new Client())
            ->get('/api/meta')
            ->status(200)
            ->path('auth.issuer', TEST_ISSUER)
            ->path('auth.login_required', false);
        (new Client())->get('/api/translations/sk')->status(200)->path('language', 'sk');
        $people['a member']->get('/api/translations/en')->status(200)->path('language', 'en');
        // A language's code is one segment: `..` there reaches the strings' route and no other, which refuses
        // what is not a language's code before any query.
        (new Client())->get('/api/translations/..')->status(404)->path('code', 'not_found');
        (new Client())->get('/api/translations/%2e%2e')->status(404)->path('code', 'not_found');

        // Open, for an ADMIN: the Migrations page's API - and only for an ADMIN, as ever.
        (new Client())->get('/api/admin/migrations')->status(401)->path('code', 'sign_in_required');
        $people['a member']->get('/api/admin/migrations')->status(403)->path('code', 'admin_only');
        $people['an editor']->post('/api/admin/migrations/apply')->status(403)->path('code', 'admin_only');
        (new Client())->withToken('x')->get('/api/admin/migrations')->status(401)->path('code', 'invalid_token');
        $admin = $people['an admin'];
        same([['set' => Migrations::SET, 'name' => '900_waiting.sql', 'ready' => true, 'blocked' => false]], $admin->get('/api/admin/migrations')->status(200)->json('pending'), 'what waits');
        $admin->get('/api/admin/migrations/file?set=build_analyzer&name=900_waiting.sql')->status(200)->path('sql', 'CREATE TABLE waiting (id INT);');

        // Open too, for an ADMIN alone: the error log and the backups, which need no table - a backup just before
        // Apply is the point (a restore stays closed: above).
        check(is_int($admin->get('/api/admin/errors')->status(200)->json('total')), 'the error log\'s report');
        $admin->get('/api/admin/backups')->status(200)->path('backups', []);
        $admin->get('/api/admin/backups/proof')->status(200)->path('kind', 'recent_sign_in');
        (new Client())->get('/api/admin/errors')->status(401)->path('code', 'sign_in_required');
        $people['a member']->get('/api/admin/backups')->status(403)->path('code', 'admin_only');
        $people['an editor']->get('/api/admin/errors')->status(403)->path('code', 'admin_only');
        same(0, (int) db()->value('SELECT count(*) FROM users'), 'the ADMIN check made no row: it reads the token alone');

        $applied = $admin->post('/api/admin/migrations/apply')->status(200);
        same(['applied' => [['set' => Migrations::SET, 'name' => '900_waiting.sql']], 'failed' => null, 'error' => null, 'note' => null], $applied->json(), 'Apply');

        // Ready again, for everybody.
        same(['state' => 'ready', 'installed' => false], (new Client())->get('/api/site-status')->status(200)->json(), 'the status after Apply');
        $people['a member']->get('/api/accounts')->status(200);
        (new Client())->get('/api/builds/genshin')->status(200);
    });
});

test('the status answers anybody in every state - also while the IAM requires sign-in, whatever comes with the request', function () {
    // The status reads no credentials: a 401 here would read on the pages as "not available right now".
    iamRequiresSignIn(true);
    $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $askers = [
        'nobody' => new Client(),
        'a visitor key' => (new Client())->withHeaders(['X-Visitor-Key' => $key]),
        'a token that does not verify' => (new Client())->withToken('x'),
        'a member' => (new Client())->asIam(7, []),
    ];
    $statusFor = function (string $state) use ($askers): void {
        foreach ($askers as $who => $client) {
            $answer = $client->get('/api/site-status');
            same([200, ['state' => $state, 'installed' => false]], [$answer->status, $answer->json()], "{$state}, {$who}");
        }
    };

    $statusFor('ready');
    (new Client())->get('/api/builds/genshin')->status(401)->path('code', 'sign_in_required');

    whileAnUpdateWaits(function () use ($statusFor): void {
        $statusFor('update_pending');
        // The gate answers before the sign-in requirement does, and meta - where to sign in - stays open.
        (new Client())->get('/api/builds/genshin')->status(503)->path('code', 'update_pending');
        (new Client())->get('/api/meta')->status(200)->path('auth.login_required', true);
    });

    $api = notSetUp('not-set-up-with-credentials', false);
    foreach ([null, 'x', iamToken(7)] as $token) {
        same(
            [200, ['state' => 'not_set_up', 'installed' => false]],
            array_values(array_intersect_key(ask($api, 'GET', '/api/site-status', $token), ['status' => 0, 'body' => 0])),
            'not set up, ' . ($token === null ? 'nobody' : 'with a token'),
        );
    }
    same(0, (int) db()->value('SELECT count(*) FROM users'), 'nobody was recorded');
});

test('a database with nothing in it yet: the Migrations page\'s ADMIN check needs no table, and an ADMIN builds it all from there', function () use ($server) {
    $pdo = scratchDatabase($server, 'nothing');
    $appDb = T::$app->get(Db::class);
    T::$app->set(Db::class, new Db($pdo));
    T::$app->set(Migrator::class, Migrations::migrator($pdo));
    try {
        same('update_pending', (new Client())->get('/api/site-status')->status(200)->json('state'), 'every file waits');
        (new Client())
            ->asIam(4, [Role::EDITOR])
            ->get('/api/admin/migrations')
            ->status(403)
            ->path('code', 'admin_only');

        $admin = (new Client())->asIam(1, [Role::ADMIN], ['preferred_username' => 'ada']);
        $status = $admin->get('/api/admin/migrations')->status(200);
        // The site's files, then the library's.
        $migrator = Migrations::migrator($pdo);
        $files = [];
        foreach (Migrations::sets() as $set) {
            foreach ($migrator->files($set) as $name) {
                $files[] = ['set' => $set->name, 'name' => $name];
            }
        }
        same(
            [[], $files],
            [$status->json('applied'), array_map(static fn(array $file): array => ['set' => $file['set'], 'name' => $file['name']], $status->json('pending'))],
            'nothing applied, every file pending',
        );
        same(false, (bool) $pdo->query("SELECT to_regclass('public.users') IS NOT NULL")->fetchColumn(), 'no table of users - and none was needed');

        $applied = $admin->post('/api/admin/migrations/apply')->status(200);
        same([$files, null], [$applied->json('applied'), $applied->json('error')], 'every file applied');
        same('ready', (new Client())->get('/api/site-status')->status(200)->json('state'), 'ready');
        $admin->get('/api/admin/people')->status(200)->path('people.0.name', 'ada');
    } finally {
        T::$app->set(Db::class, $appDb);
        T::$app->forget(Migrator::class);
    }
});

test('the database out of reach: the status says unavailable, and the routes answer as they do without one', function () {
    // A database that is not there: every connection fails, as when the server is down.
    $config = testConfig(['database' => ['database' => T::$database . '_nowhere', 'persistent' => false] + (require __DIR__ . '/../config/database.local.php')]);
    $app = new App($config);
    $app->set(HttpClient::class, T::$http);
    $api = buildAnalyzerApi($app);

    same([200, ['state' => 'unavailable', 'installed' => false]], array_values(array_intersect_key(ask($api, 'GET', '/api/site-status'), ['status' => 0, 'body' => 0])), 'the status');
    // The gate lets everything through: the routes answer as they do without a database - the site's own 500.
    foreach ([['/api/builds/genshin', null], ['/api/accounts', iamToken(7)]] as [$path, $token]) {
        $answer = ask($api, 'GET', $path, $token);
        same([500, 'server_error'], [$answer['status'], $answer['body']['code'] ?? null], "{$path}: not a 503 of the gate");
    }
});

finishTests();
