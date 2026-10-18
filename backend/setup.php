<?php

/**
 * setup.php - sets the build analyzer up: writes config/database.local.php and auth.local.php (and
 * fetch.local.php with its defaults, when there is none), and builds the database. Needed once on every
 * installation, a development copy included: without those files the API answers 503 "not_set_up" and the
 * app says so.
 *
 * It asks for the database (PostgreSQL) and the anotoki IAM people sign in at - and, on a host, the setup
 * token - and nothing else. Sign-in through the IAM is the only way in, on this computer as on a host.
 *
 * ON THIS COMPUTER (a loopback client asking for localhost - `composer start` in backend/, then
 * http://localhost:8400/setup.php, or through the Angular dev server on http://localhost:4600/setup.php)
 * there is no token: a CSRF nonce (a cookie that other sites cannot send) protects the form. It can be run
 * again whenever something changes; it shows the saved values, never the saved password, and it stays.
 *
 * ON A HOST (FTP-only) upload it into the web root beside the build, then open https://<site>/setup.php.
 * Anyone could reach it, so it first proves you own the server: on first view it writes a random token into
 * config/setup-token.local.php and asks you to read it back over FTP. It shows no saved value. When it is
 * done it writes storage/installed.lock (so it never runs twice) and deletes the token and itself.
 *
 * "Test connection" beside the database and the IAM checks either without saving anything, in the words
 * every anotoki setup page answers in (App\Setup\Installer::testDatabase()).
 *
 * A database made by the first (Laravel) version of the app is recognised and brought forward with all its
 * data (migrations/001_adopt_laravel_database.sql).
 */

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
// Not "no-referrer": with it a browser sends "Origin: null" for the form's own POST, and on this computer
// the form checks that its Origin is this computer.
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Checked before anything newer is loaded, so an old PHP still gets as far as saying it is too old.
if (PHP_VERSION_ID < 80400 || ! is_file(__DIR__.'/vendor/autoload.php')) {
    echo '<!doctype html><meta charset="utf-8"><title>Set up</title><p style="font:16px system-ui;margin:40px">',
        PHP_VERSION_ID < 80400
            ? 'The build analyzer needs PHP 8.4 or newer; this is PHP '.htmlspecialchars(PHP_VERSION).'. Choose PHP 8.4 in the hosting panel and reload.'
            : 'vendor/ is missing: upload the whole build (or run composer install in backend/), then reload.',
        '</p>';
    exit;
}

require __DIR__.'/vendor/autoload.php';

use App\Setup\Installer;

