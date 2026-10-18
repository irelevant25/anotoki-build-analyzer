<?php

/**
 * Signing in through the anotoki family's stand-in IAM (the anotoki library's backend/testing/stand-in-iam.php, in
 * vendor/), the way a browser does: discovery, the authorize redirect with PKCE, the code for tokens, a refresh -
 * and the access token it hands out is let into this API by the same checker that checks the real IAM's (the
 * library's AccessTokens), its keys downloaded from the stand-in - its public answer about the application,
 * which the site reads to know whether sign-in is required (the library's SignInRule, kept for a minute, failing
 * closed), the sign-in hint it sets and clears as the IAM does (which this API never reads: the pages' silent
 * sign-in is the library's Angular half), and its list of the accounts it deleted, which the scrub reads. This is
 * what the browser check signs in with; the other test files sign their own tokens and give a fixed answer.
 *
 * The stand-in runs as `php -S 127.0.0.1:<port>` on a throwaway port (8590-8599) and is stopped at the end.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Auth\Testing\StandInIam;
use Anotoki\Lib\Auth\Testing\TestIam;
use Tests\Support\Client;

bootTests();

/** A free throwaway port: 8590-8599, never the owner's 4200-4700 or 8000-8500. */
function throwawayPort(): int
{
    foreach (range(8590, 8599) as $port) {
        $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);
        if ($socket === false) {
            return $port;
        }
        fclose($socket);
    }
    throw new RuntimeException('No free port in 8590-8599.');
}

/** @return array{status: int, headers: array<string, string>, cookies: list<string>, body: string} */
function http(string $method, string $url, ?array $form = null, ?string $cookie = null): array
{
    $headers = [];
    $cookies = [];
    $curl = curl_init($url);
    curl_setopt_array(
        $curl,
        [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => $cookie === null ? [] : ["Cookie: {$cookie}"],
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers, &$cookies): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                    if (strtolower(trim($name)) === 'set-cookie') {
                        $cookies[] = trim($value);
                    }
                }

                return strlen($line);
            },
        ] + ($form === null ? [] : [CURLOPT_POSTFIELDS => http_build_query($form)]),
    );
    $body = (string) curl_exec($curl);

    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'cookies' => $cookies, 'body' => $body];
}

$dir = T::$storage . DIRECTORY_SEPARATOR . 'stand-in-iam';
$port = throwawayPort();
$issuer = "http://127.0.0.1:{$port}";
// Its key pair and files: the library's test IAM under that issuer (the stand-in signs with its key.json).
$standIn = TestIam::create($dir, $issuer, TEST_CLIENT);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", StandInIam::script()],
    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $dir . '/server.log', 'a']],
    $pipes,
    $dir,
    ['STAND_IN_DIR' => $standIn->dir, 'STAND_IN_ISSUER' => $issuer] + getenv(),
);
if (!is_resource($process)) {
    fwrite(STDERR, "The stand-in IAM did not start.\n");
    exit(1);
}
register_shutdown_function(static function () use ($process): void {
    proc_terminate($process);
    @proc_close($process);
});
for ($i = 0; $i < 100 && http('GET', "{$issuer}/.well-known/openid-configuration")['status'] !== 200; $i++) {
    usleep(50000);
}

/** $changes merged into the stand-in's state; the state that results (its request log among it). */
function standInState(string $issuer, array $changes = []): array
{
    $curl = curl_init("{$issuer}/_test/state");
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object) $changes), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);

    return (array) json_decode((string) curl_exec($curl), true);
}

/** The stand-in's person for the next sign-in. */
function standInUser(string $issuer, array $user, array $more = []): void
{
    standInState($issuer, ['user' => $user] + $more);
}

/**
 * Signs in as a browser does - authorize with PKCE, then the code for tokens; $prompt 'none' as the sign-in hint's
 * silent hop asks, with the browser's cookies.
 *
 * @return array<string, mixed> the token answer, or ['error' => ...] from the redirect - with the cookies authorize set
 */
