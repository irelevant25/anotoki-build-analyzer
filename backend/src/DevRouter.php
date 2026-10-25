<?php

namespace App;

/**
 * What router.php (PHP's built-in server in development) does with a path - the same as the Apache the
 * app runs on: /api to the API, and /robots.txt and /sitemap.xml too (anotoki-lib 0.11.0's RobotsRoutes, as the
 * release's frontend/.htaccess sends them - before any file of the app is looked for), /setup.php to the
 * installer, a file of the built app as itself, any other app path (/accounts/3, /auth/callback) as the app's
 * index.html, and a missing file (an old hashed chunk after a new build) as a 404 rather than the app.
 */
final class DevRouter
{
    /** The front controller's paths outside /api: what robots read (composer.json's extra["anotoki-deploy"]["routes"]). */
    public const ROBOTS_PATHS = ['/robots.txt', '/sitemap.xml'];

    /**
     * @return array{0: 'api'|'setup'|'file'|'app'|'missing'|'no-app', 1: string|null}
     */
    public static function resolve(string $path, ?string $appDir): array
    {
        if ($path === '/api' || str_starts_with($path, '/api/') || in_array($path, self::ROBOTS_PATHS, true)) {
            return ['api', null];
        }
        if ($path === '/setup.php') {
            return ['setup', null];
        }

        $app = $appDir === null ? false : realpath($appDir);
        if ($app === false || !is_file($app . DIRECTORY_SEPARATOR . 'index.html')) {
            return ['no-app', null];
        }

        $file = realpath($app . $path);
        if ($file !== false && is_file($file) && str_starts_with($file, $app . DIRECTORY_SEPARATOR)) {
            return ['file', $file];
        }
        if (pathinfo($path, PATHINFO_EXTENSION) !== '') {
            return ['missing', null];
        }

        return ['app', $app . DIRECTORY_SEPARATOR . 'index.html'];
    }
}
