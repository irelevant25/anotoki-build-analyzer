<?php

namespace App\Http;

/**
 * An answer from another site.
 */
final readonly class HttpResponse
{
    /**
     * @param  array<string, string>  $headers  lower-case names
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }

    /** The body as JSON, or null when it is not JSON. */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }
}
