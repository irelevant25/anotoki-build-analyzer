<?php

/**
 * PHP's built-in server, made to behave like the Apache the app runs on:
 *
 *     composer start          (php -S localhost:8400 router.php)
 *
 *   /api/...     the API (api/index.php), whatever the path looks like: without this, `php -S` treats a
 *                path with a dot in its last segment as a static file and answers 404 itself
 *   /robots.txt, the API's front controller too (anotoki-lib 0.11.0's RobotsRoutes), before any file of the
 *   /sitemap.xml build is looked for - as the release's frontend/.htaccess sends them
 *   /setup.php   the installer
 *   anything else the built Angular app (frontend/dist/frontend/browser) when there is one - its files, and
 *                index.html for the app's own paths (/accounts/3, /auth/callback), never for a missing
 *                file such as an old hashed chunk (App\DevRouter)
 *
 * During development the Angular dev server (http://localhost:4600) serves the app and proxies /api,
 * /robots.txt, /sitemap.xml and /setup.php here.
 */

require_once __DIR__ . '/vendor/autoload.php';

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
[$kind, $file] = App\DevRouter::resolve($path, __DIR__ . '/../frontend/dist/frontend/browser');

switch ($kind) {
    case 'api':
        require __DIR__ . '/api/index.php';
        break;

    case 'setup':
        require __DIR__ . '/setup.php';
        break;

    case 'file':
        $types = [
            'js' => 'text/javascript',
            'css' => 'text/css',
            'html' => 'text/html; charset=utf-8',
            'json' => 'application/json',
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'txt' => 'text/plain; charset=utf-8',
        ];
        header('Content-Type: ' . ($types[strtolower(pathinfo((string) $file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        readfile((string) $file);
        break;

    case 'app':
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile((string) $file);
        break;

    case 'missing':
        http_response_code(404);
        break;

    default:
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "The API is at /api and the installer at /setup.php. The app itself is served by the Angular dev server\n" .
            "(cd frontend && npm start, then http://localhost:4600), or here after `ng build`.\n";
}

return true;
