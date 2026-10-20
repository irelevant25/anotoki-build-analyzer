<?php

namespace App\Services\Builds;

use RuntimeException;

/**
 * Another import of builds holds the lock: this one wrote nothing (BuildImport).
 */
final class BuildImportBusy extends RuntimeException {}
