<?php

namespace App\Services\Builds;

use RuntimeException;

/**
 * A source's builds cannot be imported while a sync of that source is going: its next step would carry on
 * writing the site's builds over the file's. The message says so, for the UI as is.
 */
final class SourceSyncing extends RuntimeException {}
