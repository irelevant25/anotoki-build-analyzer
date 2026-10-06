<?php

/**
 * Signing in through the stand-in IAM (tests/tools/fake-iam.php), the way a browser does: discovery, the
 * authorize redirect with PKCE, the code for tokens, a refresh - and the access token it hands out is let
 * into this API by the same verifier that checks the real IAM's, its keys downloaded from the stand-in -
 * and its public answer about the application, which the site reads through the IAM's SDK to know whether
 * sign-in is required (App\Auth\IamSignInPolicy, kept for a minute, failing closed). This is what the browser
 * check signs in with; the other test files sign their own tokens and give a fixed answer.
 *
 * The stand-in runs as `php -S 127.0.0.1:<port>` on a throwaway port (8590-8599) and is stopped at the end.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Support\Iam\IamTokenVerifier;
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

/** @return array{status: int, headers: array<string, string>, body: string} */
function http(string $method, string $url, ?array $form = null): array
{
    $headers = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        },
    ] + ($form === null ? [] : [CURLOPT_POSTFIELDS => http_build_query($form)]));
    $body = (string) curl_exec($curl);

    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
}

$dir = T::$storage.DIRECTORY_SEPARATOR.'stand-in-iam';
@mkdir($dir, 0777, true);
file_put_contents($dir.'/key.json', json_encode(T::$key));
$port = throwawayPort();
$issuer = "http://127.0.0.1:{$port}";
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/tools/fake-iam.php'],
    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $dir.'/server.log', 'a']],
    $pipes, __DIR__.'/..', ['FAKE_IAM_DIR' => $dir, 'FAKE_IAM_ISSUER' => $issuer] + getenv());
if (! is_resource($process)) {
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

/** The stand-in's person for the next sign-in. */
function standInUser(string $issuer, array $user, array $more = []): void
{
    standInState($issuer, ['user' => $user] + $more);
}

/** $changes merged into the stand-in's state; the state that results (its request log among it). */
function standInState(string $issuer, array $changes = []): array
{
    $curl = curl_init("{$issuer}/_test/state");
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object) $changes), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);

    return (array) json_decode((string) curl_exec($curl), true);
}

/**
 * Signs in as a browser does - authorize with PKCE, then the code for tokens.
 *
 * @return array<string, mixed> the token answer, or ['error' => ...] from the redirect
 */
