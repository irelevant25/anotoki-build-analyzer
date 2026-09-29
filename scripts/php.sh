#!/usr/bin/env bash
# Runs PHP 8.4+ for this project (bash / Git Bash), from any directory.
# Resolution order: $BUILD_ANALYZER_PHP, the $env:BUILD_ANALYZER_PHP line in dev.local.ps1, then `php` on PATH.
# Usage: scripts/php.sh -v    |    cd backend && ../scripts/php.sh tests/run.php
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php="${BUILD_ANALYZER_PHP:-}"

if [ -z "$php" ] && [ -f "$root/dev.local.ps1" ]; then
    php="$(sed -n "s/^[[:space:]]*\$env:BUILD_ANALYZER_PHP[[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" "$root/dev.local.ps1" | head -n 1)"
fi
php="${php:-php}"

# Windows paths (C:\... or C:/...) need converting under Git Bash.
if command -v cygpath >/dev/null 2>&1 && [[ "$php" =~ ^[A-Za-z]: ]]; then
    php="$(cygpath -u "$php")"
fi

# Some PHP builds print startup warnings on stdout, so read the version from a marker.
version="$("$php" -r 'echo "VERSION=".PHP_VERSION_ID;' 2>/dev/null | sed -n 's/.*VERSION=\([0-9][0-9]*\).*/\1/p' | head -n 1)"
if [ "${version:-0}" -lt 80400 ]; then
    echo "scripts/php.sh: '$php' is not PHP 8.4+. Set BUILD_ANALYZER_PHP or put \$env:BUILD_ANALYZER_PHP = 'C:\\path\\to\\php.exe' in dev.local.ps1." >&2
    exit 1
fi

exec "$php" "$@"
