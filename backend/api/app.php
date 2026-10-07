<?php

/**
 * The application: every route and middleware of the API, not yet running.
 *
 *   meta, icons            public: what the frontend reads before anybody is signed in (always, even when
 *                          the IAM says this site requires sign-in - meta is where the page learns it)
 *   builds, GET sources    public while the site is open to everybody: the builds browser and how fresh the
 *                          sources are (sign-in required: 401 sign_in_required without a token)
 *   accounts, imports      whose own data it is (requireOwner): the signed-in person, any role or none - or,
 *                          without a sign-in, this browser's visitor (X-Visitor-Key), whom the first import
 *                          that goes through makes; each reaches their own accounts only (sign-in required:
 *                          no visitor - a key is refused unread)
 *   visitor                the move of a visitor's accounts into the signed-in person's (token and key;
 *                          whether or not sign-in is required)
 *   me                     a signed-in person's own accounts as a file, out and back (never a visitor's)
 *   sync, sources          editors (EDITOR or ADMIN): syncs, their history, a source's builds as a file;
 *                          admins: switching a source on or off
 *   admin                  admins: migrations (the Migrations page's API: anotoki/lib's routes)
 *   translations           the site's own words: the strings of a language are public (always); the grid the
 *                          strings are reworded in is for editors; adding, renaming, switching and
 *                          deleting a language for admins
 *   people                 admins: the people who used the site, and deleting a person's data
 *   site-status            public: where the site stands, {state, installed} (anotoki/lib) - what the pages
 *                          ask first
 *
 * Middleware, outermost first: a fresh request scope, security headers, JSON errors, the site gate (503
 * "not_set_up" until config/*.local.php are there; 503 "update_pending" while a migration waits, but on its
 * open paths - api/middleware/site_gate.php), routing, body parsing; then per group the sign-in or role check
 * (api/middleware/auth.php), so that a route added to a group cannot go without it.
 */

use Anotoki\Lib\Migrations\Http\MigrationsRoutes;
use App\App;
use App\Web\AfterResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;

require_once __DIR__.'/middleware/errors.php';
require_once __DIR__.'/middleware/security.php';
require_once __DIR__.'/middleware/site_gate.php';
require_once __DIR__.'/middleware/auth.php';

function buildAnalyzerApi(App $app): Slim\App
{
    $slim = AppFactory::create();
    $state = siteState($app);

    foreach (['meta', 'icons', 'accounts', 'imports', 'visitor', 'me', 'builds', 'sources', 'sync', 'admin', 'translations', 'people'] as $routes) {
        (require __DIR__."/routes/{$routes}.php")($slim, $app);
    }
    // GET /api/site-status: the gate always lets it through.
    MigrationsRoutes::status($slim, $state);

    // Added innermost first: Slim runs the last one added first.
    $slim->addBodyParsingMiddleware();
    $slim->addRoutingMiddleware();
    // Inside the JSON errors, so that whatever the gate might meet besides a database it cannot reach (which it
    // answers itself: the site is unavailable) is a JSON answer too.
    $slim->add(siteGate($slim, $state));
    $slim->add(jsonErrors($app, $slim));
    $slim->add(securityHeaders());
    // Each request starts with fresh services (the catalog's cached rows, a sync runner), as each request of
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
