<?php

namespace App\Web;

use RuntimeException;

/**
 * A request answered with an error status and an English message ({"message": ...}).
 *
 * An error a member can meet also says what happened as a "code" in $extra, so a page can word it in the
 * reader's language: sign_in_required, editor_only, admin_only, too_many_imports, not_found,
 * method_not_allowed, server_error, not_set_up, invalid, or an import kind with its "params"
 * (App\Services\Import\ImportKinds::ERRORS) - and a visitor's (App\Auth\Visitors): visitor_unknown (401),
 * too_many_visitors (429), visitor_limit (422, params {limit: accounts | file_size, max}),
 * visitors_unavailable (503, migration 004 pending), visitor_move_retry (503, ask again) - and those of a
 * person's own accounts file (App\Services\Transfer\AccountsFileException, 422): no_file, file_too_big,
 * not_a_data_file, newer_version, bad_entry, too_many_accounts, export_too_big. The errors of the admin
 * area's routes carry their sentence alone -
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
