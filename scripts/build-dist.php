<?php

declare(strict_types=1);

/**
 * build-dist.php - assembles dist/, exactly what to upload to the (FTP-only) host.
 *
 *     php scripts/build-dist.php [--skip-frontend]
 *     .\dev.ps1 dist
 *
 * dist/build-analyzer/   the site's web root, complete: the Angular build (index.html, the hashed bundles,
 *                        the fonts, changelog.json) with the backend beside it - api/ (the front controller
 *                        at /api), src/, config/app.php, migrations/, vendor/ (the host cannot run
 *                        composer) and an empty storage/. The .htaccess files route /api to api/index.php,
 *                        send every other path that is not a file to the app, and keep src/, config/,
 *                        migrations/, vendor/ and storage/ from ever being served.
 * dist/setup.php         the installer: for the first install only, upload it into the web root and open
 *                        https://<site>/setup.php. It writes config/*.local.php on the server and deletes
 *                        itself when it is done.
 *
 * Never in it: config/*.local.php (setup.php writes the server's own), the command-line scripts and the dev
 * router (there is no shell on the host), tests, logs, caches, node_modules. The upload map is in
 * DEVELOPING.md ("Deploying").
 *
 * Runs on the developer's machine, where a shell exists; nothing here runs on the host.
 */
const MIN_PHP = 80400;

$root = dirname(__DIR__);
$backend = $root.'/backend';
$frontend = $root.'/frontend';
$dist = $root.'/dist';
$site = $dist.'/build-analyzer';

$options = getopt('', ['skip-frontend']);

if (PHP_VERSION_ID < MIN_PHP) {
    fail('PHP 8.4 or newer is needed (this is '.PHP_VERSION.').');
}
if (! is_file($backend.'/vendor/autoload.php')) {
    fail('backend/vendor/ is missing: run `composer install` in backend/ first (DEVELOPING.md).');
}
require $backend.'/vendor/autoload.php';

// ── 1. The Angular app ────────────────────────────────────────────────────────────────────────
$browser = $frontend.'/dist/frontend/browser';
if (! isset($options['skip-frontend'])) {
    step('Building the Angular app (production)');
    run('npx ng build --configuration production', $frontend);
}
if (! is_file($browser.'/index.html')) {
    fail("No Angular build in {$browser}; run without --skip-frontend.");
}

step('Assembling dist/build-analyzer');
removeTree($dist, $root);
mkdir($site, 0775, true);
copyTree($browser, $site, fn () => false);

// ── 2. The backend's runtime files ────────────────────────────────────────────────────────────
copyTree($backend.'/api', $site.'/api', fn () => false);
copyTree($backend.'/src', $site.'/src', fn () => false);
copyTree($backend.'/migrations', $site.'/migrations', fn () => false);
copyTree($backend.'/vendor', $site.'/vendor', fn () => false);
// config/: the committed app.php only; every *.local.php (the database password, the setup token) stays.
copyTree($backend.'/config', $site.'/config', fn (string $relative) => str_ends_with($relative, '.local.php'));
// storage/: the folders the app writes to, empty.
foreach (['cache', 'http-cache', 'icons', 'logs'] as $folder) {
    mkdir("{$site}/storage/{$folder}", 0775, true);
}

// ── 3. Only the app and /api are served ───────────────────────────────────────────────────────
file_put_contents($site.'/.htaccess', <<<'HTACCESS'
# The build analyzer - the web root: the Angular app, with the PHP API in api/.
# Written by scripts/build-dist.php; edit that, not this.

DirectoryIndex index.html

<IfModule mod_rewrite.c>
RewriteEngine On

# The API is its own handler (api/.htaccess sends every /api path to api/index.php).
RewriteRule ^api(/|$) - [L]

# Real files (the hashed bundles, the fonts, changelog.json, setup.php while it is there) as they are.
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# A file that is not there - a bundle of an older version, say - is a 404, not the app: a script answered
# with a page fails with a confusing error instead of a missing file.
RewriteRule \.(js|mjs|css|map|json|ico|png|jpe?g|gif|svg|webp|avif|woff2?|ttf|txt|php)$ - [R=404,L]

# Every other path is a page of the app.
RewriteRule ^ index.html [L]
</IfModule>

<IfModule mod_headers.c>
Header always set X-Frame-Options "DENY"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
# A new version must reach the browser at once; the bundles it names are hashed, and may be cached.
<FilesMatch "^(index\.html|changelog\.json)$">
Header set Cache-Control "no-cache"
</FilesMatch>
</IfModule>

HTACCESS);

