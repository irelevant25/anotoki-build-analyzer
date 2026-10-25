<?php

use Anotoki\Lib\Robots\Http\RobotsRoutes;
use Anotoki\Lib\Robots\Robots;
use App\App;

/**
 * What search engines' robots read (anotoki-lib 0.11.0's Robots, on this site's pages - App\Web\SitePages): both
 * public, nobody's, `Cache-Control: public, max-age=3600`, and behind the site gate like any route (a robot told 503
 * with Retry-After keeps what it had).
 *
 *   GET|HEAD /robots.txt    every robot kept out of /admin, /api, /auth, /accounts, /sign-in and /staff; then
 *                           `Sitemap: <the site's address>/sitemap.xml`
 *   GET|HEAD /sitemap.xml   the legal pages, About and Support; while the IAM lets visitors in, the dashboard, the
 *                           import, the builds browser and every character's builds page (the day one of its builds
 *                           last changed) - never what Page access hides; 404 not_configured while no address is
 *
 * The address is the installation's (App\Config::siteAddress(): config/site.local.php, else a release's or a
 * checkout's own), never a request's Host. Neither path is under /api: router.php sends both to the front controller
 * in development, and the release's frontend/.htaccess on the host (composer.json's extra["anotoki-deploy"]["routes"]),
 * before any file of the app is looked for - the app keeps no robots.txt of its own.
 */
return function (Slim\App $slim, App $app): void {
    $robots = $app->get(Robots::class);

    RobotsRoutes::robots($slim, $robots);
    RobotsRoutes::sitemap($slim, $robots);
};
