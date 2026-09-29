<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * An import problem the user can fix (wrong UID, private showcase, unsupported file...).
 * The message is shown in the UI as is.
 */
class ImportException extends RuntimeException {}
