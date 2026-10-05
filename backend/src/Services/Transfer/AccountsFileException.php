<?php

namespace App\Services\Transfer;

use RuntimeException;

/**
 * A file of a person's accounts that was not imported, or accounts too large to export as one: what went
 * wrong as a kind (the page words it) with its parameters, and the first problem in one English sentence.
 * Nothing was written.
 *
 *   no_file           the request arrived without a body (also what PHP makes of one past post_max_size)
 *   file_too_big      {max_mb}: larger than config/app.php me.file_max_mb
 *   not_a_data_file   not JSON, or not this format
 *   newer_version     {version}: written by a newer version of the format than this copy reads
 *   bad_entry         {path}: the first entry that is not as the export writes it ("accounts[2].items[14].slot")
 *   too_many_accounts {max}: the import would leave the person with more accounts than one file carries
 *   export_too_big    {max_mb, max_accounts}: the person's accounts do not fit in one file
 */
final class AccountsFileException extends RuntimeException
{
    /** @param array<string, string|int|float> $params */
    public function __construct(public readonly string $kind, public readonly array $params, string $message)
    {
        parent::__construct($message);
    }
}
