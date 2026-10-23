<?php

namespace App\Services\Builds;

use RuntimeException;

/**
 * An editor's save made against builds that are not the ones there now - an import, or another editor's save, came
 * in between (BuildEditor): nothing was written; the page reloads them, the editor's work kept until then.
 */
final class BuildEditConflict extends RuntimeException {}
