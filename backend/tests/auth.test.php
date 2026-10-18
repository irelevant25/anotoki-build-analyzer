<?php

/**
 * Signing in through the anotoki IAM - the only way in, through the anotoki library's auth module: which tokens
 * are accepted, who a request is, what a signed-in person, an EDITOR and an ADMIN may do (route by route), what
 * the IAM's "sign-in required" closes (the library's SignInRule, from the test IAM's file of its answer), that a
 * leftover sign-in "mode" opens nothing, which settings make an installation set up (the site gate's every
 * state: site_gate.test.php), the version meta says, and the development router. Tokens are signed by the
 * library's test IAM, its key set a file (tests/stand_in_iam.test.php signs in through the stand-in IAM itself).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Auth\Role;
use App\Config;
use App\Db\Db;
use App\Db\Migrations;
use App\DevRouter;
use App\Version;
use Tests\Support\Answer;
use Tests\Support\Client;

bootTests();

$users = fn() => (int) db()->value('SELECT count(*) FROM users');
$userName = fn(int $id) => db()->value('SELECT name FROM users WHERE id = ?', [$id]);

/** The editors' routes: syncs, their history, a source's builds as a file, the site's strings. */
const STAFF_ROUTES = [
    ['GET', '/api/sync', null],
    ['POST', '/api/sync/step', []],
    ['POST', '/api/sync/builds', ['force' => 'not a boolean']],
    ['POST', '/api/sync/catalog', ['games' => ['chess']]],
    ['POST', '/api/sources/nowhere-9/sync', []],
    ['POST', '/api/sync-runs/999/cancel', []],
    ['GET', '/api/sync-runs', null],
    ['GET', '/api/sync-runs/999', null],
    ['GET', '/api/sources/genshin-1/export', null],
    ['POST', '/api/sources/genshin-1/import', []],
    ['GET', '/api/admin/languages', null],
    ['GET', '/api/admin/translations', null],
    ['PUT', '/api/admin/translations', ['values' => []]],
    ['GET', '/api/admin/translations/sk/export', null],
    ['PUT', '/api/admin/translations/sk/import', []],
];

/**
 * The admins' own: sources on / off, database updates, the languages themselves, people's data, and the anotoki
 * library's admin tools - the error log, the audit log, feedback, backups (admin_tools.test.php: what each does).
 */
