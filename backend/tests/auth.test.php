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

test('the site\'s strings are public too: nobody is asked who they are', function () use ($users) {
    // The words are the same for everybody, and a page whose sign-in ran out still reads in its language.
    db()->run("INSERT INTO translation_keys (name) VALUES ('test.public')");
    db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.public', 'en', 'For everybody')");
    try {
        $anybody = (new Client)->get('/api/translations/sk')->status(200)->path('language', 'sk');
        same('For everybody', $anybody->json('values')['test.public'] ?? null, 'the strings');
        same('', $anybody->header('WWW-Authenticate'), 'no sign-in is asked for');
        same($anybody->body, (new Client)->withToken('definitely-not-a-token')->get('/api/translations/sk')->status(200)->body, 'a token that does not check out is not looked at');
        same($anybody->body, (new Client)->withToken(iamToken(5, claims: ['exp' => time() - 120]))->get('/api/translations/sk')->status(200)->body, 'nor one that ran out');
        same($anybody->body, (new Client)->asIam(7, [])->get('/api/translations/sk')->status(200)->body, 'nor somebody with no role here');
        same(0, $users(), 'and nobody was recorded');

        // With sign-in off it answers other computers and other sites' pages as well: there is nothing of anybody's in it.
        T::$app->config->set('auth.mode', 'none');
        same($anybody->body, (new Client('203.0.113.9'))->withHeaders(['Origin' => 'https://elsewhere.example'])->get('/api/translations/sk')->status(200)->body, 'from anywhere');
    } finally {
        db()->run("DELETE FROM translation_keys WHERE name = 'test.public'");
    }
});

test('requests without a valid access token are refused', function () use ($users) {
    $client = new Client;
    $answer = $client->get('/api/accounts')->status(401)->path('message', 'Sign in to continue.')->path('code', 'sign_in_required');
    same('Bearer', $answer->header('WWW-Authenticate'), 'WWW-Authenticate');
    same(['message' => 'Sign in to continue.', 'code' => 'sign_in_required'], $answer->json(), 'the whole answer');

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
        ->path('message', 'Your account has no access to the build analyzer. Ask the owner to add you.')
        ->path('code', 'no_access');

    // Roles are this application's only; a role name from elsewhere means nothing here.
    (new Client)->asIam(7, ['EDITOR'])->get('/api/builds/genshin')->status(403)->path('code', 'no_access');
});

test('syncs, sources, migrations, the languages and the site\'s strings are for admins only', function () {
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
        ['GET', '/api/admin/languages', null],
        ['POST', '/api/admin/languages', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']],
        ['PUT', '/api/admin/languages/sk', ['enabled' => false]],
        ['DELETE', '/api/admin/languages/sk', null],
        ['GET', '/api/admin/translations', null],
        ['PUT', '/api/admin/translations', ['values' => []]],
        ['GET', '/api/admin/translations/sk/export', null],
        ['PUT', '/api/admin/translations/sk/import', []],
    ];
    foreach ($adminOnly as [$method, $url, $body]) {
        $answer = $member->request($method, $url, $body);
        same(403, $answer->status, "{$method} {$url}");
        same('Only an admin can do this.', $answer->json('message'), "{$method} {$url} message");
        same('admin_only', $answer->json('code'), "{$method} {$url} code");
    }

    $admin = (new Client)->asIam(4, [Role::ADMIN]);
    $admin->patch('/api/sources/genshin-1', ['enabled' => false])->status(200)->path('enabled', false)->path('name', 'Source 1');
    same(['active' => false, 'wait_ms' => null, 'runs' => []], $admin->get('/api/sync')->status(200)->json(), 'no syncs');
    $admin->post('/api/sync/step')->status(200)->path('busy', false)->path('did', null);
    $admin->get('/api/admin/migrations')->status(200)->path('pending', []);
    $admin->post('/api/admin/migrations/apply')->status(200)->path('ok', true)->path('applied', []);

    // ADMIN includes everything a member may do.
    $admin->get('/api/accounts')->status(200);

    // The languages are as they were: a member's requests did none of it. (An admin's own go past the role
    // check to the strings themselves - translations.test.php.)
    same([['code' => 'en', 'enabled' => true], ['code' => 'sk', 'enabled' => true]], db()->all('SELECT code, enabled FROM languages ORDER BY code'), 'the languages');
    check(! in_array($admin->get('/api/admin/languages')->status, [401, 403], true), 'an admin is let through');
    // Signed out, the same routes ask for sign-in.
    foreach ($adminOnly as [$method, $url, $body]) {
        if (str_starts_with($url, '/api/admin/')) {
            same(401, (new Client)->request($method, $url, $body)->status, "{$method} {$url}, signed out");
        }
    }
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
        ->path('message', 'Sign-in is switched off on this server (auth mode "none"), so it only answers its own pages on the computer it runs on.')
        ->path('code', 'local_only');
});

