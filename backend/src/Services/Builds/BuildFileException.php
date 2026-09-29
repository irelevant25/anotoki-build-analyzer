<?php

namespace App\Services\Builds;

use RuntimeException;

/**
 * A builds file that was not imported (not a builds file, another source's, a malformed build ...). The
 * message names the first problem in one sentence, shown in the UI as is; nothing of the file was written.
 */
final class BuildFileException extends RuntimeException {}
