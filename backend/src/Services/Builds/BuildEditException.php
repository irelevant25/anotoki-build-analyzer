<?php

namespace App\Services\Builds;

use RuntimeException;

/**
 * An editor's save refused (BuildEditor): nothing was written. What kind of refusal (`kind`: invalid_body,
 * invalid_build, invalid_note, names_another_site), which build of the save (its index) and where in it (a path
 * such as "qualifier" or "notes.teams[1].sk") come along, so the editor's page can say it at its field; the message
 * says it in one sentence, neutral.
 */
final class BuildEditException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'invalid_build', public readonly ?int $build = null, public readonly ?string $path = null)
    {
        parent::__construct($message);
    }
}