const ADMIN_ROUTES = [
    ['PATCH', '/api/sources/genshin-1', ['enabled' => false]],
    ['GET', '/api/admin/migrations', null],
    ['POST', '/api/admin/migrations/apply', []],
    ['POST', '/api/admin/languages', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']],
    ['PUT', '/api/admin/languages/sk', ['name' => 'Renamed', 'enabled' => false]],
    ['DELETE', '/api/admin/languages/sk', null],
    ['GET', '/api/admin/people', null],
    ['DELETE', '/api/admin/people/5', null],
    ['POST', '/api/admin/people/sync', []],
    ['GET', '/api/admin/errors', null],
    ['DELETE', '/api/admin/errors', null],
    ['GET', '/api/admin/audit', null],
    ['GET', '/api/admin/audit/facets', null],
    ['GET', '/api/admin/feedback', null],
    ['GET', '/api/admin/feedback/unread', null],
    ['PUT', '/api/admin/feedback/1/status', ['status' => 'read']],
    ['DELETE', '/api/admin/feedback/1', null],
    ['GET', '/api/admin/backups', null],
    ['GET', '/api/admin/backups/proof', null],
    ['POST', '/api/admin/backups', []],
];

$refusedAs = function (Answer $answer, int $status, string $code, string $message, string $what): void {
    same([$status, ['message' => $message, 'code' => $code]], [$answer->status, $answer->json()], $what);
};

test('meta is public and says where to sign in and whether the IAM requires it - and nothing about a sign-in mode', function () {
    $meta = (new Client())
        ->get('/api/meta')
        ->status(200)
        ->path('auth.issuer', TEST_ISSUER)
        ->path('auth.client_id', TEST_CLIENT)
        ->path('auth.login_required', false)
        ->path('auth.sign_in_available', true)
        ->path('games.0.key', 'genshin');
    // The library's boot configuration (AuthRoutes::configBody()), snake_case.
    same(['issuer', 'client_id', 'login_required', 'sign_in_available'], array_keys($meta->json('auth')), 'the sign-in part of meta');
    same(['keep_days' => 30], $meta->json('visitors'), 'how long a visitor\'s imports are kept');
    // Read with no token: a bad one is not even looked at, nor a visitor key.
    same(
        $meta->body,
        (new Client())
            ->withToken('x')
            ->withHeaders(['X-Visitor-Key' => 'x'])
            ->get('/api/meta')
            ->status(200)->body,
        'credentials are not read',
    );
});

test('meta says the version people see: MAJOR.MINOR.PATCH, the changelog\'s newest entry and the frontend package\'s', function () {
    $frontend = __DIR__ . '/../../frontend';
    $changelog = json_decode((string) file_get_contents($frontend . '/public/changelog.json'), true);
    $package = json_decode((string) file_get_contents($frontend . '/package.json'), true);
    $threeNumbers = fn(mixed $version): bool => is_string($version) && preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1;
    check($threeNumbers(Version::CURRENT), 'three numbers: ' . Version::CURRENT);
    same(Version::CURRENT, $changelog[0]['version'] ?? null, 'the newest entry of public/changelog.json');
    same(Version::CURRENT, $package['version'] ?? null, 'frontend/package.json');
    same([], array_values(array_filter(array_column($changelog, 'version'), fn($version) => !$threeNumbers($version))), 'every release in the changelog has three numbers');
    (new Client())->get('/api/meta')->status(200)->path('version', Version::CURRENT);
});

test('the site\'s strings are public too: nobody is asked who they are', function () use ($users) {
    // The words are the same for everybody, and a page whose sign-in ran out still reads in its language.
    db()->run("INSERT INTO translation_keys (name) VALUES ('test.public')");
    db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.public', 'en', 'For everybody')");
    try {
        $anybody = (new Client())->get('/api/translations/sk')->status(200)->path('language', 'sk');
        same('For everybody', $anybody->json('values')['test.public'] ?? null, 'the strings');
        same('', $anybody->header('WWW-Authenticate'), 'no sign-in is asked for');
        same($anybody->body, (new Client())->withToken('definitely-not-a-token')->get('/api/translations/sk')->status(200)->body, 'a token that does not check out is not looked at');
        same($anybody->body, (new Client())->withToken(iamToken(5, claims: ['exp' => time() - 120]))->get('/api/translations/sk')->status(200)->body, 'nor one that ran out');
        same($anybody->body, (new Client())->asIam(7, [])->get('/api/translations/sk')->status(200)->body, 'nor somebody with no role here');
        same(
            $anybody->body,
            (new Client('203.0.113.9'))
                ->withHeaders(['Origin' => 'https://elsewhere.example'])
                ->get('/api/translations/sk')
                ->status(200)->body,
            'from anywhere',
        );
        same(0, $users(), 'and nobody was recorded');
    } finally {
        db()->run("DELETE FROM translation_keys WHERE name = 'test.public'");
    }
});

test('the builds and the sources are public: the same for everybody, and nobody is recorded', function () use ($users) {
    seedGenshinCatalog();

    (new Client())->get('/api/builds/genshin')->status(200);
    (new Client())->get('/api/builds/genshin/hutao')->status(200)->path('character.key', 'hutao');
    $sources = (new Client())->get('/api/sources')->status(200);
    check(in_array('Source 1', array_column($sources->json(), 'name'), true), 'the sources: ' . $sources->body);
    same('', $sources->header('WWW-Authenticate'), 'no sign-in is asked for');
    same(0, $users(), 'nobody was recorded');

    // Signed in, the same answers.
    same($sources->body, (new Client())->asIam(7, [])->get('/api/sources')->status(200)->body, 'a signed-in person');
});

test('requests without a valid access token are refused', function () use ($users) {
    $client = new Client();
    $answer = $client->get('/api/accounts')->status(401)->path('message', 'Sign in to continue.')->path('code', 'sign_in_required');
    same('Bearer realm="' . TEST_CLIENT . '"', $answer->header('WWW-Authenticate'), 'WWW-Authenticate');
    same(['message' => 'Sign in to continue.', 'code' => 'sign_in_required'], $answer->json(), 'the whole answer');

    $stranger = strangerIam(); // the same kid, but not the IAM's key
    $refused = [
        'expired' => iamToken(5, claims: ['exp' => time() - 120]),
        'for another application' => iamToken(5, claims: ['aud' => 'genshin']),
        'for another application\'s client' => iamToken(5, claims: ['client_id' => 'genshin']),
        'from another issuer' => iamToken(5, claims: ['iss' => 'https://iam.evil.example']),
        'an ID token' => iamToken(5, typ: 'JWT'),
        'signed by another key' => iamToken(5, iam: $stranger),
        'not valid yet' => iamToken(5, claims: ['nbf' => time() + 600]),
        'without a user id' => iamToken(5, claims: ['sub' => 'admin']),
        'with a header it must understand' => T::$iam->sign(T::$iam->claims(5), header: ['crit' => ['exp']]),
        'unsigned' => T::$iam->sign(T::$iam->claims(5), alg: 'none'),
        'not a JWT' => 'definitely-not-a-token',
    ];
    foreach ($refused as $case => $token) {
        // A token that does not verify is told apart from no token at all: the page signs in again.
        $answer = (new Client())->withToken($token)->get('/api/accounts');
        same([401, 'invalid_token'], [$answer->status, $answer->json('code')], $case);
        same('Bearer realm="' . TEST_CLIENT . '", error="invalid_token"', $answer->header('WWW-Authenticate'), "{$case}: WWW-Authenticate");
    }

    // Nothing in a request makes anybody somebody: no parameter, no header of the site's own, no address.
    same(401, (new Client())->withToken('x')->get('/api/accounts?auth=none&mode=none&user_id=1')->status, 'query parameters');
    same(401, (new Client())->withHeaders(['X-Auth-Mode' => 'none', 'X-User-Id' => '1', 'X-Forwarded-For' => '127.0.0.1'])->get('/api/accounts')->status, 'headers');

    same(0, $users(), 'a refused token creates no user');
});

test('credentials that are sent must verify: never answered as if none were sent, on public routes too', function () use ($users) {
    seedGenshinCatalog();
    $bad = [
        'expired' => ['Authorization' => 'Bearer ' . iamToken(5, claims: ['exp' => time() - 120])],
        'a bad signature' => ['Authorization' => 'Bearer ' . iamToken(5, iam: strangerIam())],
        'garbage' => ['Authorization' => 'Bearer x'],
        'no token after Bearer' => ['Authorization' => 'Bearer '],
        'the word Bearer alone' => ['Authorization' => 'Bearer'],
        'bearer in small letters' => ['Authorization' => 'bearer x'],
    ];
    foreach ($bad as $case => $headers) {
        foreach (['/api/accounts', '/api/builds/genshin', '/api/sources', '/api/sync', '/api/admin/errors', '/api/admin/people'] as $url) {
            $answer = (new Client())->withHeaders($headers)->get($url);
            same([401, 'invalid_token'], [$answer->status, $answer->json('code')], "{$case}: {$url}");
        }
    }

    // The header as Apache under CGI hands it on (api/.htaccess) counts the same.
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/builds/genshin', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer x']);
    same(401, T::$api->handle($request)->getStatusCode(), 'HTTP_AUTHORIZATION');
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/builds/genshin', [
        'REMOTE_ADDR' => '127.0.0.1',
        'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . iamToken(5, claims: ['exp' => time() - 120]),
    ]);
    same(401, T::$api->handle($request)->getStatusCode(), 'REDIRECT_HTTP_AUTHORIZATION');

    same(0, $users(), 'nobody was recorded');
});

