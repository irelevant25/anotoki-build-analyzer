<?php

use App\App;
use App\Auth\Visitors;
use App\Web\Input;
use App\Web\Json;
use App\Web\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * At sign-in, what this browser imported as a visitor becomes the signed-in person's - the one place the
 * two identities meet, so it takes both: the person's token (requireSignIn) and the visitor's key.
 *
 *   POST /api/visitor/move   X-Visitor-Key: <key>   {silent?: bool}   -> {found, moved, kept, imports, held}
 *
 * In one transaction (App\Auth\Visitors::moveInto()): a game account both have (the same game and UID)
 * stays the person's as it is and the visitor's copy goes (kept); every other account and import run
 * becomes the person's (moved, imports); the visitor is gone, and its key opens nothing from then on.
 * silent: the sign-in was the IAM's sign-in hint's silent hop, nobody's click (the page knows: the library's
 * callback said so). Then nothing moves into a person who already has data here - a game account or an
 * import run: {found: true, held: true} with zeros, and the visitor stays as it is, its key still opening its
 * data on this browser (the owner's decision of 2026-10-11); a person with nothing here yet takes it in.
 * A key nobody has - moved already by another tab, swept, never there - is {found: false} with zeros, so
 * the move can be asked again safely. No key, or one that is not a key: 422 invalid. An import still
 * writing, or one of the person's at the same moment: 503 visitor_move_retry (ask again). The person's
 * own row deleted meanwhile (the People page): 401 sign_in_required, nothing moved. Migration 004
 * not applied: 503 visitors_unavailable.
 *
 * It works whether or not the IAM says this site requires sign-in: switching that on stops a key from being
 * somebody, not from being handed in with a token - so what a visitor imported before still comes into
 * their account when they sign in.
 */
return function (Slim\App $slim, App $app): void {
    $slim->group('/api/visitor', function (RouteCollectorProxy $group) use ($app) {
        $group->post('/move', function (Request $request, Response $response) use ($app): Response {
            $key = Visitors::keyOf($request);
            if ($key === null) {
                throw new ValidationError(['visitor_key' => ['The X-Visitor-Key header is required.']]);
            }
            if (! Visitors::wellFormed($key)) {
                throw new ValidationError(['visitor_key' => ['The X-Visitor-Key header is not a visitor key.']]);
            }
            $input = Input::of($request);
            $silent = $input->boolean('silent') ?? false;
            $input->valid();

            return Json::send($response, $app->get(Visitors::class)->moveInto(currentUser($request), $key, $silent));
        });
    })->add(requireSignIn($app));
};