function signIn(string $issuer, ?string $prompt = null, ?string $cookie = null): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $redirect = 'http://localhost:4600/auth/callback';
    $authorize = http(
        'GET',
        "{$issuer}/api/oidc/authorize?" .
            http_build_query(
                [
                    'response_type' => 'code',
                    'client_id' => TEST_CLIENT,
                    'redirect_uri' => $redirect,
                    'scope' => 'openid profile email',
                    'state' => 'st',
                    'nonce' => 'no',
                    'code_challenge' => $challenge,
                    'code_challenge_method' => 'S256',
                ] + ($prompt === null ? [] : ['prompt' => $prompt]),
            ),
        cookie: $cookie,
    );
    same(302, $authorize['status'], 'authorize redirects back');
    parse_str((string) parse_url($authorize['headers']['location'] ?? '', PHP_URL_QUERY), $query);
    if (isset($query['error'])) {
        return ['error' => $query['error'], 'cookies' => $authorize['cookies']];
    }
    same('st', $query['state'] ?? null, 'the state comes back');

    $token = http('POST', "{$issuer}/api/oidc/token", [
        'grant_type' => 'authorization_code',
        'client_id' => TEST_CLIENT,
        'code' => $query['code'] ?? '',
        'redirect_uri' => $redirect,
        'code_verifier' => $verifier,
    ]);
    same(200, $token['status'], 'the code for tokens: ' . $token['body']);

    return (array) json_decode($token['body'], true) + ['cookies' => $authorize['cookies']];
}

/** The hint a Set-Cookie header sets: [name, value] - the value '' when it clears it; null when it says nothing of it. */
function hintOf(array $cookies): ?array
{
    foreach ($cookies as $cookie) {
        if (preg_match('/^anotoki_signed_in=([^;]*)/', $cookie, $m)) {
            return ['anotoki_signed_in', $m[1]];
        }
    }

    return null;
}

// The API checks tokens against the stand-in, its keys downloaded from it as from the real IAM, and asks it
// whether sign-in is required and whom it deleted. The stand-in starts as this site is set: visitors welcome.
$asksTheStandIn = static function () use ($issuer): void {
    T::$app->config->set('auth.issuer', $issuer);
    T::$app->config->set('auth.jwks_file', null);
    T::$app->config->set('auth.application_file', null);
    T::$app->config->set('auth.anonymized_file', null);
};
// The people module is made once with the API, as on the host, with the IAM the configuration names then: the
// API made again, so that the scrub asks the stand-in too.
$asksTheStandIn();
T::$app->resetScope();
T::$app->forget(Anotoki\Lib\People\People::class);
T::$api = buildAnalyzerApi(T::$app);

beforeEach(function () use ($issuer, $asksTheStandIn): void {
    $asksTheStandIn();
    standInState($issuer, [
        'login_required' => false,
        'application_missing' => false,
        'down' => false,
        'deny' => false,
        'refuse' => false,
        'session' => true,
        'interaction' => false,
        'anonymized' => [],
    ]);
});

test('the stand-in answers as an IAM: discovery, its keys, and the authorize redirect', function () use ($issuer, $standIn) {
    $discovery = json_decode(http('GET', "{$issuer}/.well-known/openid-configuration")['body'], true);
    same([$issuer, "{$issuer}/.well-known/jwks.json", "{$issuer}/api/oidc/token"], [$discovery['issuer'] ?? null, $discovery['jwks_uri'] ?? null, $discovery['token_endpoint'] ?? null], 'discovery');
    same($standIn->jwks(), json_decode(http('GET', "{$issuer}/.well-known/jwks.json")['body'], true), 'the key set');
});