test('another scheme than Bearer is no credentials: "Basic" (a host behind a password file) is answered as if nothing was sent', function () use ($users) {
    seedGenshinCatalog();
    $basic = ['Authorization' => 'Basic b3duZXI6b3duZXI='];
    foreach (['/api/builds/genshin' => 200, '/api/sources' => 200, '/api/accounts' => 401, '/api/sync' => 401, '/api/me/export' => 401] as $url => $status) {
        $answer = (new Client())->withHeaders($basic)->get($url);
        same($status, $answer->status, "Basic: {$url}");
        same((new Client())->get($url)->body, $answer->body, "Basic: {$url} answers what no header does");
    }
    same(0, $users(), 'nobody was recorded');
    // (A visitor behind such a host keeps its key: visitors.test.php.)
});

test('a valid token signs the person in and keeps their name current', function () use ($users, $userName) {
    same('[]', (new Client())->asIam(42, [], ['preferred_username' => 'mona'])->get('/api/accounts')->body, 'no accounts yet');
    same('mona', $userName(42), 'name');

    (new Client())
        ->asIam(42, [], ['preferred_username' => 'mona_megistus'])
        ->get('/api/accounts')
        ->status(200);
    same('mona_megistus', $userName(42), 'renamed');
    same(1, $users(), 'one row');
});

test('an ordinary person (MEMBER) uses their own accounts and imports, and nothing of the admin panel', function () {
    // Every anotoki account holds MEMBER: an ordinary signed-in person - no check needs it, none refuses it.
    // No role at all, or a role name from another application, is the same.
    foreach (['MEMBER' => ['MEMBER'], 'no role' => [], 'another application\'s role' => ['OWNER']] as $case => $roles) {
        $person = (new Client())->asIam(7, $roles);
        $person->get('/api/accounts')->status(200);
        $person->get('/api/accounts/999')->status(404)->path('code', 'not_found');
        // The import routes let them through to the input itself.
        $person
            ->post('/api/imports/showcase', ['game' => 'chess', 'uid' => '1'])
            ->status(422)
            ->path('code', 'invalid');
        $person
            ->post('/api/imports/file', ['content' => 'not json'])
            ->status(422)
            ->path('code', 'file_not_json');
        $person->get('/api/sync')->status(403)->path('code', 'editor_only');
        $person->get('/api/admin/migrations')->status(403)->path('code', 'admin_only');
        same([], (new App\Models\User(7, 'x'))->withRoles($roles)->roles(), "{$case}: no role here");
    }
});