$installer = new Installer(__DIR__);
$local = Installer::local($_SERVER);
$scheme = (! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$siteUrl = $scheme.'://'.(string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

function h(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function page(string $heading, string $body, string $tone = 'plain'): never
{
    $accent = ['plain' => '#6750a4', 'good' => '#1d8a55', 'bad' => '#c62f3b'][$tone] ?? '#6750a4';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">',
        '<meta name="robots" content="noindex, nofollow"><title>Set up · Build Analyzer</title><style>',
        ':root{color-scheme:light dark;--bg:#f6f5fb;--card:#fff;--ink:#1c1b20;--muted:#6b6878;--line:#e2dfeb;--field:#fff;--accent:', $accent, '}',
        '@media (prefers-color-scheme:dark){:root{--bg:#121218;--card:#1c1b24;--ink:#ebe8f4;--muted:#a09cb0;--line:#33323f;--field:#15141b}}',
        '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;display:flex;min-height:100vh;align-items:flex-start;justify-content:center;padding:24px 16px}',
        '.card{background:var(--card);border:1px solid var(--line);border-radius:14px;max-width:640px;width:100%;padding:26px 28px}',
        'h1{font-size:1.35rem;margin:0 0 6px;display:flex;align-items:center;gap:9px}h1::before{content:"";width:10px;height:10px;border-radius:50%;background:var(--accent);flex:none}',
        'h2{font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:24px 0 2px}p,li{color:var(--muted)}',
        'label{display:block;margin:12px 0 5px;font-weight:600;font-size:.9rem}.row{display:flex;gap:12px}.row>div{flex:1;min-width:0}',
        'input[type=text],input[type=password],input[type=url],input[type=number]{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--field);color:var(--ink);font:inherit}',
        'button{margin-top:22px;width:100%;padding:11px;border:0;border-radius:9px;background:var(--accent);color:#fff;font:600 1rem system-ui;cursor:pointer}',
        'button.test{width:auto;margin-top:10px;padding:7px 14px;font-size:.9rem;background:transparent;color:var(--ink);border:1px solid var(--line)}',
        '.result{margin-top:8px;font-size:.9rem;min-height:1.2em}.result.ok{color:#1d8a55}.result.bad{color:#c62f3b}',
        'code{background:rgba(127,127,127,.15);padding:1px 6px;border-radius:5px;font-size:.85em;overflow-wrap:anywhere}',
        '.err{background:rgba(198,47,59,.12);border:1px solid rgba(198,47,59,.4);color:#c62f3b;border-radius:9px;padding:10px 12px;margin:12px 0}',
        '.note{border:1px solid var(--line);border-radius:9px;padding:11px 13px;margin:12px 0}.ok{color:#1d8a55}.muted{font-size:.85rem;margin:4px 0 0}',
        '@media (max-width:520px){.row{flex-direction:column;gap:0}.card{padding:20px 18px}}',
        '</style></head><body><main class="card"><h1>', h($heading), '</h1>', $body, '</main></body></html>';
    exit;
}

function json(array $data): never
{
    header('Content-Type: application/json; charset=utf-8', true);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── A host that is set up: the lock stops a second run ─────────────────────────────────────────────

if (! $local && is_file($installer->lockFile())) {
    $gone = $installer->removeSetupFiles(__FILE__);
    page('Already set up', '<p>This site is set up; <code>storage/installed.lock</code> stops setup running again.</p>'
        .($gone ? '<p class="ok">The setup files have been removed.</p>'
            : '<p class="err">Delete <code>setup.php</code> and <code>config/setup-token.local.php</code> by hand.</p>'), 'good');
}

// ── Requirements ───────────────────────────────────────────────────────────────────────────────────

$missing = array_filter($installer->requirements(), fn (array $check) => ! $check['ok']);
if ($missing !== []) {
    $items = implode('', array_map(fn (array $check) => '<li><strong>'.h($check['label']).'</strong>: '.h($check['detail']).'</li>', $missing));
    page('Not ready yet', '<p>The server is missing something the app needs:</p><ul>'.$items.'</ul><p class="muted">Fix these and reload.</p>', 'bad');
}

// ── Who may use this form ──────────────────────────────────────────────────────────────────────────
// This computer: a nonce in a SameSite=Strict cookie, repeated in the form - no other site can send both.
// A host: the token from config/setup-token.local.php, which only somebody with FTP access can read.

$nonce = null;
$cookie = null;
$token = null;
if ($local) {
    $cookie = is_string($_COOKIE['build_analyzer_setup'] ?? null) && preg_match('/^[a-f0-9]{48}$/', $_COOKIE['build_analyzer_setup'])
        ? $_COOKIE['build_analyzer_setup'] : null;
    $nonce = $cookie ?? bin2hex(random_bytes(24));
    if ($cookie === null) {
        setcookie('build_analyzer_setup', $nonce, ['path' => '/setup.php', 'httponly' => true, 'samesite' => 'Strict']);
    }
} else {
    $token = $installer->token() ?? $installer->makeToken();
    if ($token === null) {
        page('Cannot write to config/', '<p>Setup could not create <code>config/setup-token.local.php</code>: the <code>config/</code> folder is not writable for the web server. Give it write permission and reload.</p>', 'bad');
    }
}

$allowed = function () use ($local, $cookie, $token): bool {
    if ($local) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $sameMachine = $origin === null || Installer::isLocalHost((string) parse_url((string) $origin, PHP_URL_HOST));

        return $cookie !== null && $sameMachine && hash_equals($cookie, (string) ($_POST['nonce'] ?? ''));
    }

    return hash_equals((string) $token, trim((string) ($_POST['token'] ?? '')));
};

$values = $installer->defaults($local, $siteUrl);
$password = (string) ($_POST['db_password'] ?? '');
if ($post) {
    foreach (array_keys($values) as $key) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $values[$key] = trim($_POST[$key]);
        }
    }
    // On this computer an empty password field keeps the saved password.
    if ($local && $password === '' && $installer->savedPassword() !== null) {
        $password = (string) $installer->savedPassword();
    }
}

// ── "Test connection" ──────────────────────────────────────────────────────────────────────────────

$action = (string) ($_POST['action'] ?? '');
if ($post && in_array($action, ['test-db', 'test-iam'], true)) {
    if (! $allowed()) {
        json(['ok' => false, 'message' => $local ? 'This form has expired. Reload the page.' : 'Enter the setup token first.']);
    }
    if ($action === 'test-iam') {
        json($installer->testIam($values['issuer']));
    }

    [$settings, $errors] = $installer->validate($values, $password);
    if (array_filter($errors, fn ($error) => str_contains($error, 'database')) !== []) {
        json(['ok' => false, 'message' => 'Fill in the database host, a numeric port, the database name and the username.']);
    }
    json($installer->testDatabase($settings['database']));
}

// ── Set up ─────────────────────────────────────────────────────────────────────────────────────────

$errors = [];
if ($post) {
    if (! $allowed()) {
        $errors[] = $local ? 'This form has expired. Reload the page and try again.' : 'That is not the token from config/setup-token.local.php.';
    } else {
        [$settings, $errors] = $installer->validate($values, $password);
    }

    if ($errors === []) {
        try {
            if (! $local) {
                $installer->checkExposure($siteUrl);
            }
            $log = $installer->buildDatabase($settings['database']);
            $written = $installer->writeConfig($settings, Installer::userAgent($local, $siteUrl));

            $files = implode(', ', array_map(fn ($file) => '<code>'.h($file).'</code>', $written));
            $steps = '<ul>'.implode('', array_map(fn ($line) => '<li>'.h($line).'</li>', $log)).'</ul>';

            if ($local) {
                page('Saved', $steps.'<p>Wrote '.$files.'.</p>'
                    .'<p>Sign-in: <strong>through the anotoki IAM at '.h($settings['auth']['issuer']).'</strong>.</p>'
                    .'<h2>Next</h2><ul><li>The app: <code>npm start</code> in <code>frontend/</code>, then <a href="http://localhost:4600/">http://localhost:4600</a>.</li>'
                    .'<li>On the <strong>Sources</strong> page (editors and admins) press <strong>Refresh catalog</strong>, then <strong>Sync all</strong>, and keep the tab open while it runs.</li>'
                    .'<li>To change any of this, open <a href="setup.php">setup.php</a> again.</li></ul>', 'good');
            }

            if (! is_dir(dirname($installer->lockFile()))) {
                @mkdir(dirname($installer->lockFile()), 0775, true);
            }
            // Checked, not assumed: the lock is what keeps a leftover setup.php from running a second time.
            $locked = @file_put_contents($installer->lockFile(), json_encode(['installed_at' => gmdate('c'), 'site' => $siteUrl], JSON_PRETTY_PRINT)) !== false;
            $gone = $installer->removeSetupFiles(__FILE__);

            page('Set up', $steps.'<p>Wrote '.$files.' ('.($installer->phpOwns(__DIR__.'/config') ? 'readable by their owner only' : 'readable over FTP, because PHP runs as another user on this host').').</p>'
                .($locked ? '' : '<p class="err"><code>storage/installed.lock</code> could not be written. Make <code>storage/</code> writable.</p>')
                .($gone ? '<p class="ok">Removed <code>setup.php</code> and <code>config/setup-token.local.php</code>.</p>'
                    : '<p class="err">Delete <code>setup.php</code> and <code>config/setup-token.local.php</code> by hand now.</p>')
                .'<h2>Next</h2><ol><li>Open <a href="/">'.h($siteUrl).'</a> and sign in with the IAM\'s administrator account (the IAM gives it the ADMIN role here).</li>'
                .'<li>On the <strong>Sources</strong> page press <strong>Refresh catalog</strong>, then <strong>Sync all</strong>, and keep the tab open while it runs.</li></ol>', 'good');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// ── The form ───────────────────────────────────────────────────────────────────────────────────────

$saved = $installer->saved() !== [];
// On this computer, a file of setup's own that is there but cannot be read cleanly (a byte-order mark, no <?php line)
// is said by its fault, never its text: none of its values is shown and its password is not kept. On a real host,
// none (Installer::passedOver()).
$broken = $installer->passedOver($local);
$field = fn (string $name, string $label, string $type = 'text', string $extra = '') => '<label for="'.$name.'">'.h($label).'</label>'
    .'<input id="'.$name.'" name="'.$name.'" type="'.$type.'"'.($type === 'password' ? '' : ' value="'.h($values[$name] ?? '').'"').' '.$extra.'>';

$intro = $local
    ? '<p>This writes the configuration of this copy and builds its database.'.($saved ? ' It is set up already: these are the saved values (the password is not shown), and saving replaces them.' : '').'</p>'
        .implode('', array_map(fn (string $file) => '<div class="err">'.h($file).': it is passed over, nothing in it read. Fill in every field, the password too - saving writes the file anew.</div>', $broken))
    : '<p>This writes the configuration and builds the database. Do it once, on a PostgreSQL database made in the hosting panel.</p>'
        .'<div class="note">A token was written to <code>config/setup-token.local.php</code>. Open that file over FTP, copy the token and paste it below: that is how setup knows it is you.</div>';

page('Set up the build analyzer', $intro
    .implode('', array_map(fn (string $error) => '<div class="err">'.h($error).'</div>', $errors))
    .'<form method="post" autocomplete="off" id="setup">'
    .($local ? '<input type="hidden" name="nonce" value="'.h($nonce).'">' : $field('token', 'Setup token', 'password', 'required'))
    .'<h2>Database (PostgreSQL)</h2>'
    .'<div class="row"><div>'.$field('db_host', 'Host', 'text', 'required').'</div><div>'.$field('db_port', 'Port', 'text', 'required inputmode="numeric"').'</div></div>'
    .$field('db_database', 'Database', 'text', 'required')
    .'<div class="row"><div>'.$field('db_username', 'Username', 'text', 'required').'</div><div>'
    .$field('db_password', 'Password', 'password', $local && $installer->savedPassword() !== null ? 'placeholder="saved - leave empty to keep it"' : '').'</div></div>'
    .'<button type="button" class="test" data-test="test-db">Test connection</button><div class="result" id="test-db"></div>'
    .'<h2>Sign-in</h2>'
    .'<p>Everyone signs in at the anotoki IAM, which must know this site as the client below, with <code>'.h($local ? 'http://localhost:4600' : $siteUrl).'/auth/callback</code> as its redirect address.</p>'
    .'<div class="row"><div>'.$field('issuer', 'IAM address', 'url').'</div><div>'.$field('client_id', 'Client id').'</div></div>'
    .'<button type="button" class="test" data-test="test-iam">Test connection</button><div class="result" id="test-iam"></div>'
    .'<button type="submit">'.($local ? 'Save' : 'Set up').'</button></form>'
    .'<script>'
    .'document.querySelectorAll("[data-test]").forEach(function (button) {'
    .'  button.addEventListener("click", function () {'
    .'    var action = button.getAttribute("data-test"), out = document.getElementById(action), data = new FormData(document.getElementById("setup"));'
    .'    data.set("action", action); out.className = "result"; out.textContent = "Testing...";'
    .'    fetch("setup.php", { method: "POST", body: data, credentials: "same-origin" })'
    .'      .then(function (r) { return r.json(); })'
    .'      .then(function (r) { out.className = "result " + (r.ok ? "ok" : "bad"); out.textContent = r.message; })'
    .'      .catch(function () { out.className = "result bad"; out.textContent = "The test could not be run. Reload the page and try again."; });'
    .'  });'
    .'});'
    .'</script>');
