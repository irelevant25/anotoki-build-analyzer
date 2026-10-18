<?php

use Anotoki\Lib\ErrorLog\ErrorLog;
use App\App;
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
 * "server_error" that says nothing about the server; what it was goes into the error log (the anotoki
 * library's: storage/logs/errors-*.jsonl, redacted, with its trace and the route - the admin panel's Error log
 * page, the place an FTP-only host lets anybody read it). A decided answer (an HttpError, a 404 for a path
 * nobody has) is written there by the error log's watch on every answer instead, when its status is one worth
 * seeing (App\Admin\AdminTools::errorLog()).
 */
function jsonErrors(App $app, Slim\App $slim): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $slim): ResponseInterface {
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
            $app->get(ErrorLog::class)->record($e, $request, 500, 'server_error');

            return Json::message($slim->getResponseFactory()->createResponse(), 500, 'Something went wrong on the server. Try again, and if it keeps happening, tell the admin.', [
                'code' => 'server_error',
            ]);
        }
    };
}
