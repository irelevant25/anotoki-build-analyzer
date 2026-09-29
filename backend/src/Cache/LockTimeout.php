<?php

namespace App\Cache;

use RuntimeException;

/** A lock that did not come free in the time a request was willing to wait. */
final class LockTimeout extends RuntimeException {}