test('an ADMIN signed in at the stand-in is an admin here; a MEMBER is an ordinary person', function () use ($issuer) {
    standInUser($issuer, ['id' => 1, 'username' => 'owner', 'roles' => ['MEMBER', 'ADMIN']]);
    $tokens = signIn($issuer);
    check(is_string($tokens['access_token'] ?? null) && is_string($tokens['refresh_token'] ?? null), 'tokens');
    $owner = (new Client())->withToken($tokens['access_token']);
    $owner->get('/api/admin/migrations')->status(200);
    $owner->get('/api/admin/people')->status(200)->path('people.0.name', 'owner');
    $owner->get('/api/admin/errors')->status(200);

    standInUser($issuer, ['id' => 7, 'username' => 'mona', 'roles' => ['MEMBER']]);
    $mona = (new Client())->withToken(signIn($issuer)['access_token'] ?? '');
    $mona->get('/api/accounts')->status(200);
    $mona->get('/api/sync')->status(403)->path('code', 'editor_only');
    $mona->get('/api/admin/backups')->status(403)->path('code', 'admin_only');

    standInUser($issuer, ['id' => 4, 'username' => 'eda', 'roles' => ['MEMBER', 'EDITOR']]);
    $editor = (new Client())->withToken(signIn($issuer)['access_token'] ?? '');
    $editor->get('/api/sync')->status(200);
    $editor->get('/api/admin/migrations')->status(403)->path('code', 'admin_only');
    $editor->get('/api/admin/feedback')->status(403)->path('code', 'admin_only');

    same(['eda', 'mona', 'owner'], db()->column('SELECT name FROM users ORDER BY name'), 'each named from the token');
    same(3, (int) db()->value('SELECT count(*) FROM users WHERE id > 0 AND last_seen_at IS NOT NULL'), 'and seen now (the library\'s RecordPerson)');
});

test('a refreshed token is let in; a token of another IAM is not', function () use ($issuer) {
    standInUser($issuer, ['id' => 5, 'username' => 'amber', 'roles' => ['MEMBER']]);
    $tokens = signIn($issuer);
    $refreshed = json_decode(http('POST', "{$issuer}/api/oidc/token", ['grant_type' => 'refresh_token', 'client_id' => TEST_CLIENT, 'refresh_token' => $tokens['refresh_token']])['body'], true);
    check(($refreshed['access_token'] ?? $tokens['access_token']) !== $tokens['access_token'], 'a new access token');
    (new Client())->withToken($refreshed['access_token'])->get('/api/accounts')->status(200);

    // Signed by another key, naming the IAM the tests otherwise use: not this IAM's.
    (new Client())->withToken(iamToken(5))->get('/api/accounts')->status(401)->path('code', 'invalid_token');
});

