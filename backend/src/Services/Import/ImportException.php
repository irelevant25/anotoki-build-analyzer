<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * An import problem the user can fix (wrong UID, private showcase, unsupported file...).
 *
 * It says what happened twice: as a kind with its parameters (ImportKinds::ERRORS), which a page words in the
 * reader's language, and as the English message, which the command line prints and the import history keeps.
 * Neither may name a website, service or tool.
 */
class ImportException extends RuntimeException
{
    /**
     * @param  string  $kind  a key of ImportKinds::ERRORS (not $code: Exception::$code is taken)
     * @param  array<string, string|int>  $params  exactly the parameters ImportKinds::ERRORS lists for $kind
     */
    public function __construct(string $message, public readonly string $kind = 'unexpected', public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
