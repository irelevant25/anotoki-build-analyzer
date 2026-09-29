<?php

/**
 * Signing in through the anotoki IAM: which tokens are accepted, who a request is, what each role may do,
 * auth mode "none" and its checks, the setup gate, and the development router. Tokens are signed with a
 * test key published as a JWKS file.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Auth\Role;
use App\Config;
use App\DevRouter;
use Tests\Support\Client;

bootTests();

$users = fn () => (int) db()->value('SELECT count(*) FROM users');
$userName = fn (int $id) => db()->value('SELECT name FROM users WHERE id = ?', [$id]);

test('meta is public and says how to sign in', function () {
    (new Client)->get('/api/meta')->status(200)
        ->path('auth.mode', 'iam')
        ->path('auth.issuer', TEST_ISSUER)
        ->path('auth.client_id', TEST_CLIENT)
        ->path('games.0.key', 'genshin');
});

test('requests without a valid access token are refused', function () use ($users) {
    $client = new Client;
    $answer = $client->get('/api/accounts')->status(401)->path('message', 'Sign in to continue.');
    same('Bearer', $answer->header('WWW-Authenticate'), 'WWW-Authenticate');

    $otherKey = anotoki_iam_test_keypair('test-key'); // the same kid, but not the IAM's key
    $refused = [
        'expired' => iamToken(5, claims: ['exp' => time() - 120]),
        'for another application' => iamToken(5, claims: ['aud' => 'genshin']),
        'from another issuer' => iamToken(5, claims: ['iss' => 'https://iam.evil.example']),
        'an ID token' => iamToken(5, typ: 'JWT'),
        'signed by another key' => iamToken(5, key: $otherKey),
        'not valid yet' => iamToken(5, claims: ['nbf' => time() + 600]),
        'without a user id' => iamToken(5, claims: ['sub' => 'admin']),
        'not a JWT' => 'definitely-not-a-token',
    ];
    foreach ($refused as $case => $token) {
        same(401, (new Client)->withToken($token)->get('/api/accounts')->status, $case);
    }

    // The mode is configuration: nothing in a request switches sign-in off.
    same(401, (new Client)->withToken('x')->get('/api/accounts?auth=none&mode=none')->status, 'query parameters');
    same(401, (new Client)->withHeaders(['X-Auth-Mode' => 'none', 'X-Forwarded-For' => '127.0.0.1'])->get('/api/accounts')->status, 'headers');

    same(0, $users(), 'a refused token creates no user');
});

test('a valid token signs the person in and keeps their name current', function () use ($users, $userName) {
    same('[]', (new Client)->asIam(42, [Role::MEMBER], ['preferred_username' => 'mona'])->get('/api/accounts')->body, 'no accounts yet');
    same('mona', $userName(42), 'name');

    (new Client)->asIam(42, [Role::MEMBER], ['preferred_username' => 'mona_megistus'])->get('/api/accounts')->status(200);
    same('mona_megistus', $userName(42), 'renamed');
    same(1, $users(), 'one row');
});

test('a token without a role in this application is forbidden', function () {
    (new Client)->asIam(7, [])->get('/api/accounts')->status(403)
        ->path('message', 'Your account has no access to the build analyzer. Ask the owner to add you.');

    // Roles are this application's only; a role name from elsewhere means nothing here.
    (new Client)->asIam(7, ['EDITOR'])->get('/api/builds/genshin')->status(403);
});

test('syncs, sources and migrations are for admins only', function () {
    $member = (new Client)->asIam(3, [Role::MEMBER]);

    // Members read the state of the sources (the dashboard shows it) ...
    $member->get('/api/sources')->status(200);

    // ... but nothing that changes them or runs anything.
    $adminOnly = [
        ['PATCH', '/api/sources/genshin-1', ['enabled' => false]],
        ['POST', '/api/sources/genshin-1/sync', []],
        ['GET', '/api/sources/genshin-1/export', null],
        ['POST', '/api/sources/genshin-1/import', []],
        ['GET', '/api/sync', null],
        ['POST', '/api/sync/builds', []],
        ['POST', '/api/sync/catalog', []],
        ['POST', '/api/sync/step', []],
        ['GET', '/api/sync-runs', null],
        ['GET', '/api/sync-runs/1', null],
        ['POST', '/api/sync-runs/1/cancel', []],
        ['GET', '/api/admin/migrations', null],
        ['POST', '/api/admin/migrations/apply', []],
    ];
    foreach ($adminOnly as [$method, $url, $body]) {
        $answer = $member->request($method, $url, $body);
        same(403, $answer->status, "{$method} {$url}");
        same('Only an admin can do this.', $answer->json('message'), "{$method} {$url} message");
    }

    $admin = (new Client)->asIam(4, [Role::ADMIN]);
    $admin->patch('/api/sources/genshin-1', ['enabled' => false])->status(200)->path('enabled', false)->path('name', 'Source 1');
    same(['active' => false, 'wait_ms' => null, 'runs' => []], $admin->get('/api/sync')->status(200)->json(), 'no syncs');
    $admin->post('/api/sync/step')->status(200)->path('busy', false)->path('did', null);
    $admin->get('/api/admin/migrations')->status(200)->path('pending', []);
    $admin->post('/api/admin/migrations/apply')->status(200)->path('ok', true)->path('applied', []);

    // ADMIN includes everything a member may do.
    $admin->get('/api/accounts')->status(200);
});

test('auth mode "none" acts as the owner, with every role', function () use ($userName) {
    T::$app->config->set('auth.mode', 'none');
    T::$app->config->set('auth.local_user_id', 9);

    (new Client)->get('/api/meta')->path('auth.mode', 'none')->path('auth.issuer', null);
    (new Client)->get('/api/accounts')->status(200);
    (new Client)->get('/api/sync')->status(200); // an admin route
    same('owner', $userName(9), 'the owner\'s row');

    // A token changes nothing: the request is still the owner's.
    (new Client)->withToken(iamToken(5, [Role::MEMBER]))->get('/api/accounts')->status(200);
    same(null, $userName(5), 'no row for the token\'s user');
});

test('auth mode "none" only answers this computer', function () {
    T::$app->config->set('auth.mode', 'none');

    (new Client('203.0.113.9'))->get('/api/accounts')->status(403)
        ->path('message', 'Sign-in is switched off on this server (auth mode "none"), so it only answers its own pages on the computer it runs on.');
});

test('auth mode "none" refuses other sites open in the owner\'s browser', function () {
    T::$app->config->set('auth.mode', 'none');

    // Another site's page also connects from 127.0.0.1; a "simple" POST needs no CORS preflight.
    (new Client)->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->post('/api/sync/builds')->status(403);
    (new Client)->withHeaders(['Origin' => 'https://elsewhere.example'])->get('/api/accounts')->status(403);
    (new Client)->withHeaders(['Origin' => 'null'])->get('/api/accounts')->status(403);
    // A DNS-rebound name that points at 127.0.0.1, and a name that only looks local.
    (new Client)->get('http://rebound.elsewhere.example/api/accounts')->status(403);
    (new Client)->get('http://127.elsewhere.example/api/accounts')->status(403);

    // The app's own pages: the dev server on localhost:4600 proxies /api, or the API's own origin.
    (new Client)->withHeaders(['Origin' => 'http://localhost:4600', 'Sec-Fetch-Site' => 'same-origin'])->get('http://localhost/api/accounts')->status(200);
    (new Client)->get('http://127.0.0.1:8400/api/accounts')->status(200);
});

test('no other site may read or call the API', function () {
    // Same origin only: no CORS answer for anybody else, so browsers keep other sites out.
    $preflight = (new Client)->withHeaders(['Origin' => 'https://elsewhere.example', 'Access-Control-Request-Method' => 'DELETE'])
        ->request('OPTIONS', '/api/accounts/1');
    same('', $preflight->header('Access-Control-Allow-Origin'), 'preflight');

    $answer = (new Client)->withHeaders(['Origin' => 'https://elsewhere.example'])->get('/api/meta')->status(200);
    same('', $answer->header('Access-Control-Allow-Origin'), 'a simple request');
});

test('ids in paths are numbers', function () {
    $admin = (new Client)->asIam(3, [Role::ADMIN]);

    $admin->get('/api/accounts/abc')->status(404);
    $admin->get('/api/sync-runs/abc')->status(404);
    $answer = $admin->post('/api/imports/file', ['content' => '{}', 'account_id' => 'abc'])->status(422);
    check(isset($answer->json()['errors']['account_id']), 'a validation error for account_id: '.$answer->body);
});

test('an unknown auth mode asks for sign-in', function () {
    T::$app->config->set('auth.mode', 'None ');

    (new Client)->get('/api/accounts')->status(401);
    (new Client)->get('/api/meta')->path('auth.mode', 'iam');
});

test('unknown API paths are JSON 404s', function () {
    $answer = (new Client)->get('/api/nothing-here')->status(404);
    same('Not found.', $answer->json('message'), 'message');
    check(str_starts_with($answer->header('Content-Type'), 'application/json'), 'JSON');
});

test('the development router serves the app for its own paths only', function () {
    $app = T::$storage.DIRECTORY_SEPARATOR.'browser';
    @mkdir($app, 0777, true);
    file_put_contents($app.'/index.html', '<!doctype html><title>app</title>');
    file_put_contents($app.'/main-ABC123.js', 'console.log(1)');

    same('api', DevRouter::resolve('/api/meta', $app)[0], '/api');
    same('setup', DevRouter::resolve('/setup.php', $app)[0], '/setup.php');
    same('app', DevRouter::resolve('/', $app)[0], '/');
    same('app', DevRouter::resolve('/accounts/3', $app)[0], 'an app path');
    same('app', DevRouter::resolve('/auth/callback', $app)[0], 'the sign-in callback');
    same('file', DevRouter::resolve('/main-ABC123.js', $app)[0], 'a file of the build');
    // A missing asset (an old hashed chunk after a deploy) is not answered with the app.
    same('missing', DevRouter::resolve('/main-OLDHASH.js', $app)[0], 'a missing file');
    same('missing', DevRouter::resolve('/../config/database.local.php', $app)[0], 'nothing outside the build');
    same('no-app', DevRouter::resolve('/', T::$storage.'/nothing')[0], 'no build');
});

test('an installation that is not set up answers 503 not_set_up everywhere', function () {
    $empty = T::$storage.DIRECTORY_SEPARATOR.'empty';
    @mkdir($empty.'/config', 0777, true);
    $config = Config::load($empty);
    check(! $config->isSetUp(), 'no local config files');
    same(['config/database.local.php is missing', 'config/auth.local.php is missing', 'config/fetch.local.php is missing'], $config->problems(), 'problems');

    $api = buildAnalyzerApi(new App\App($config));
    foreach (['/api/meta', '/api/accounts', '/api/sync'] as $path) {
        $request = (new Slim\Psr7\Factory\ServerRequestFactory)->createServerRequest('GET', 'http://localhost'.$path, ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $api->handle($request);
        same(503, $response->getStatusCode(), $path);
        $body = json_decode((string) $response->getBody(), true);
        same('not_set_up', $body['code'] ?? null, "{$path} code");
        same('/setup.php', $body['setup'] ?? null, "{$path} points at setup.php");
    }
});

test('a local config file that is incomplete is not a set-up installation', function () {
    $half = T::$storage.DIRECTORY_SEPARATOR.'half';
    @mkdir($half.'/config', 0777, true);
    file_put_contents($half.'/config/database.local.php', "<?php return ['host' => '127.0.0.1', 'port' => '5432', 'database' => 'x', 'username' => 'u'];");
    file_put_contents($half.'/config/auth.local.php', "<?php return ['mode' => 'iam'];");
    file_put_contents($half.'/config/fetch.local.php', "<?php return ['user_agent' => 'x'];");

    $problems = Config::load($half)->problems();
    check(in_array('config/auth.local.php has no issuer (the IAM\'s address)', $problems, true), implode('; ', $problems));
    check(in_array('config/auth.local.php has no client_id', $problems, true), implode('; ', $problems));
});

finishTests();
