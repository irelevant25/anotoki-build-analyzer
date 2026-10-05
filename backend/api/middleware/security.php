<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Baseline security headers on every API answer.
 *
 * No CORS header, ever: the app and the API share one origin (the development server proxies /api), so a
 * browser keeps every other site from reading the API, and from sending it a header of the site's own -
 * the bearer token and a visitor's X-Visitor-Key above all, which nothing but the app's pages ever puts on a
 * request (neither is a cookie, so nothing rides along on a request another site makes). Cache-Control:
 * no-store keeps every answer - the one carrying a new visitor's key too - out of every cache. A route
 * that sets one of these headers itself (the icon route) keeps its own.
 */
function securityHeaders(): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $response = $handler->handle($request);

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cache-Control' => 'no-store',
        ];
        foreach ($defaults as $name => $value) {
            if (! $response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    };
}