test('the editors\' routes refuse a person with no role, and the admins\' refuse an editor - route by route', function () use ($refusedAs) {
    $before = [db()->all('SELECT code, name, enabled FROM languages ORDER BY code'), (int) db()->value('SELECT count(*) FROM sync_runs')];
    $nobody = new Client();
    $person = (new Client())->asIam(3, []);
    $editor = (new Client())->asIam(4, [Role::EDITOR]);

    foreach (STAFF_ROUTES as [$method, $url, $body]) {
        $refusedAs($nobody->request($method, $url, $body), 401, 'sign_in_required', 'Sign in to continue.', "{$method} {$url}, signed out");
        $refusedAs($person->request($method, $url, $body), 403, 'editor_only', 'Only an editor or an admin can do this.', "{$method} {$url}, no role");
        $answer = $editor->request($method, $url, $body);
        check(!in_array($answer->status, [401, 403], true), "{$method} {$url}: an editor is let through ({$answer->status} {$answer->body})");
    }
    foreach (ADMIN_ROUTES as [$method, $url, $body]) {
        $refusedAs($nobody->request($method, $url, $body), 401, 'sign_in_required', 'Sign in to continue.', "{$method} {$url}, signed out");
        $refusedAs($person->request($method, $url, $body), 403, 'admin_only', 'Only an admin can do this.', "{$method} {$url}, no role");
        $refusedAs($editor->request($method, $url, $body), 403, 'admin_only', 'Only an admin can do this.', "{$method} {$url}, an editor");
    }

    // A role in the address or a header of the request is nobody's role.
    $refusedAs($editor->withHeaders(['X-Roles' => 'ADMIN'])->request('GET', '/api/admin/migrations?role=ADMIN'), 403, 'admin_only', 'Only an admin can do this.', 'a role in the request');
    same($before, [db()->all('SELECT code, name, enabled FROM languages ORDER BY code'), (int) db()->value('SELECT count(*) FROM sync_runs')], 'and none of it was done');
});

test('an editor runs the syncs, reads their history, moves a source\'s builds and rewords the strings', function () {
    $editor = (new Client())->asIam(4, [Role::EDITOR]);

    same(['active' => false, 'wait_ms' => null, 'runs' => []], $editor->get('/api/sync')->status(200)->json(), 'no syncs');
    $editor->post('/api/sync/step')->status(200)->path('busy', false)->path('did', null);
    $editor->get('/api/sync-runs')->status(200);
    $file = $editor->get('/api/sources/genshin-1/export')->status(200)->json();
    same('genshin-1', $file['source'] ?? null, 'a source\'s builds as a file');
    $editor->get('/api/admin/languages')->status(200);
    $editor->get('/api/admin/translations')->status(200);
    $editor->get('/api/admin/translations/sk/export')->status(200);

    // An editor sees who started a run on the Sources page; a person with no role and nobody do not.
    $source = (int) db()->value("SELECT min(id) FROM build_sources WHERE game = 'genshin'");
    db()->insert('sync_runs', [
        'build_source_id' => $source,
        'kind' => 'builds',
        'game' => 'genshin',
        'status' => 'success',
        'started_by' => 4,
        'started_at' => App\Support\Clock::now(),
        'finished_at' => App\Support\Clock::now(),
    ]);
    $latest = fn(Client $client) => array_values(array_filter(array_column($client->get('/api/sources')->status(200)->json(), 'latest_run')))[0] ?? [];
    same(4, $latest($editor)['started_by']['id'] ?? null, 'an editor sees who started it');
    same(4, $latest((new Client())->asIam(1, [Role::ADMIN]))['started_by']['id'] ?? null, 'an admin too');
    $hidden = fn(array $run) => [$run['id'] ?? null, array_key_exists('started_by', $run), $run['started_by'] ?? null];
    same([1, true, null], $hidden($latest((new Client())->asIam(3, []))), 'a person with no role sees the run, not who started it');
    same([1, true, null], $hidden($latest(new Client())), 'nobody neither');
});

test('an admin may do everything an editor may, and the admins\' own', function () {
    $admin = (new Client())->asIam(4, [Role::ADMIN]);

    foreach (STAFF_ROUTES as [$method, $url, $body]) {
        $answer = $admin->request($method, $url, $body);
        check(!in_array($answer->status, [401, 403], true), "{$method} {$url}: an admin is let through ({$answer->status})");
    }
    $admin
        ->patch('/api/sources/genshin-1', ['enabled' => false])
        ->status(200)
        ->path('enabled', false)
        ->path('name', 'Source 1');
    $admin->get('/api/admin/migrations')->status(200)->path('pending', []);
    same(['applied' => [], 'failed' => null, 'error' => null, 'note' => null], $admin->post('/api/admin/migrations/apply')->status(200)->json(), 'nothing to apply');
    // One apply at a time: while another holds the lock every apply takes (the command line's, setup's, another
    // tab's), the next is told so and applies nothing.
    $other = Db::pdo(require __DIR__ . '/../config/database.local.php', T::$database, false);
    $other->query('SELECT pg_advisory_lock(' . Migrations::LOCK . ')');
    try {
        same(
            [409, ['code' => 'busy', 'message' => 'Another database update is running. Nothing was applied. Wait a moment, then look again.']],
            [($busy = $admin->post('/api/admin/migrations/apply'))->status, $busy->json()],
            'busy',
        );
    } finally {
        $other->query('SELECT pg_advisory_unlock(' . Migrations::LOCK . ')');
    }
    $admin->get('/api/admin/people')->status(200);
    $admin->delete('/api/admin/people/999')->status(404);
    $admin->get('/api/accounts')->status(200);
});

