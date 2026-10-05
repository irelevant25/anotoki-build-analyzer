#!/usr/bin/env bash
# Runs PHP 8.4+ for this project (bash / Git Bash), from any directory: $BUILD_ANALYZER_PHP, else `php` on PATH.
# Usage: backend/tools/php.sh -v    |    cd backend && tools/php.sh tests/run.php
set -euo pipefail

php="${BUILD_ANALYZER_PHP:-php}"

# Windows paths (C:\... or C:/...) need converting under Git Bash.
if command -v cygpath >/dev/null 2>&1 && [[ "$php" =~ ^[A-Za-z]: ]]; then
    php="$(cygpath -u "$php")"
fi

# Some PHP builds print startup warnings on stdout, so read the version from a marker.
version="$("$php" -r 'echo "VERSION=".PHP_VERSION_ID;' 2>/dev/null | sed -n 's/.*VERSION=\([0-9][0-9]*\).*/\1/p' | head -n 1)"
if [ "${version:-0}" -lt 80400 ]; then
    echo "backend/tools/php.sh: '$php' is not PHP 8.4+. Set BUILD_ANALYZER_PHP to the PHP 8.4+ to use." >&2
    exit 1
fi

exec "$php" "$@"