/** A token's claims, read without verifying - the stand-in's own. @return array<string, mixed> */
function claimsOf(string $jwt): array
{
    return (array) json_decode((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);
}

/**
 * POST /api/oidc/preferences as a site's page sends it (the library's savePreferences()): JSON, the bearer token,
 * from the site's origin.
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function savePreferences(string $issuer, string $json, ?string $token): array
{
    $headers = [];
    $curl = curl_init("{$issuer}/api/oidc/preferences");
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Origin: http://localhost:4600'], $token === null ? [] : ["Authorization: Bearer {$token}"]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        },
    ]);
    $body = (string) curl_exec($curl);

    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
}

test("the account's theme, dates and clock as an IAM with its migration 015 keeps them: in the tokens, saved by a site, carried by a refresh", function () use ($issuer) {
    $kept = ['theme' => 'dark', 'date_format' => 'ymd_dash', 'time_format' => null];
    standInUser($issuer, ['id' => 12, 'username' => 'pia', 'roles' => ['MEMBER'], 'locale' => 'en', 'preferences' => $kept]);
    $tokens = signIn($issuer);
    same($kept, claimsOf($tokens['access_token'])['preferences'] ?? null, 'the access token says them');
    same($kept, claimsOf($tokens['id_token'])['preferences'] ?? null, 'and the ID token');
    (new Client())->withToken($tokens['access_token'])->get('/api/accounts')->status(200);

    $saved = savePreferences($issuer, '{"theme":"light","language":"sk"}', $tokens['access_token']);
    same(200, $saved['status'], 'saved: ' . $saved['body']);
    same(['locale' => 'sk', 'preferences' => ['theme' => 'light', 'date_format' => 'ymd_dash', 'time_format' => null]], json_decode($saved['body'], true), 'the account as it now stands');
    same('http://localhost:4600', $saved['headers']['access-control-allow-origin'] ?? null, "answered to the site's origin");
    $refreshed = json_decode(http('POST', "{$issuer}/api/oidc/token", ['grant_type' => 'refresh_token', 'client_id' => TEST_CLIENT, 'refresh_token' => $tokens['refresh_token']])['body'], true);
    same(['sk', 'light'], [claimsOf($refreshed['access_token'])['locale'] ?? null, claimsOf($refreshed['access_token'])['preferences']['theme'] ?? null], 'a refreshed token carries the change');

    foreach (
        [
            '{"theme":"pink"}' => 'a theme not on offer',
            '{"language":"de"}' => 'a language the IAM does not offer',
            '{"colour":"red"}' => 'anything else',
            '{}' => 'nothing at all',
            '[]' => 'not an object',
        ]
        as $json => $what
    ) {
        $refused = savePreferences($issuer, $json, $refreshed['access_token']);
        same([400, 'invalid_request'], [$refused['status'], json_decode($refused['body'], true)['error'] ?? null], "refused: {$what}");
    }
    same(401, savePreferences($issuer, '{"theme":"dark"}', null)['status'], 'no token, no account');

    standInUser($issuer, ['id' => 13, 'username' => 'olga', 'roles' => ['MEMBER']]);
    $older = signIn($issuer);
    check(!array_key_exists('preferences', claimsOf($older['access_token'])), 'an IAM before 015: no claim');
    same(503, savePreferences($issuer, '{"theme":"dark"}', $older['access_token'])['status'], 'and nowhere to save them');
});

test('a person the stand-in refuses comes back with access_denied, and nothing here is recorded', function () use ($issuer) {
    standInUser($issuer, ['id' => 6, 'username' => 'nobody', 'roles' => ['MEMBER']], ['deny' => true]);
    same('access_denied', signIn($issuer)['error'] ?? null, 'refused at the IAM');
    same(0, (int) db()->value('SELECT count(*) FROM users'), 'nobody recorded');
});

// ── The sign-in hint: what the pages' silent sign-in rides on (the IAM's side, as the stand-in plays it) ──

test('the sign-in hint: set where a session opens, a silent sign-in answered from it - and gone with the session', function () use ($issuer) {
    standInUser($issuer, ['id' => 7, 'username' => 'mona', 'roles' => ['MEMBER']]);
    // A sign-in at the IAM (on another anotoki site, say) opens a session there, and sets the hint: a random
    // value, readable by script, saying somebody signed in - never who.
    $atIam = http('GET', "{$issuer}/_test/sign-in?return=" . rawurlencode('http://localhost:4600/'));
    $hint = hintOf($atIam['cookies']);
    check($hint !== null && preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $hint[1]) === 1, 'the hint, in its shape: ' . json_encode($atIam['cookies']));
    check(!str_contains(strtolower(implode(' ', $atIam['cookies'])), 'httponly'), 'readable by the pages\' script');

    // The pages' silent hop (prompt=none): the session at the IAM answers with a code, and the tokens are mona's.
    $silent = signIn($issuer, prompt: 'none', cookie: "{$hint[0]}={$hint[1]}");
    check(is_string($silent['access_token'] ?? null), 'signed in without a click: ' . json_encode(array_keys($silent)));
    (new Client())->withToken($silent['access_token'])->get('/api/accounts')->status(200);
    same('mona', db()->value('SELECT name FROM users WHERE id = 7'), 'and recorded as anybody signed in');

    // The hint opens nothing here: sent as a cookie, it is nobody - this API reads no cookie at all.
    $withHint = (new Client())->withHeaders(['Cookie' => "{$hint[0]}={$hint[1]}"]);
    $withHint->get('/api/accounts')->status(401)->path('code', 'sign_in_required');
    $withHint->get('/api/me/export')->status(401)->path('code', 'sign_in_required');
    $withHint->get('/api/admin/people')->status(401)->path('code', 'sign_in_required');

    // Signed out at the IAM: the hint is cleared, and a silent hop now comes back without a sign-in (the pages
    // then stay a visitor's, and say nothing).
    $out = http('GET', "{$issuer}/_test/sign-out?return=" . rawurlencode('http://localhost:4600/'), cookie: "{$hint[0]}={$hint[1]}");
    same(['anotoki_signed_in', ''], hintOf($out['cookies']), 'the hint cleared');
    same('login_required', signIn($issuer, prompt: 'none', cookie: "{$hint[0]}={$hint[1]}")['error'] ?? null, 'a stale hint: no session behind it');
});

test('the boot configuration tells the pages whether the hint may act: visitors welcome and a site the IAM knows', function () use ($issuer) {
    $auth = fn(): array => (array) (new Client())->get('/api/meta')->status(200)->json('auth');
    $kept = T::$storage . '/cache/iam/anotoki-iam-application-' . sha1($issuer . ' ' . TEST_CLIENT) . '.json';
    @unlink($kept);
    same(['issuer' => $issuer, 'client_id' => TEST_CLIENT, 'login_required' => false, 'sign_in_available' => true], $auth(), 'visitors welcome: the hint may act');

    @unlink($kept);
    standInState($issuer, ['login_required' => true]);
    same([true, true], [$auth()['login_required'], $auth()['sign_in_available']], 'sign-in required: the pages send people to the IAM as ever');

    @unlink($kept);
    standInState($issuer, ['login_required' => false, 'application_missing' => true]);
    same([true, false], [$auth()['login_required'], $auth()['sign_in_available']], 'an application the IAM does not know: no sign-in offered, the hint sends nobody there');
});

// ── Whether the site requires sign-in: the IAM's public answer ─────────────────────────────────────

test('the stand-in answers the public application route: the switch, missing, down, its cache header', function () use ($issuer) {
    $url = "{$issuer}/api/public/applications/" . TEST_CLIENT;
    $answer = http('GET', $url);
    same(200, $answer['status'], 'answered');
    same(['client_id' => TEST_CLIENT, 'name' => 'Test site', 'login_required' => false], json_decode($answer['body'], true), 'visitors welcome, as this site is set');
    same('public, max-age=60', $answer['headers']['cache-control'] ?? null, 'cacheable for a minute');
    standInState($issuer, ['login_required' => true]);
    same(true, json_decode(http('GET', $url)['body'], true)['login_required'], 'switched on');
    standInState($issuer, ['application_missing' => true]);
    same(404, http('GET', $url)['status'], 'an unknown application');
    standInState($issuer, ['application_missing' => false, 'down' => true]);
    same(503, http('GET', $url)['status'], 'the IAM out of reach');
});

test('the site reads the switch from the IAM: kept a minute (one fetch), 404 = required, out of reach = the last value, never read = required', function () use ($issuer) {
    seedGenshinCatalog();
    $kept = T::$storage . '/cache/iam/anotoki-iam-application-' . sha1($issuer . ' ' . TEST_CLIENT) . '.json';
    @unlink($kept);
    $fetches = fn(): int => count(array_filter(standInState($issuer)['log'] ?? [], fn($line) => $line === 'GET /api/public/applications/' . TEST_CLIENT));
    // As if the minute had passed: what the site kept, read and tried that long ago.
    $age = function () use ($kept): void {
        $record = (array) json_decode((string) file_get_contents($kept), true);
        foreach (['fetched_at', 'failed_at', 'tried_at'] as $at) {
            $record[$at] = ($record[$at] ?? 0) > 0 ? $record[$at] - 120 : 0;
        }
        file_put_contents($kept, json_encode($record));
    };
    // A request from nobody: what meta says, and whether the builds are theirs to see.
    $nobody = fn(): array => [(new Client())->get('/api/meta')->status(200)->json('auth.login_required'), (new Client())->get('/api/builds/genshin')->status];
    // The library's own lines (why it answered as it did) go to the site's log, as api/index.php sends them.
    $log = T::$storage . DIRECTORY_SEPARATOR . 'sign-in-answer.log';
    @unlink($log);
    App\Support\Log::to($log);
    Anotoki\Lib\Support\Log::to(static fn(string $line) => App\Support\Log::info($line));

    try {
        $before = $fetches();
        same([false, 200], $nobody(), 'the IAM says no sign-in needed: open');
        same(1, $fetches() - $before, 'asked once');
        check(is_file($kept), 'the answer is kept in the site\'s own storage/cache/iam');

        standInState($issuer, ['login_required' => true]);
        same([false, 200], $nobody(), 'switched on at the IAM, but within the minute the kept answer holds');
        same(1, $fetches() - $before, 'not asked again within the minute');
        $age();
        same([true, 401], $nobody(), 'a minute later: sign-in required');
        same(2, $fetches() - $before, 'asked again');
        standInUser($issuer, ['id' => 9, 'username' => 'lisa', 'roles' => ['MEMBER']]);
        $person = (new Client())->withToken(signIn($issuer)['access_token'] ?? '');
        $person->get('/api/builds/genshin')->status(200);

        standInState($issuer, ['login_required' => false]);
        $age();
        same([false, 200], $nobody(), 'switched off again');
        standInState($issuer, ['down' => true]);
        $age();
        same([false, 200], $nobody(), 'the IAM out of reach: the last value');
        same(4, $fetches() - $before, 'asked, and failed');
        same([false, 200], $nobody(), 'and not asked again for a minute');
        same(4, $fetches() - $before, 'not asked');

        standInState($issuer, ['down' => false, 'application_missing' => true]);
        $age();
        same([true, 401], $nobody(), 'an application the IAM does not know (or switched off): sign-in required');

        standInState($issuer, ['application_missing' => false, 'down' => true]);
        unlink($kept);
        same([true, 401], $nobody(), 'never read, and the IAM out of reach: sign-in required (fail closed)');

        $logged = (string) @file_get_contents($log);
        foreach (['(stale): not required, as it said last', '(never_read): required'] as $reason) {
            check(str_contains($logged, $reason), "logged: {$reason} ({$logged})");
        }
    } finally {
        App\Support\Log::to(null);
        Anotoki\Lib\Support\Log::to(null);
    }
});

// ── The accounts the IAM deleted: the scrub reads its list ─────────────────────────────────────────

test('the scrub reads the stand-in\'s deleted accounts: each one nobody\'s here, its data kept', function () use ($issuer) {
    seedGenshinCatalog();
    seedUser(21, 'gone');
    $account = seedAccount(21, 'genshin', ['uid' => '700000021', 'nickname' => 'Gone main']);
    seedUser(22, 'stays');
    standInState($issuer, ['anonymized' => [['sub' => '21', 'anonymized_at' => '2026-11-06T10:00:00Z'], ['sub' => '99', 'anonymized_at' => '2026-11-06T11:00:00Z']]]);

    standInUser($issuer, ['id' => 1, 'username' => 'owner', 'roles' => ['MEMBER', 'ADMIN']]);
    $admin = (new Client())->withToken(signIn($issuer)['access_token'] ?? '');
    $synced = $admin->post('/api/admin/people/sync')->status(200);
    same(['done', 2, 1], [$synced->json('run.state'), $synced->json('run.handled'), $synced->json('run.scrubbed')], 'every account handled, the one known here scrubbed');

    same(['deleted-21', '2026-11-06 10:00:00+00'], array_values(db()->one('SELECT name, anonymized_at FROM users WHERE id = 21') ?? []), 'its row nobody\'s');
    same('stays', db()->value('SELECT name FROM users WHERE id = 22'), 'nobody else touched');
    same([$account, 'Gone main'], array_values(db()->one('SELECT id, nickname FROM accounts WHERE user_id = 21') ?? []), 'its data kept');
    same(['21', '99'], db()->column('SELECT sub::text FROM anotoki_people_anonymized ORDER BY sub'), 'both ids listed, known here or not');
    $people = $admin->get('/api/admin/people?q=21')->status(200);
    same(['deleted-21', '2026-11-06T10:00:00Z'], [$people->json('people.0.name'), $people->json('people.0.anonymized_at')], 'the People page says so');
});

finishTests();
