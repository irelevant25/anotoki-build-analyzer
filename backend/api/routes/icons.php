<?php

use App\App;
use App\Http\ConnectionFailed;
use App\Http\HttpClient;
use App\Presenters\Icons;
use App\Repositories\CatalogRows;
use App\Support\Log;
use App\Web\HttpError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/icons/{c|w|s}/{id} - a catalog icon (character, weapon, set), served from this site.
 *
 * The UI never loads anything from another site, so the icons the catalog lists are fetched here, once, and
 * kept in storage/icons/. Only addresses stored by a catalog sync are ever fetched - the route takes a
 * catalog row's id, not an address - so it cannot be pointed anywhere else. Public, like the images of any
 * page: an <img> sends no bearer token.
 */
return function (Slim\App $slim, App $app): void {
    $slim->get('/api/icons/{type:[cws]}/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($app): Response {
        $entry = $app->get(CatalogRows::class)->find(Icons::TYPES[$args['type']], (int) $args['id']);
        $source = $entry?->icon_url;
        if (! is_string($source) || ! preg_match('#^https?://#i', $source)) {
            throw HttpError::notFound();
        }

        $file = $app->config->storage('icons/'.sha1($source).'.img');
        if (! is_file($file)) {
            try {
                $answer = $app->get(HttpClient::class)->get($source, [
                    'User-Agent' => (string) $app->config->get('fetch.user_agent'),
                    'Accept' => 'image/*',
                ], 10);
            } catch (ConnectionFailed $e) {
                Log::info("Icon {$args['type']}/{$args['id']}: ".$e->getMessage());

                throw HttpError::notFound();
            }

            if ($answer->status !== 200 || Icons::type($answer->body) === null || strlen($answer->body) > 2_000_000) {
                throw HttpError::notFound();
            }
            if (! is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            @file_put_contents($file, $answer->body, LOCK_EX);
            $body = $answer->body;
        } else {
            $body = (string) file_get_contents($file);
        }

        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', Icons::type($body) ?? 'application/octet-stream')
            ->withHeader('Cache-Control', 'public, max-age=604800')
            ->withHeader('Content-Security-Policy', "default-src 'none'");
    });
};