// ── The IAM's "sign-in required" ────────────────────────────────────────────────────────────────────

test('when the IAM requires sign-in, nobody gets anything but meta, the strings, the icons and the site\'s status - and any token is let in', function () use ($users) {
    seedGenshinCatalog();
    iamRequiresSignIn(true);

    foreach (['/api/builds/genshin', '/api/builds/genshin/hutao', '/api/sources', '/api/accounts', '/api/me/export', '/api/sync'] as $url) {
        $answer = (new Client())->get($url);
        same(
            [401, ['message' => 'Sign in to continue.', 'code' => 'sign_in_required'], 'Bearer realm="' . TEST_CLIENT . '"'],
            [$answer->status, $answer->json(), $answer->header('WWW-Authenticate')],
            "nobody: {$url}",
        );
    }
    $import = (new Client())->post('/api/imports/file', ['content' => '{}']);
    same([401, 'sign_in_required', ''], [$import->status, $import->json('code'), $import->header('X-Visitor-Key')], 'nobody\'s import: refused before its input, and no visitor');
    $feedback = (new Client())->post('/api/feedback', ['kind' => 'idea', 'message' => 'More sources']);
    same([401, 'sign_in_required'], [$feedback->status, $feedback->json('code')], 'nobody\'s feedback too');

    (new Client())->get('/api/meta')->status(200)->path('auth.login_required', true);
    (new Client())->get('/api/translations/en')->status(200)->path('language', 'en');
    (new Client())->get('/api/icons/c/999999')->status(404)->path('code', 'not_found');
    // Where the site stands, for anybody and whatever they send: a 401 here would read as "not available".
    $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    foreach (
        ['nobody' => new Client(), 'a visitor key' => (new Client())->withHeaders(['X-Visitor-Key' => $key]), 'a token that does not verify' => (new Client())->withToken('x')]
        as $who => $client
    ) {
        same(['state' => 'ready', 'installed' => false], $client->get('/api/site-status')->status(200)->json(), "the site's status, {$who}");
    }
    same(0, $users(), 'nobody was recorded');

    // The IAM decided who may sign in: any token that verifies is let in, MEMBER alone or no role at all - also
    // while the IAM does not answer at all (fail closed: required for everybody else).
    foreach ([true, null] as $required) {
        iamRequiresSignIn($required);
        foreach (['MEMBER' => ['MEMBER'], 'no role' => []] as $case => $roles) {
            $person = (new Client())->asIam(7, $roles);
            $person->get('/api/builds/genshin')->status(200);
            $person->get('/api/sources')->status(200);
            $person->get('/api/accounts')->status(200);
            $person->get('/api/admin/migrations')->status(403)->path('code', 'admin_only');
        }
        (new Client())->get('/api/builds/genshin')->status(401)->path('code', 'sign_in_required');
    }
    // A token that does not verify is refused as ever, never answered as nobody.
    (new Client())->withToken('x')->get('/api/translations/en')->status(200);
    (new Client())->withToken('x')->get('/api/builds/genshin')->status(401)->path('code', 'invalid_token');

    iamRequiresSignIn(false);
    (new Client())->get('/api/builds/genshin')->status(200);
    (new Client())->get('/api/meta')->status(200)->path('auth.login_required', false);
});

test('the IAM\'s answer (the library\'s SignInRule, from the test IAM\'s file of it): false opens the site, anything else - no answer, another client, not a boolean - requires sign-in', function () {
    seedGenshinCatalog();
    $file = T::$iam->dir . '/' . Anotoki\Lib\Auth\Testing\TestIam::APPLICATION_FILE;
    $answers = [
        'no answer ever (fail closed)' => [null, true],
        'the IAM says sign-in is not required' => [['client_id' => TEST_CLIENT, 'name' => 'Build Analyzer', 'login_required' => false], false],
        'the IAM says it is required' => [['client_id' => TEST_CLIENT, 'name' => 'Build Analyzer', 'login_required' => true], true],
        'an answer about another application' => [['client_id' => 'genshin', 'name' => 'Genshin', 'login_required' => false], true],
        'not a boolean' => [['client_id' => TEST_CLIENT, 'name' => 'Build Analyzer', 'login_required' => 'no'], true],
    ];
    foreach ($answers as $case => [$answer, $required]) {
        $answer === null ? @unlink($file) : file_put_contents($file, json_encode($answer));
        (new Client())->get('/api/meta')->status(200)->path('auth.login_required', $required);
        same($required ? 401 : 200, (new Client())->get('/api/builds/genshin')->status, "{$case}: the builds for nobody");
    }
});