$deny = "# Never served: read by PHP from disk only.\n"
    ."<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
foreach (['src', 'config', 'migrations', 'vendor', 'storage'] as $folder) {
    file_put_contents("{$site}/{$folder}/.htaccess", $deny);
}

// ── 4. The first-run installer, kept apart ────────────────────────────────────────────────────
copy($backend.'/setup.php', $dist.'/setup.php');

// ── 5. Nothing that must not be uploaded, nothing missing ─────────────────────────────────────
step('Checking the result');
$problems = [];
$files = 0;
$bytes = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS)) as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($site) + 1));
    $files++;
    $bytes += $file->getSize();

    $name = $file->getFilename();
    if (str_ends_with($name, '.local.php') || $name === '.env' || str_starts_with($name, '.env.') || $name === 'dev.local.ps1'
        || str_ends_with($name, '.sqlite') || str_ends_with($name, '.log')) {
        $problems[] = "must not ship: {$relative}";
    }
    if (preg_match('~(^|/)(laravel|illuminate)(/|$)~i', $relative)) {
        $problems[] = "a Laravel component: {$relative}";
    }
    // The UI names no other website, service or tool (the backend's own code may: it is not served).
    if (! str_contains($relative, '/') && preg_match('/\.(html|js|css|json|txt)$/', $name)) {
        $names = App\Support\Neutral::namesIn((string) file_get_contents($file->getPathname()));
        if ($names !== []) {
            $problems[] = "{$relative} names ".implode(', ', $names);
        }
    }
}
foreach (['index.html', 'changelog.json', 'api/index.php', 'api/.htaccess', 'vendor/autoload.php', 'config/app.php', 'migrations/002_schema.sql'] as $required) {
    if (! is_file("{$site}/{$required}")) {
        $problems[] = "missing: build-analyzer/{$required}";
    }
}
foreach (['tests', 'node_modules', 'router.php', 'migration.php', 'sync-builds.php', 'sync-catalog.php', 'import-account.php', 'setup.php', 'composer.json'] as $absent) {
    if (file_exists("{$site}/{$absent}")) {
        $problems[] = "should not be there: build-analyzer/{$absent}";
    }
}
if ($problems !== []) {
    fail("The build is not right:\n  ".implode("\n  ", $problems));
}

echo "\nBuilt dist/ ({$files} files, ".round($bytes / 1048576, 1)." MB):\n\n"
    ."  dist/build-analyzer/*   upload into the site's web root (the folder its address serves).\n"
    ."  dist/setup.php          first install only: upload beside it, open https://<site>/setup.php.\n\n"
    ."Writable for the web server: config/ (setup.php writes its files there) and storage/.\n"
    ."PHP 8.4+ with pdo_pgsql, curl, dom, mbstring and openssl. Details: DEVELOPING.md (\"Deploying\").\n";

// ──────────────────────────────────────────────────────────────────────────────────────────────

function step(string $message): void
{
    echo "\n== {$message}\n";
}

function fail(string $message): never
{
    fwrite(STDERR, "build-dist: {$message}\n");
    exit(1);
}

function run(string $command, string $directory): void
{
    $previous = getcwd();
    chdir($directory);
    passthru($command, $status);
    chdir($previous);
    if ($status !== 0) {
        fail("'{$command}' failed (exit code {$status}).");
    }
}

/**
 * Copy a folder; $skip gets each path relative to $from ("routes/admin.php") and may leave it out.
 */
function copyTree(string $from, string $to, callable $skip): void
{
    if (! is_dir($from)) {
        fail("{$from} is missing.");
    }
    @mkdir($to, 0775, true);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));
        if ($skip($relative)) {
            continue;
        }
        $target = "{$to}/{$relative}";
        if ($item->isDir()) {
            @mkdir($target, 0775, true);
        } else {
            @mkdir(dirname($target), 0775, true);
            copy($item->getPathname(), $target);
        }
    }
}

/**
 * Delete dist/ (and only a folder inside the repository).
 */
function removeTree(string $path, string $root): void
{
    if (! is_dir($path)) {
        return;
    }
    if (! str_starts_with(realpath($path) ?: '', realpath($root) ?: "\0")) {
        fail("refusing to delete {$path}");
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