test('auth mode "none" refuses other sites open in the owner\'s browser', function () {
    T::$app->config->set('auth.mode', 'none');

    // Another site's page also connects from 127.0.0.1; a "simple" POST needs no CORS preflight.
    (new Client)->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->post('/api/sync/builds')->status(403)->path('code', 'local_only');
    (new Client)->withHeaders(['Origin' => 'https://elsewhere.example'])->get('/api/accounts')->status(403)->path('code', 'local_only');
    (new Client)->withHeaders(['Origin' => 'null'])->get('/api/accounts')->status(403);
    // The owner's pages of the site's strings and languages are the owner's the same way: a page of another
    // site cannot reword a string or add a language through the owner's browser.
    (new Client)->withHeaders(['Origin' => 'https://elsewhere.example'])->request('PUT', '/api/admin/translations', ['values' => []])->status(403)->path('code', 'local_only');
    (new Client)->withHeaders(['Origin' => 'https://elsewhere.example'])->request('PUT', '/api/admin/translations/sk/import', [])->status(403)->path('code', 'local_only');
    (new Client)->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->post('/api/admin/languages', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch'])->status(403)->path('code', 'local_only');
    (new Client)->withHeaders(['Origin' => 'https://elsewhere.example'])->delete('/api/admin/languages/sk')->status(403)->path('code', 'local_only');
    same(['en', 'sk'], db()->column('SELECT code FROM languages ORDER BY code'), 'the languages are as they were');
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

    $admin->get('/api/accounts/abc')->status(404)->path('code', 'not_found');
    $admin->get('/api/sync-runs/abc')->status(404);
    $answer = $admin->post('/api/imports/file', ['content' => '{}', 'account_id' => 'abc'])->status(422);
    check(isset($answer->json()['errors']['account_id']), 'a validation error for account_id: '.$answer->body);
    // Input that did not pass is "invalid", whatever the field; the messages by field stay as they are.
    same('invalid', $answer->json('code'), 'the code of a validation error');
    same(['message', 'code', 'errors'], array_keys($answer->json()), 'the fields of a validation error');
});

test('an unknown auth mode asks for sign-in', function () {
    T::$app->config->set('auth.mode', 'None ');

    (new Client)->get('/api/accounts')->status(401);
    (new Client)->get('/api/meta')->path('auth.mode', 'iam');
});

test('unknown API paths are JSON 404s', function () {
    $answer = (new Client)->get('/api/nothing-here')->status(404);
    same('Not found.', $answer->json('message'), 'message');
    same('not_found', $answer->json('code'), 'code');
    check(str_starts_with($answer->header('Content-Type'), 'application/json'), 'JSON');
});

test('every error a member can meet says what happened as a code, beside its unchanged sentence', function () {
    $member = (new Client)->asIam(3, [Role::MEMBER]);
    $whole = fn (Tests\Support\Answer $answer, int $status, string $code, string $message) => same(
        [$status, ['message' => $message, 'code' => $code]], [$answer->status, $answer->json()], $code);

    $whole((new Client)->get('/api/accounts'), 401, 'sign_in_required', 'Sign in to continue.');
    $whole((new Client)->asIam(7, [])->get('/api/accounts'), 403, 'no_access', 'Your account has no access to the build analyzer. Ask the owner to add you.');
    $whole($member->get('/api/sync'), 403, 'admin_only', 'Only an admin can do this.');
    // Not found three ways: no such path, an id that is not a number, and something that is not there (or not theirs).
    $whole($member->get('/api/nothing-here'), 404, 'not_found', 'Not found.');
    $whole($member->get('/api/accounts/abc'), 404, 'not_found', 'Not found.');
    $whole($member->get('/api/accounts/999'), 404, 'not_found', 'Not found.');
    $whole($member->get('/api/builds/chess/hutao'), 404, 'not_found', 'Not found.');
    $whole($member->delete('/api/meta'), 405, 'method_not_allowed', 'Method not allowed.');

    // Input that did not pass: one code, with the messages by field as before.
    $invalid = $member->post('/api/imports/showcase', ['game' => 'chess', 'uid' => '1'])->status(422)->path('code', 'invalid');
    same(['game', 'uid'], array_keys($invalid->json('errors')), 'the fields');
    same($invalid->json('errors.game.0').' (and 1 more error)', $invalid->json('message'), 'the message is the first one, counting the rest');

    // Too many imports in a minute (the limit counts every attempt, a refused UID too).
    for ($i = 1; $i < 10; $i++) {
        $member->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '1'])->status(422);
    }
    $limited = $member->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '1']);
    $whole($limited, 429, 'too_many_imports', 'That is a lot of imports in one minute. Wait a moment and try again.');
    same('60', $limited->header('Retry-After'), 'Retry-After');

    // Anything the server did not expect: no detail, one code.
    T::$app->set(App\Services\Import\ImportService::class, new class extends App\Services\Import\ImportService
    {
        public function __construct() {}

        public function fromFile(string $contents, App\Models\User $owner, ?App\Enums\Game $expectedGame = null, ?App\Models\Account $target = null, ?string $fileName = null): App\Models\ImportRun
        {
            throw new LogicException('Something of the server\'s own, at C:\\somewhere\\on\\the\\host.');
        }
    });
    try {
        $whole((new Client)->asIam(4, [Role::MEMBER])->post('/api/imports/file', ['content' => '{}']), 500, 'server_error',
            'Something went wrong on the server. Try again, and if it keeps happening, tell the admin.');
    } finally {
        T::$app->forget(App\Services\Import\ImportService::class);
    }

    // The admin-only routes keep their sentences and carry no code of their own.
    $admin = (new Client)->asIam(4, [Role::ADMIN]);
    same(['message' => 'No file arrived. A file larger than this server accepts arrives empty.'],
        $admin->request('POST', '/api/sources/genshin-1/import', null, ['body' => ''])->status(422)->json(), 'an admin-only refusal');
});

test('auth mode "none" refuses another computer with a code too', function () {
    T::$app->config->set('auth.mode', 'none');

    same(['message' => 'Sign-in is switched off on this server (auth mode "none"), so it only answers its own pages on the computer it runs on.', 'code' => 'local_only'],
        (new Client('203.0.113.9'))->get('/api/accounts')->status(403)->json(), 'the whole answer');
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
