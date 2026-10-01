<?php

namespace App\Web;

use RuntimeException;

/**
 * A request answered with an error status and an English message ({"message": ...}).
 *
 * An error a member can meet also says what happened as a "code" in $extra, so a page can word it in the
 * reader's language: sign_in_required, local_only, no_access, admin_only, too_many_imports, not_found,
 * method_not_allowed, server_error, not_set_up, invalid, or an import kind with its "params"
 * (App\Services\Import\ImportKinds::ERRORS). The errors of the admin-only routes carry their sentence alone -
 * but for the routes of the site's own strings, whose refusals say which key and language they are about
 * beside a code (App\Services\Translations\TranslationCheck), and answer translations_unavailable (503)
 * while the strings are not in the database.
 */
class HttpError extends RuntimeException
{
    /** @param array<string, mixed> $extra more fields for the JSON answer (code, params, errors ...) */
    public function __construct(public readonly int $status, string $message, public readonly array $extra = [], public readonly array $headers = [])
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(404, 'Not found.', ['code' => 'not_found']);
    }
}
