<?php

namespace App\Http;

use RuntimeException;

class HttpStatusException extends RuntimeException
{
    public function __construct(public readonly string $url, public readonly int $status)
    {
        parent::__construct("HTTP {$status} from {$url}");
    }
}
