<?php

/**
 * The application: every route and middleware of the API, not yet running.
 *
 *   meta, icons            public: what the frontend reads before anybody is signed in (always, even when
 *                          the IAM says this site requires sign-in - meta is where the page learns it, with
 *                          where people sign in: the anotoki library's boot configuration)
 *   builds                 public while the site is open to everybody: the builds browser and how fresh the
 *                          builds are (sign-in required: 401 sign_in_required without a token)
 *   accounts, imports      whose own data it is (requireOwner): the signed-in person, any role or none - or,
 *                          without a sign-in, this browser's visitor (X-Visitor-Key), whom the first import
 *                          that goes through makes; each reaches their own accounts only (sign-in required:
 *                          no visitor - a key is refused unread)
 *   visitor                the move of a visitor's accounts into the signed-in person's (token and key;
 *                          whether or not sign-in is required)
 *   me                     a signed-in person's own accounts as a file, out and back (never a visitor's)
 *   admin_builds           editors (EDITOR or ADMIN): a game's builds imported from a file and exported to one,
 *                          the imports made, the catalog refreshed list by list
 *   admin                  admins: migrations (the Migrations page's API: anotoki/lib's routes)
 *   admin_tools            admins: the error log, the audit log, feedback, backups (anotoki/lib's admin tools) -
 *                          and, public, the errors the pages meet in the browser into the error log
 *                          (POST /api/client-errors)
 *   translations           the site's own words: the strings of a language are public (always); the grid the
 *                          strings are reworded in is for editors; adding, renaming, switching and
 *                          deleting a language for admins
 *   people                 admins: the people who used the site, and deleting a person's data (anotoki/lib's
 *                          people module)
 *   feedback               public: the visitors' feedback form (anotoki/lib's)
 *   site_settings          public: what every page is told of the site settings, GET /api/site-settings; admins:
 *                          the Site settings page's API, /api/admin/site-settings (anotoki/lib's, App\Web\SitePages)
 *   robots                 public: GET /robots.txt and /sitemap.xml (anotoki/lib's - router.php and the release's
 *                          front controller send both here beside /api)
 *   site-status            public: where the site stands, {state, installed} (anotoki/lib) - what the pages
 *                          ask first
 *
 * Middleware, outermost first: a fresh request scope, security headers, the error log's watch on every answer,
 * JSON errors, the site gate (503 "not_set_up" until config/*.local.php are there; 503 "update_pending" while a
 * migration waits, but on its open paths; 503 "maintenance" while the site's ADMIN has closed the site, to anybody
 * but that ADMIN - api/middleware/site_gate.php), the upkeep after the answer (the scrub of the IAM's deleted
 * accounts, the day's backup and tidying up), routing, body parsing; then per group who the request is, its sign-in
 * or role check and Page access (the library's PageAccessGate: 423 page_locked for an API path a page's row refuses
 * with it - api/middleware/auth.php), so that a route added to a group cannot go without them.
 */

use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\ErrorLog\ErrorLogMiddleware;
use Anotoki\Lib\Migrations\Http\MigrationsRoutes;
use App\Admin\AdminTools;
use App\App;
use App\Web\AfterResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;

require_once __DIR__.'/middleware/errors.php';
require_once __DIR__.'/middleware/security.php';
require_once __DIR__.'/middleware/site_gate.php';
require_once __DIR__.'/middleware/auth.php';
require_once __DIR__.'/middleware/upkeep.php';

function buildAnalyzerApi(App $app): Slim\App
{
    $slim = AppFactory::create();
    $state = siteState($app);
    // This API's error log: each line names the route that failed (App\Admin\AdminTools).
    $errorLog = AdminTools::errorLog($app, $slim);
    $app->set(ErrorLog::class, $errorLog);

    foreach (['meta', 'icons', 'accounts', 'imports', 'visitor', 'me', 'builds', 'admin_builds', 'admin', 'admin_tools', 'translations', 'people', 'feedback', 'site_settings', 'robots'] as $routes) {
        (require __DIR__."/routes/{$routes}.php")($slim, $app);
    }
    // GET /api/site-status: the gate always lets it through.
    MigrationsRoutes::status($slim, $state);

    // Added innermost first: Slim runs the last one added first.
    $slim->addBodyParsingMiddleware();
    $slim->addRoutingMiddleware();
    // Inside the gate: a site that is not set up never asks the IAM or the database for its upkeep.
    $slim->add(upkeepAfterAnswer($app, $state));
    // Inside the JSON errors, so that whatever the gate might meet besides a database it cannot reach (which it
    // answers itself: the site is unavailable) is a JSON answer too.
    $slim->add(siteGate($slim, $state, $app));
    $slim->add(jsonErrors($app, $slim));
    // Outside the JSON errors: the answer as it went out. One worth seeing is written into the error log, unless
    // the exception behind it was already (jsonErrors() writes every one it answers 500).
    $slim->add(ErrorLogMiddleware::outer($errorLog));
    $slim->add(securityHeaders());
    // Each request starts with fresh services (the catalog's cached rows, the IAM's answer), as each request of
    // the host's PHP would; in the tests one process handles many. What it leaves for after its answer runs
    // here unless api/index.php holds it until the answer is sent (App\Web\AfterResponse).
    $slim->add(function (ServerRequestInterface $request, $handler) use ($app): ResponseInterface {
        $app->resetScope();

        try {
            return $handler->handle($request);
        } finally {
            AfterResponse::runUnlessHeld();
        }
    });

    return $slim;
}