function signIn(string $issuer): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $redirect = 'http://localhost:4600/auth/callback';
    $authorize = http('GET', "{$issuer}/api/oidc/authorize?".http_build_query([
        'response_type' => 'code', 'client_id' => TEST_CLIENT, 'redirect_uri' => $redirect, 'scope' => 'openid profile email',
        'state' => 'st', 'nonce' => 'no', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    same(302, $authorize['status'], 'authorize redirects back');
    parse_str((string) parse_url($authorize['headers']['location'] ?? '', PHP_URL_QUERY), $query);
    if (isset($query['error'])) {
        return ['error' => $query['error']];
    }
    same('st', $query['state'] ?? null, 'the state comes back');

    $token = http('POST', "{$issuer}/api/oidc/token", [
        'grant_type' => 'authorization_code', 'client_id' => TEST_CLIENT, 'code' => $query['code'] ?? '', 'redirect_uri' => $redirect, 'code_verifier' => $verifier,
    ]);
    same(200, $token['status'], 'the code for tokens: '.$token['body']);

    return (array) json_decode($token['body'], true);
}

// The API checks tokens against the stand-in, its keys downloaded from it as from the real IAM.
beforeEach(function () use ($issuer): void {
    T::$app->config->set('auth.issuer', $issuer);
    T::$app->config->set('auth.jwks_file', null);
    T::$app->forget(IamTokenVerifier::class);
});

test('the stand-in answers as an IAM: discovery, its keys, and the authorize redirect', function () use ($issuer) {
    $discovery = json_decode(http('GET', "{$issuer}/.well-known/openid-configuration")['body'], true);
    same([$issuer, "{$issuer}/.well-known/jwks.json", "{$issuer}/api/oidc/token"], [$discovery['issuer'] ?? null, $discovery['jwks_uri'] ?? null, $discovery['token_endpoint'] ?? null], 'discovery');
    same(T::$key['jwks'], json_decode(http('GET', "{$issuer}/.well-known/jwks.json")['body'], true), 'the key set');
});

test('an ADMIN signed in at the stand-in is an admin here; a MEMBER is an ordinary person', function () use ($issuer) {
    standInUser($issuer, ['id' => 1, 'username' => 'owner', 'roles' => ['MEMBER', 'ADMIN']]);
    $tokens = signIn($issuer);
    check(is_string($tokens['access_token'] ?? null) && is_string($tokens['refresh_token'] ?? null), 'tokens');
    $owner = (new Client)->withToken($tokens['access_token']);
    $owner->get('/api/admin/migrations')->status(200);
    $owner->get('/api/admin/people')->status(200)->path('people.0.name', 'owner');

    standInUser($issuer, ['id' => 7, 'username' => 'mona', 'roles' => ['MEMBER']]);
    $mona = (new Client)->withToken(signIn($issuer)['access_token'] ?? '');
    $mona->get('/api/accounts')->status(200);
    $mona->get('/api/sync')->status(403)->path('code', 'editor_only');

    standInUser($issuer, ['id' => 4, 'username' => 'eda', 'roles' => ['MEMBER', 'EDITOR']]);
    $editor = (new Client)->withToken(signIn($issuer)['access_token'] ?? '');
    $editor->get('/api/sync')->status(200);
    $editor->get('/api/admin/migrations')->status(403)->path('code', 'admin_only');

    same(['eda', 'mona', 'owner'], db()->column('SELECT name FROM users ORDER BY name'), 'each named from the token');
});

test('a refreshed token is let in; a token of another IAM is not', function () use ($issuer) {
    standInUser($issuer, ['id' => 5, 'username' => 'amber', 'roles' => ['MEMBER']]);
    $tokens = signIn($issuer);
    $refreshed = json_decode(http('POST', "{$issuer}/api/oidc/token", ['grant_type' => 'refresh_token', 'client_id' => TEST_CLIENT, 'refresh_token' => $tokens['refresh_token']])['body'], true);
    check(($refreshed['access_token'] ?? $tokens['access_token']) !== $tokens['access_token'], 'a new access token');
    (new Client)->withToken($refreshed['access_token'])->get('/api/accounts')->status(200);

    // Signed by the same key, but naming the IAM the tests otherwise use: not this IAM's.
    (new Client)->withToken(iamToken(5))->get('/api/accounts')->status(401)->path('code', 'sign_in_required');
});

/** A token's claims, read without verifying - the stand-in's own. @return array<string, mixed> */
function claimsOf(string $jwt): array
{
    return (array) json_decode((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);
}

/**
 * POST /api/oidc/preferences as a site's page sends it (the SDK's savePreferences()): JSON, the bearer token,
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
    (new Client)->withToken($tokens['access_token'])->get('/api/accounts')->status(200);

    $saved = savePreferences($issuer, '{"theme":"light","language":"sk"}', $tokens['access_token']);
    same(200, $saved['status'], 'saved: '.$saved['body']);
    same(['locale' => 'sk', 'preferences' => ['theme' => 'light', 'date_format' => 'ymd_dash', 'time_format' => null]], json_decode($saved['body'], true), 'the account as it now stands');
    same('http://localhost:4600', $saved['headers']['access-control-allow-origin'] ?? null, "answered to the site's origin");
    $refreshed = json_decode(http('POST', "{$issuer}/api/oidc/token", ['grant_type' => 'refresh_token', 'client_id' => TEST_CLIENT, 'refresh_token' => $tokens['refresh_token']])['body'], true);
    same(['sk', 'light'], [claimsOf($refreshed['access_token'])['locale'] ?? null, claimsOf($refreshed['access_token'])['preferences']['theme'] ?? null], 'a refreshed token carries the change');

    foreach (['{"theme":"pink"}' => 'a theme not on offer', '{"language":"de"}' => 'a language the IAM does not offer', '{"colour":"red"}' => 'anything else', '{}' => 'nothing at all', '[]' => 'not an object'] as $json => $what) {
        $refused = savePreferences($issuer, $json, $refreshed['access_token']);
        same([400, 'invalid_request'], [$refused['status'], json_decode($refused['body'], true)['error'] ?? null], "refused: {$what}");
    }
    same(401, savePreferences($issuer, '{"theme":"dark"}', null)['status'], 'no token, no account');

    standInUser($issuer, ['id' => 13, 'username' => 'olga', 'roles' => ['MEMBER']]);
    $older = signIn($issuer);
    check(! array_key_exists('preferences', claimsOf($older['access_token'])), 'an IAM before 015: no claim');
    same(503, savePreferences($issuer, '{"theme":"dark"}', $older['access_token'])['status'], 'and nowhere to save them');
});

test('a person the stand-in refuses comes back with access_denied, and nothing here is recorded', function () use ($issuer) {
    standInUser($issuer, ['id' => 6, 'username' => 'nobody', 'roles' => ['MEMBER']], ['deny' => true]);
    same(['error' => 'access_denied'], signIn($issuer), 'refused at the IAM');
    standInUser($issuer, ['id' => 6, 'username' => 'nobody', 'roles' => ['MEMBER']], ['deny' => false]);
    same(0, (int) db()->value('SELECT count(*) FROM users'), 'nobody recorded');
});

// ── Whether the site requires sign-in: the IAM's public answer ─────────────────────────────────────

test('the stand-in answers the public application route: the switch, missing, down, its cache header', function () use ($issuer) {
    $url = "{$issuer}/api/public/applications/".TEST_CLIENT;
    $answer = http('GET', $url);
    same(200, $answer['status'], 'answered');
    same(['client_id' => TEST_CLIENT, 'name' => 'Test site', 'login_required' => false], json_decode($answer['body'], true), 'the default here: visitors welcome');
    same('public, max-age=60', $answer['headers']['cache-control'] ?? null, 'cacheable for a minute');
    try {
        standInState($issuer, ['login_required' => true]);
        same(true, json_decode(http('GET', $url)['body'], true)['login_required'], 'switched on');
        standInState($issuer, ['application_missing' => true]);
        same(404, http('GET', $url)['status'], 'an unknown application');
        standInState($issuer, ['application_missing' => false, 'down' => true]);
        same(503, http('GET', $url)['status'], 'the IAM out of reach');
    } finally {
        standInState($issuer, ['login_required' => false, 'application_missing' => false, 'down' => false]);
    }
});

test('the site reads the switch from the IAM: kept a minute (one fetch), 404 = required, out of reach = the last value, never read = required', function () use ($issuer) {
    seedGenshinCatalog();
    $kept = T::$storage.'/cache/iam/anotoki-iam-application-'.sha1($issuer.' '.TEST_CLIENT).'.json';
    @unlink($kept);
    $fetches = fn (): int => count(array_filter(standInState($issuer)['log'] ?? [], fn ($line) => $line === 'GET /api/public/applications/'.TEST_CLIENT));
    // As if the minute had passed: what the site kept, read and tried that long ago.
    $age = function () use ($kept): void {
        $record = (array) json_decode((string) file_get_contents($kept), true);
        foreach (['fetched_at', 'failed_at', 'tried_at'] as $at) {
            $record[$at] = ($record[$at] ?? 0) > 0 ? $record[$at] - 120 : 0;
        }
        file_put_contents($kept, json_encode($record));
    };
    // A request from nobody: what meta says, and whether the builds are theirs to see.
    $nobody = function (): array {
        realSignInPolicy();

        return [(new Client)->get('/api/meta')->status(200)->json('auth.login_required'), (new Client)->get('/api/builds/genshin')->status];
    };
    $log = T::$storage.DIRECTORY_SEPARATOR.'sign-in-answer.log';
    @unlink($log);
    App\Support\Log::to($log);

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
        $person = (new Client)->withToken(signIn($issuer)['access_token'] ?? '');
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
        foreach (['stale', 'unknown_application', 'never_read'] as $reason) {
            check(str_contains($logged, "IAM sign-in answer: {$reason}"), "logged: {$reason} ({$logged})");
        }
    } finally {
        App\Support\Log::to(null);
        standInState($issuer, ['login_required' => false, 'application_missing' => false, 'down' => false]);
    }
});

finishTests();