test('a settings file that still says sign-in mode "none" opens nothing', function () use ($userName) {
    // What an older setup wrote for a copy without sign-in: the IAM named, and a mode and an owner nobody reads.
    T::$app->config->set('auth.mode', 'none');
    T::$app->config->set('auth.local_user_id', 9);

    foreach (['/api/accounts', '/api/me/export', '/api/sync', '/api/admin/translations', '/api/admin/migrations', '/api/admin/people'] as $url) {
        (new Client())->get($url)->status(401)->path('code', 'sign_in_required');
        (new Client())->get('http://127.0.0.1:8400' . $url)->status(401);
    }
    // Nobody's import is a visitor's (which a file that is no export does not make), never the old owner's.
    $import = (new Client())
        ->post('/api/imports/file', ['content' => '{}'])
        ->status(422)
        ->path('code', 'file_unknown_format');
    same('', $import->header('X-Visitor-Key'), 'no visitor made');
    same(0, (int) db()->value('SELECT count(*) FROM users'), 'nobody recorded');
    same(['issuer', 'client_id', 'login_required', 'sign_in_available'], array_keys((new Client())->get('/api/meta')->json('auth')), 'meta says nothing of it');
    same(null, $userName(9), 'no row for the old owner');

    // A token is needed as anywhere, and is enough.
    (new Client())->asIam(5, [])->get('/api/accounts')->status(200);
    (new Client())->asIam(5, [])->get('/api/me/export')->status(200)->path('accounts', []);
    (new Client())->asIam(5, [])->get('/api/sync')->status(403);

    // The same from the files themselves: such an auth.local.php, and no fetch.local.php, is set up - and asks for a token.
    $old = T::$storage . DIRECTORY_SEPARATOR . 'old-none';
    @mkdir($old . '/config', 0777, true);
    copy(__DIR__ . '/../config/app.php', $old . '/config/app.php');
    file_put_contents($old . '/config/database.local.php', '<?php return ' . var_export(T::$app->config->get('database'), true) . ';');
    file_put_contents($old . '/config/auth.local.php', "<?php return ['mode' => 'none', 'local_user_id' => 1, 'issuer' => 'http://localhost:4300', 'client_id' => 'build-analyzer'];");
    $config = Config::load($old);
    same([], $config->problems(), 'set up');
    // The test IAM's answer (sign-in not required) here too: this App is not T::$app, and its own reader would
    // ask the issuer above - the owner's dev IAM - which no test may do.
    $app = new App\App($config, web: true);
    $app->set(Anotoki\Lib\Auth\SignInRule::class, new Anotoki\Lib\Auth\SignInRule(T::$iam->client()));
    $api = buildAnalyzerApi($app);
    foreach (['/api/accounts', '/api/sync', '/api/admin/translations'] as $url) {
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $url, ['REMOTE_ADDR' => '127.0.0.1'])->withHeader('Host', 'localhost');
        same(401, $api->handle($request)->getStatusCode(), "{$url} from this computer");
    }
    same([], glob($config->storage('cache/iam') . '/anotoki-iam-application-*') ?: [], 'and no IAM was asked');
});

test('no other site may read or call the API', function () {
    // Same origin only: no CORS answer for anybody else, so browsers keep other sites out.
    $preflight = (new Client())->withHeaders(['Origin' => 'https://elsewhere.example', 'Access-Control-Request-Method' => 'DELETE'])->request('OPTIONS', '/api/accounts/1');
    same('', $preflight->header('Access-Control-Allow-Origin'), 'preflight');

    $answer = (new Client())
        ->withHeaders(['Origin' => 'https://elsewhere.example'])
        ->get('/api/meta')
        ->status(200);
    same('', $answer->header('Access-Control-Allow-Origin'), 'a simple request');
});

test('ids in paths are numbers', function () {
    $admin = (new Client())->asIam(3, [Role::ADMIN]);

    $admin->get('/api/accounts/abc')->status(404)->path('code', 'not_found');
    $admin->get('/api/sync-runs/abc')->status(404);
    $admin->delete('/api/admin/people/abc')->status(404);
    $admin->delete('/api/admin/people/-1')->status(404);
    $answer = $admin->post('/api/imports/file', ['content' => '{}', 'account_id' => 'abc'])->status(422);
    check(isset($answer->json()['errors']['account_id']), 'a validation error for account_id: ' . $answer->body);
    // Input that did not pass is "invalid", whatever the field; the messages by field stay as they are.
    same('invalid', $answer->json('code'), 'the code of a validation error');
    same(['message', 'code', 'errors'], array_keys($answer->json()), 'the fields of a validation error');
});

test('unknown API paths are JSON 404s', function () {
    $answer = (new Client())->get('/api/nothing-here')->status(404);
    same('Not found.', $answer->json('message'), 'message');
    same('not_found', $answer->json('code'), 'code');
    check(str_starts_with($answer->header('Content-Type'), 'application/json'), 'JSON');
});

