<?php

namespace App\Http;

use RuntimeException;

/** No answer from the other site: no connection, a DNS failure, a timeout. */
final class ConnectionFailed extends RuntimeException {}
