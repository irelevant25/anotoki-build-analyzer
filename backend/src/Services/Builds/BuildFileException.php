<?php

namespace App\Services\Builds;

use RuntimeException;

/**
 * A builds file that was not imported (not a builds file, another source's, a malformed build ...), or a build of an
 * editor's save that was not saved. The message names the first problem in one sentence, shown in the UI as is;
 * nothing was written. Where the problem is in a build, which build (its index in the file or the save), the path in
 * it ("weapons[0].refinement") and the problem itself ("must be a whole number from 1 to 5, or null") come along, each
 * neutral - so an editor's page can say it at its field.
 */
final class BuildFileException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $build = null, public readonly ?string $path = null, public readonly ?string $problem = null)
    {
        parent::__construct($message);
    }
}