test('every error a signed-in person can meet says what happened as a code, beside its unchanged sentence', function () use ($refusedAs) {
    $person = (new Client())->asIam(3, []);

    $refusedAs((new Client())->get('/api/accounts'), 401, 'sign_in_required', 'Sign in to continue.', 'signed out');
    $refusedAs($person->get('/api/sync'), 403, 'editor_only', 'Only an editor or an admin can do this.', 'not an editor');
    $refusedAs((new Client())->asIam(3, [Role::EDITOR])->get('/api/admin/migrations'), 403, 'admin_only', 'Only an admin can do this.', 'not an admin');
    // Not found three ways: no such path, an id that is not a number, and something that is not there (or not theirs).
    $refusedAs($person->get('/api/nothing-here'), 404, 'not_found', 'Not found.', 'no such path');
    $refusedAs($person->get('/api/accounts/abc'), 404, 'not_found', 'Not found.', 'not a number');
    $refusedAs($person->get('/api/accounts/999'), 404, 'not_found', 'Not found.', 'not there');
    $refusedAs($person->get('/api/builds/chess/hutao'), 404, 'not_found', 'Not found.', 'no such game');
    $refusedAs($person->delete('/api/meta'), 405, 'method_not_allowed', 'Method not allowed.', 'no such method');

    // Input that did not pass: one code, with the messages by field as before.
    $invalid = $person
        ->post('/api/imports/showcase', ['game' => 'chess', 'uid' => '1'])
        ->status(422)
        ->path('code', 'invalid');
    same(['game', 'uid'], array_keys($invalid->json('errors')), 'the fields');
    same($invalid->json('errors.game.0') . ' (and 1 more error)', $invalid->json('message'), 'the message is the first one, counting the rest');

    // Too many imports in a minute (the limit counts every attempt, a refused UID too).
    for ($i = 1; $i < 10; $i++) {
        $person->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '1'])->status(422);
    }
    $limited = $person->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '1']);
    $refusedAs($limited, 429, 'too_many_imports', 'That is a lot of imports in one minute. Wait a moment and try again.', 'too many');
    same('60', $limited->header('Retry-After'), 'Retry-After');

    // Anything the server did not expect: no detail, one code.
    T::$app->set(
        App\Services\Import\ImportService::class,
        new class extends App\Services\Import\ImportService {
            public function __construct() {}

            public function fromFile(string $contents, App\Models\User $owner, ?App\Enums\Game $expectedGame = null, ?App\Models\Account $target = null, ?string $fileName = null): App\Models\ImportRun
            {
                throw new LogicException('Something of the server\'s own, at C:\\somewhere\\on\\the\\host.');
            }
        },
    );
    try {
        $refusedAs(
            (new Client())->asIam(4, [])->post('/api/imports/file', ['content' => '{}']),
            500,
            'server_error',
            'Something went wrong on the server. Try again, and if it keeps happening, tell the admin.',
            'the server\'s own',
        );
    } finally {
        T::$app->forget(App\Services\Import\ImportService::class);
    }

    // The admin panel's routes keep their sentences and carry no code of their own.
    $admin = (new Client())->asIam(4, [Role::ADMIN]);
    same(
        ['message' => 'No file arrived. A file larger than this server accepts arrives empty.'],
        $admin
            ->request('POST', '/api/sources/genshin-1/import', null, ['body' => ''])
            ->status(422)
            ->json(),
        'an admin panel refusal',
    );
});

test('the development router serves the app for its own paths only', function () {
    $app = T::$storage . DIRECTORY_SEPARATOR . 'browser';
    @mkdir($app, 0777, true);
    file_put_contents($app . '/index.html', '<!doctype html><title>app</title>');
    file_put_contents($app . '/main-ABC123.js', 'console.log(1)');

    same('api', DevRouter::resolve('/api/meta', $app)[0], '/api');
    same('setup', DevRouter::resolve('/setup.php', $app)[0], '/setup.php');
    same('app', DevRouter::resolve('/', $app)[0], '/');
    same('app', DevRouter::resolve('/accounts/3', $app)[0], 'an app path');
    same('app', DevRouter::resolve('/auth/callback', $app)[0], 'the sign-in callback');
    same('file', DevRouter::resolve('/main-ABC123.js', $app)[0], 'a file of the build');
    // A missing asset (an old hashed chunk after a deploy) is not answered with the app.
    same('missing', DevRouter::resolve('/main-OLDHASH.js', $app)[0], 'a missing file');
    same('missing', DevRouter::resolve('/../config/database.local.php', $app)[0], 'nothing outside the build');
    same('no-app', DevRouter::resolve('/', T::$storage . '/nothing')[0], 'no build');
});

test('an installation that is not set up answers 503 not_set_up everywhere', function () {
    $empty = T::$storage . DIRECTORY_SEPARATOR . 'empty';
    @mkdir($empty . '/config', 0777, true);
    $config = Config::load($empty);
    check(!$config->isSetUp(), 'no local config files');
    // fetch.local.php is not among them: it is optional.
    same(['config/database.local.php is missing', 'config/auth.local.php is missing'], $config->problems(), 'problems');

    $api = buildAnalyzerApi(new App\App($config));
    foreach (['/api/meta', '/api/accounts', '/api/sync'] as $path) {
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $api->handle($request);
        same(503, $response->getStatusCode(), $path);
        $body = json_decode((string) $response->getBody(), true);
        same('not_set_up', $body['code'] ?? null, "{$path} code");
        same('/setup.php', $body['setup'] ?? null, "{$path} points at setup.php");
    }
});

