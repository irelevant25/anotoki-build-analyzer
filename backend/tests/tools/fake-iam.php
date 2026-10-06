<?php
/**
 * A stand-in for the anotoki IAM, for the tests and for browser checks only:
 * just enough OpenID Connect for the browser SDK and the PHP verifier -
 * discovery, the key set, authorize (signs the configured person in at once),
 * token (authorization code with PKCE, refresh with rotation), logout, revoke -
 * the public answer about an application (whether its site requires sign-in),
 * and the account's preferences a site saves (POST /api/oidc/preferences, the
 * SDK's savePreferences(); the IAM's migration 015).
 * Its tokens are signed with the tests' own key. Never point a real site at it.
 *
 *   FAKE_IAM_DIR=<folder> FAKE_IAM_ISSUER=http://127.0.0.1:8597 php -S 127.0.0.1:8597 tests/tools/fake-iam.php
 *
 * (from backend/; tests/stand_in_iam.test.php runs it, and so does the browser check. The anotoki survey's
 * tests/tools/fake-iam.php is the same file but for the line that requires the SDK and its default
 * login_required - keep the two alike.
 * Throwaway ports: 8590-8599.)
 *
 * <folder>/key.json    { kid, private_pem, jwks } - anotoki_iam_test_keypair()
 * <folder>/state.json  who signs in and how the IAM behaves; POST /_test/state
 *                      merges JSON into it:
 *   user      { id, username, roles, locale?, preferences? }   who authorize signs in;
 *             roles are this application's - every account holds MEMBER, so an ordinary
 *             person is ['MEMBER'] (owner: MEMBER, ADMIN); locale is the language their
 *             account is set to - the token's `locale` claim (en); preferences
 *             { theme, date_format, time_format } is the account's theme, date order and
 *             clock - the tokens' `preferences` claim, as an IAM with its migration 015
 *             sends it. Without it, none: an IAM before 015 (the default), where saving
 *             them answers 503 temporarily_unavailable, as that IAM does.
 *   ttl       seconds an access token lives (600)
 *   down      true: every endpoint but the key set answers 503 (the IAM unreachable)
 *   refuse    true: refreshing answers invalid_grant (the IAM session ended)
 *   deny      true: authorize answers access_denied (no role in the application),
 *             with deny_description as its error_description
 *   login_required       what GET /api/public/applications/{client_id} says: whether the
 *                        site requires sign-in (false here; the survey's: true)
 *   application_missing  true: that address answers 404 - an unknown or switched-off application
 *   application_name     the name it gives there (Test site)
 */

declare(strict_types=1);

require __DIR__ . '/../../src/Support/Iam/anotoki_iam.php';

$dir = (string) getenv('FAKE_IAM_DIR');
$issuer = rtrim((string) getenv('FAKE_IAM_ISSUER'), '/');
if ($dir === '' || $issuer === '' || !is_file("$dir/key.json")) {
    http_response_code(500);
    echo 'fake IAM: set FAKE_IAM_DIR (with key.json) and FAKE_IAM_ISSUER';
    return true;
}

function fake_state(string $dir): array
{
    $state = is_file("$dir/state.json") ? json_decode((string) file_get_contents("$dir/state.json"), true) : null;
    return (is_array($state) ? $state : []) + [
        'user' => ['id' => 1, 'username' => 'owner', 'roles' => ['MEMBER', 'ADMIN']],
        'ttl' => 600, 'down' => false, 'refuse' => false, 'deny' => false, 'deny_description' => 'No access to this application',
        'login_required' => false, 'application_missing' => false,
        'codes' => [], 'refresh' => [], 'log' => [],
    ];
}

