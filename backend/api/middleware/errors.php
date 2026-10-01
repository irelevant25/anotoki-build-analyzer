<?php

use App\App;
use App\Support\Log;
use App\Web\HttpError;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;

/**
 * Every failure as JSON: {"message": ...} with the right status, and validation errors by field.
 *
 * An HttpError carries its own status, its English message and - for what a member can meet - a "code" a
 * page can word in the reader's language (App\Web\HttpError lists them). Anything else is a 500
 * "server_error" that says nothing about the server; the details go to storage/logs/app.log, the only place
 * an FTP-only host lets anybody read them.
 */
function jsonErrors(App $app, Slim\App $slim): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($slim): ResponseInterface {
        try {
            return $handler->handle($request);
        } catch (HttpError $e) {
            $response = Json::message($slim->getResponseFactory()->createResponse(), $e->status, $e->getMessage(), $e->extra);
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        } catch (HttpNotFoundException) {
            return Json::message($slim->getResponseFactory()->createResponse(), 404, 'Not found.', ['code' => 'not_found']);
        } catch (HttpMethodNotAllowedException) {
            return Json::message($slim->getResponseFactory()->createResponse(), 405, 'Method not allowed.', ['code' => 'method_not_allowed']);
        } catch (Throwable $e) {
            Log::exception($e, $request->getMethod().' '.$request->getUri()->getPath());

            return Json::message($slim->getResponseFactory()->createResponse(), 500, 'Something went wrong on the server. Try again, and if it keeps happening, tell the admin.', ['code' => 'server_error']);
        }
    };
}
