<?php

use App\App;
use App\Support\Log;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Until config/database.local.php, auth.local.php and fetch.local.php exist (setup.php writes them), every
 * API request is answered 503 {"code": "not_set_up"}, and the frontend shows a "not set up yet" page with
 * the way to /setup.php. Which file is missing is said in the log only.
 */
function setupGate(App $app): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        if ($app->config->isSetUp()) {
            return $handler->handle($request);
        }

        Log::info('Not set up: '.implode('; ', $app->config->problems()));

        return Json::send(new Slim\Psr7\Response, [
            'code' => 'not_set_up',
            'message' => 'The build analyzer has not been set up yet. Open /setup.php to set it up.',
            'setup' => '/setup.php',
        ], 503)->withHeader('Retry-After', '60');
    };
}