function fake_save(string $dir, array $state): void
{
    file_put_contents("$dir/state.json.tmp", json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename("$dir/state.json.tmp", "$dir/state.json");
}

function fake_json(int $status, $body, string $origin = ''): bool
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if ($origin !== '') {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    return true;
}

function fake_redirect(string $url): bool
{
    http_response_code(302);
    header("Location: $url");
    return true;
}

function fake_b64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** What POST /api/oidc/preferences takes for each name, as the IAM does (the languages: this stand-in's two). */
const FAKE_PREFERENCES = [
    'theme' => ['auto', 'light', 'dark'],
    'date_format' => [null, 'dmy_dot', 'dmy_slash', 'mdy_slash', 'ymd_dash'],
    'time_format' => [null, '24', '12'],
    'language' => ['en', 'sk'],
];

/** The `sub` of the bearer token sent - read, not verified: the stand-in only ever deals with its own tokens. */
function fake_bearer_sub(): ?string
{
    if (!preg_match('/^Bearer [\w-]+\.([\w-]+)\.[\w-]+$/', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
        return null;
    }
    $claims = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);
    return is_array($claims) && is_string($claims['sub'] ?? null) ? $claims['sub'] : null;
}

/** $user with the saved $changes: the language its locale, the rest its preferences. */
function fake_with_preferences(array $user, array $changes): array
{
    if (array_key_exists('language', $changes)) {
        $user['locale'] = $changes['language'];
    }
    unset($changes['language']);
    $user['preferences'] = $changes + $user['preferences'];
    return $user;
}

/** An access token, an ID token and a new refresh token for $user. */
function fake_tokens(array &$state, array $user, array $key, string $issuer, string $clientId, ?string $nonce, string $sid): array
{
    $now = time();
    $common = [
        'iss' => $issuer, 'sub' => (string) $user['id'], 'aud' => $clientId, 'iat' => $now, 'sid' => $sid,
        'preferred_username' => $user['username'], 'email' => $user['username'] . '@example.test',
        'email_verified' => true, 'locale' => is_string($user['locale'] ?? null) ? $user['locale'] : 'en', 'roles' => $user['roles'],
    ] + (is_array($user['preferences'] ?? null) ? ['preferences' => $user['preferences']] : []);
    $access = anotoki_iam_test_sign($common + ['client_id' => $clientId, 'exp' => $now + (int) $state['ttl'], 'jti' => bin2hex(random_bytes(8)), 'scope' => 'openid profile email'], $key['private_pem'], $key['kid']);
    $id = anotoki_iam_test_sign($common + ['azp' => $clientId, 'exp' => $now + 3600, 'auth_time' => $now] + ($nonce !== null ? ['nonce' => $nonce] : []), $key['private_pem'], $key['kid'], 'JWT');
    $refresh = 'iam_rt_' . bin2hex(random_bytes(16));
    $state['refresh'][$refresh] = ['user' => $user, 'client' => $clientId, 'sid' => $sid];
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => (int) $state['ttl'], 'refresh_token' => $refresh, 'id_token' => $id, 'scope' => 'openid profile email'];
}

$key = json_decode((string) file_get_contents("$dir/key.json"), true);
$state = fake_state($dir);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$state['log'][] = "$method $path";
$state['log'] = array_slice($state['log'], -200);

if ($path === '/_test/state') {
    if ($method === 'POST') {
        $changes = json_decode((string) file_get_contents('php://input'), true);
        $state = array_replace($state, is_array($changes) ? $changes : []);
    }
    fake_save($dir, $state);
    return fake_json(200, $state);
}

if ($state['down'] && $path !== '/.well-known/jwks.json') {
    fake_save($dir, $state);
    return fake_json(503, ['error' => 'temporarily_unavailable'], $origin);
}

// The IAM's public answer about an application: whether its site requires sign-in
// (anotoki_iam_login_required() in the SDK reads it). Answered for any client id;
// `down` above makes it 503 like everything else.
if ($method === 'GET' && preg_match('#^/api/public/applications/([a-z0-9][a-z0-9-]{1,63})$#', $path, $m)) {
    fake_save($dir, $state);
    if ($state['application_missing']) {
        return fake_json(404, ['error' => 'not_found']);
    }
    http_response_code(200);
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=60');
    echo json_encode([
        'client_id' => $m[1],
        'name' => (string) ($state['application_name'] ?? 'Test site'),
        'login_required' => (bool) $state['login_required'],
    ], JSON_UNESCAPED_SLASHES);
    return true;
}

switch ($path) {
    case '/.well-known/openid-configuration':
        fake_save($dir, $state);
        return fake_json(200, [
            'issuer' => $issuer,
            'authorization_endpoint' => "$issuer/api/oidc/authorize",
            'token_endpoint' => "$issuer/api/oidc/token",
            'userinfo_endpoint' => "$issuer/api/oidc/userinfo",
            'jwks_uri' => "$issuer/.well-known/jwks.json",
            'end_session_endpoint' => "$issuer/api/oidc/logout",
            'revocation_endpoint' => "$issuer/api/oidc/revoke",
        ], $origin ?: '*');

    case '/.well-known/jwks.json':
        fake_save($dir, $state);
        return fake_json(200, $key['jwks'], '*');

    case '/api/oidc/authorize':
        $redirect = (string) ($_GET['redirect_uri'] ?? '');
        $query = ['state' => (string) ($_GET['state'] ?? ''), 'iss' => $issuer];
        if ($state['deny']) {
            fake_save($dir, $state);
            return fake_redirect($redirect . '?' . http_build_query(['error' => 'access_denied', 'error_description' => (string) $state['deny_description']] + $query));
        }
        $code = bin2hex(random_bytes(16));
        $state['codes'][$code] = [
            'client' => (string) ($_GET['client_id'] ?? ''), 'redirect' => $redirect, 'nonce' => (string) ($_GET['nonce'] ?? ''),
            'challenge' => (string) ($_GET['code_challenge'] ?? ''), 'at' => time(),
        ];
        fake_save($dir, $state);
        return fake_redirect($redirect . '?' . http_build_query(['code' => $code] + $query));

    case '/api/oidc/token':
        if ($method === 'OPTIONS') {
            http_response_code(204);
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Headers: Content-Type');
            return true;
        }
        $grant = (string) ($_POST['grant_type'] ?? '');
        $clientId = (string) ($_POST['client_id'] ?? '');
        if ($grant === 'authorization_code') {
            $code = $state['codes'][(string) ($_POST['code'] ?? '')] ?? null;
            unset($state['codes'][(string) ($_POST['code'] ?? '')]);
            $verifier = (string) ($_POST['code_verifier'] ?? '');
            if ($code === null || $code['client'] !== $clientId || $code['redirect'] !== (string) ($_POST['redirect_uri'] ?? '')
                || $code['at'] < time() - 60 || !hash_equals($code['challenge'], fake_b64(hash('sha256', $verifier, true)))) {
                fake_save($dir, $state);
                return fake_json(400, ['error' => 'invalid_grant', 'error_description' => 'bad code'], $origin);
            }
            $tokens = fake_tokens($state, $state['user'], $key, $issuer, $clientId, $code['nonce'], bin2hex(random_bytes(8)));
            fake_save($dir, $state);
            return fake_json(200, $tokens, $origin);
        }
        if ($grant === 'refresh_token') {
            $token = (string) ($_POST['refresh_token'] ?? '');
            $entry = $state['refresh'][$token] ?? null;
            unset($state['refresh'][$token]);
            if ($state['refuse'] || $entry === null || $entry['client'] !== $clientId) {
                fake_save($dir, $state);
                return fake_json(400, ['error' => 'invalid_grant', 'error_description' => 'the session has ended'], $origin);
            }
            $tokens = fake_tokens($state, $entry['user'], $key, $issuer, $clientId, null, $entry['sid']);
            fake_save($dir, $state);
            return fake_json(200, $tokens, $origin);
        }
        fake_save($dir, $state);
        return fake_json(400, ['error' => 'unsupported_grant_type'], $origin);

    case '/api/oidc/revoke':
        unset($state['refresh'][(string) ($_POST['token'] ?? '')]);
        fake_save($dir, $state);
        return fake_json(200, new stdClass(), $origin);

    // A site saving what its signed-in reader chose (the SDK's savePreferences()): the bearer token's own
    // account - the person authorize signs in, and every session of theirs here, so a refresh carries it.
    case '/api/oidc/preferences':
        fake_save($dir, $state);
        if ($method === 'OPTIONS') {
            http_response_code(204);
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Methods: POST');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Vary: Origin');
            return true;
        }
        $sub = fake_bearer_sub();
        $holders = array_filter($state['refresh'], fn (array $entry): bool => (string) $entry['user']['id'] === $sub);
        $account = (string) $state['user']['id'] === $sub ? $state['user'] : (reset($holders) ?: ['user' => null])['user'];
        if ($method !== 'POST' || $account === null) {
            return fake_json(401, ['error' => 'invalid_token'], $origin);
        }
        if (!is_array($account['preferences'] ?? null)) {
            return fake_json(503, ['error' => 'temporarily_unavailable', 'error_description' => 'The preferences are not available yet'], $origin);
        }
        $changes = json_decode((string) file_get_contents('php://input'), true);
        $valid = is_array($changes) && $changes !== [] && !array_is_list($changes) && array_diff_key($changes, FAKE_PREFERENCES) === [];
        foreach ($valid ? $changes : [] as $name => $value) {
            $valid = $valid && in_array($value, FAKE_PREFERENCES[$name], true);
        }
        if (!$valid) {
            return fake_json(400, ['error' => 'invalid_request', 'error_description' => 'Not a change the account takes'], $origin);
        }
        $account = fake_with_preferences($account, $changes);
        if ((string) $state['user']['id'] === $sub) {
            $state['user'] = $account;
        }
        foreach (array_keys($holders) as $token) {
            $state['refresh'][$token]['user'] = fake_with_preferences($state['refresh'][$token]['user'], $changes);
        }
        fake_save($dir, $state);
        return fake_json(200, ['locale' => $account['locale'] ?? 'en', 'preferences' => $account['preferences']], $origin);

    case '/api/oidc/logout':
        $state['refresh'] = [];
        fake_save($dir, $state);
        $to = (string) ($_GET['post_logout_redirect_uri'] ?? '');
        return $to !== '' ? fake_redirect($to) : fake_json(200, ['signed_out' => true]);

    case '/account':
        fake_save($dir, $state);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><title>Fake IAM account</title><h1>Fake IAM account page</h1>';
        return true;
}

fake_save($dir, $state);
return fake_json(404, ['error' => 'not_found']);
