<?php

namespace App\Services\Builds;

use RuntimeException;

/**
 * The questions an import asks are no longer the ones its preview answered - an editor saved a build, or another
 * import came, in between -: this one wrote nothing, and the file is to be checked again (BuildImport).
 */
final class BuildImportPlanChanged extends RuntimeException {}
