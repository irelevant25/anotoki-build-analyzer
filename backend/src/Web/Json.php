<?php

namespace App\Web;

use Psr\Http\Message\ResponseInterface;

/**
 * JSON answers.
 */
final class Json
{
    public static function send(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /** {"message": ...} with a status, plus any extra fields. */
    public static function message(ResponseInterface $response, int $status, string $message, array $extra = []): ResponseInterface
    {
        return self::send($response, ['message' => $message] + $extra, $status);
    }
}
