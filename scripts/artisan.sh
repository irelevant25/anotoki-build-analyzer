#!/usr/bin/env bash
# Runs `php artisan` in backend/ with the project's PHP (see scripts/php.sh), from any directory.
# Usage: scripts/artisan.sh test    |    scripts/artisan.sh builds:sync prydwen.hsr --character=kafka --force
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root/backend"
exec "$root/scripts/php.sh" artisan "$@"
