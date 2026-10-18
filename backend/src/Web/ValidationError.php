<?php

namespace App\Web;

/**
 * Input that did not pass: 422 with every message by field, and the first one (with how many more there
 * are) as the message - the shape the frontend's errorMessage() reads. Its code is always "invalid".
 */
final class ValidationError extends HttpError
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        $messages = array_merge(...array_values($errors));
        $more = count($messages) - 1;
        $message = ($messages[0] ?? 'The given data was invalid.') . ($more > 0 ? " (and {$more} more error" . ($more === 1 ? '' : 's') . ')' : '');

        parent::__construct(422, $message, ['code' => 'invalid', 'errors' => $errors]);
    }
}
