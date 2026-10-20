<?php

namespace App\Services\Catalog;

/**
 * What a catalog provider answered for one list (CatalogProvider::fetch()): its rows as the catalog stores them, or
 * why there are none this time.
 *
 *   changed      the list as it is now (rows), with its version tag (etag) for the next conditional request
 *   unchanged    the provider says the list is as it was (HTTP 304): nothing to write
 *   closed       the provider's site is closed for now - maintenance, sign-in required, a route locked
 *   unavailable  the provider has no such list (any more)
 *   failed       anything else: no connection, an error, an answer the catalog cannot read
 */
final class CatalogAnswer
{
    public const CHANGED = 'changed';

    public const UNCHANGED = 'unchanged';

    public const CLOSED = 'closed';

    public const UNAVAILABLE = 'unavailable';

    public const FAILED = 'failed';

    /**
     * @param  list<array{key: string, row: array<string, mixed>}>  $rows
     */
    private function __construct(public readonly string $outcome, public readonly array $rows = [], public readonly ?string $etag = null, public readonly ?string $problem = null) {}

    /** @param list<array{key: string, row: array<string, mixed>}> $rows */
    public static function changed(array $rows, ?string $etag): self
    {
        return new self(self::CHANGED, $rows, $etag);
    }

    public static function unchanged(?string $etag): self
    {
        return new self(self::UNCHANGED, [], $etag);
    }

    /** No rows this time: closed, unavailable or failed, and why in a sentence (English, for the admin panel). */
    public static function none(string $outcome, string $problem): self
    {
        return new self($outcome, [], null, $problem);
    }
}
