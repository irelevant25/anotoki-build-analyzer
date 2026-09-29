<?php

namespace App\Web;

use RuntimeException;

/**
 * A request answered with an error status and a message the UI shows as it is ({"message": ...}).
 */
class HttpError extends RuntimeException
{
    /** @param array<string, mixed> $extra more fields for the JSON answer */
    public function __construct(public readonly int $status, string $message, public readonly array $extra = [], public readonly array $headers = [])
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(404, 'Not found.');
    }
}
