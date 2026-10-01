<?php

/**
 * The application: every route and middleware of the API, not yet running.
 *
 *   meta, icons            public: what the frontend reads before anybody is signed in
 *   accounts, imports,     members (MEMBER or ADMIN): their own accounts only
 *   builds, sources
 *   sync, admin            admins: syncs, sources on / off, migrations
 *   translations           the site's own words: the strings of a language are public; the languages
 *                          and the grid the strings are reworded in are for admins
 *
 * Middleware, outermost first: a fresh request scope, security headers, the setup gate (503 "not_set_up"
 * until config/*.local.php exist), JSON errors, body parsing, routing; then per group the role check.
 */

use App\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;

require_once __DIR__.'/middleware/errors.php';
require_once __DIR__.'/middleware/security.php';
require_once __DIR__.'/middleware/setup_gate.php';
require_once __DIR__.'/middleware/auth.php';

function buildAnalyzerApi(App $app): Slim\App
{
    $slim = AppFactory::create();

    foreach (['meta', 'icons', 'accounts', 'imports', 'builds', 'sources', 'sync', 'admin', 'translations'] as $routes) {
        (require __DIR__."/routes/{$routes}.php")($slim, $app);
    }

    // Added innermost first: Slim runs the last one added first.
    $slim->addBodyParsingMiddleware();
    $slim->addRoutingMiddleware();
    $slim->add(jsonErrors($app, $slim));
    $slim->add(setupGate($app));
    $slim->add(securityHeaders());
    // Each request starts with fresh services (the catalog's cached rows, a sync runner), as each request of
    // the host's PHP would; in the tests one process handles many.
    $slim->add(function (ServerRequestInterface $request, $handler) use ($app): ResponseInterface {
        $app->resetScope();

        return $handler->handle($request);
    });

    return $slim;
}