test('a local config file that is incomplete is not a set-up installation', function () {
    $half = T::$storage . DIRECTORY_SEPARATOR . 'half';
    @mkdir($half . '/config', 0777, true);
    file_put_contents($half . '/config/database.local.php', "<?php return ['host' => '127.0.0.1', 'port' => '5432', 'database' => 'x', 'username' => 'u'];");
    // An older file that says how to sign in but not where: not set up.
    file_put_contents($half . '/config/auth.local.php', "<?php return ['mode' => 'iam'];");

    $problems = Config::load($half)->problems();
    check(in_array('config/auth.local.php has no issuer (the IAM\'s address)', $problems, true), implode('; ', $problems));
    check(in_array('config/auth.local.php has no client_id', $problems, true), implode('; ', $problems));

    file_put_contents($half . '/config/fetch.local.php', "<?php return 'not an array';");
    $problems = Config::load($half)->problems();
    check(in_array('config/fetch.local.php does not return an array', $problems, true), 'a fetch.local.php that is there must be right: ' . implode('; ', $problems));
});

test('without fetch.local.php the outgoing requests use the defaults; with it, its own values over them', function () {
    $root = T::$storage . DIRECTORY_SEPARATOR . 'no-fetch';
    @mkdir($root . '/config', 0777, true);
    copy(__DIR__ . '/../config/app.php', $root . '/config/app.php');
    file_put_contents($root . '/config/database.local.php', "<?php return ['host' => '127.0.0.1', 'port' => '5432', 'database' => 'x', 'username' => 'u'];");
    file_put_contents($root . '/config/auth.local.php', "<?php return ['issuer' => 'https://iam.example.test', 'client_id' => 'build-analyzer'];");

    $config = Config::load($root);
    same([], $config->problems(), 'set up without it');
    same(
        ['build-analyzer/1.0', 1200, 12, 20, 20],
        [$config->get('fetch.user_agent'), $config->get('fetch.delay_ms'), $config->get('fetch.cache_hours'), $config->get('fetch.step_seconds'), $config->get('fetch.stale_hours')],
        'the defaults',
    );

    file_put_contents($root . '/config/fetch.local.php', "<?php return ['user_agent' => 'build-analyzer/1.0 (https://builds.example.test)', 'delay_ms' => 2500];");
    $config = Config::load($root);
    same(['build-analyzer/1.0 (https://builds.example.test)', 2500, 12], [$config->get('fetch.user_agent'), $config->get('fetch.delay_ms'), $config->get('fetch.cache_hours')], 'its own values');
});

test('the browser check\'s settings folder stands in for config/ and storage/ - for a throwaway database only', function () {
    $root = T::$storage . DIRECTORY_SEPARATOR . 'with-own';
    $check = T::$storage . DIRECTORY_SEPARATOR . 'check-settings';
    @mkdir($root . '/config', 0777, true);
    @mkdir($check, 0777, true);
    copy(__DIR__ . '/../config/app.php', $root . '/config/app.php');
    // The checkout's own settings: what the variable must never fall back to.
    file_put_contents($root . '/config/database.local.php', "<?php return ['host' => '127.0.0.1', 'port' => '5432', 'database' => 'owners_db', 'username' => 'u'];");
    file_put_contents($root . '/config/auth.local.php', "<?php return ['issuer' => 'https://iam.example.test', 'client_id' => 'build-analyzer'];");
    file_put_contents($check . '/auth.local.php', "<?php return ['issuer' => 'http://127.0.0.1:8597', 'client_id' => 'build-analyzer'];");

    try {
        putenv(Config::TEST_CONFIG_ENV . '=' . $check);
        file_put_contents($check . '/database.local.php', "<?php return ['host' => '127.0.0.1', 'port' => '5432', 'database' => 'build_analyzer_test_browser', 'username' => 'u'];");
        $config = Config::load($root);
        same([], $config->problems(), 'set up from the folder');
        same(['build_analyzer_test_browser', 'http://127.0.0.1:8597'], [$config->get('database.database'), $config->get('auth.issuer')], 'its database and its IAM');
        same(str_replace('\\', '/', $check) . '/storage/logs/app.log', $config->storage('logs/app.log'), 'and its storage, never backend/storage');
        check(is_array($config->get('aliases')), 'config/app.php is still the checkout\'s');

        // Pointed at any other database - the owner's - it is not set up at all, rather than that database.
        file_put_contents($check . '/database.local.php', "<?php return ['host' => '127.0.0.1', 'port' => '5432', 'database' => 'owners_db', 'username' => 'u'];");
        $config = Config::load($root);
        check(!$config->isSetUp(), 'not set up');
        same(['BUILD_ANALYZER_TEST_CONFIG names a database that is not a build_analyzer_test_ one'], $config->problems(), 'and says why');
        same(null, $config->get('database.database'), 'with no database at all');
    } finally {
        putenv(Config::TEST_CONFIG_ENV);
    }

    same('owners_db', Config::load($root)->get('database.database'), 'without the variable: the checkout\'s own settings');
});

finishTests();
