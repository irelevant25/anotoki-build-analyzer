#!/usr/bin/env bash
# Headless screenshot or DOM dump of a page with the installed Edge (or Chrome), for Git Bash on Windows.
#
#   screenshot.sh URL OUT.png [WIDTH=1400] [HEIGHT=1600] [BUDGET_MS=30000]
#   screenshot.sh --dom URL OUT.html [BUDGET_MS=20000]     # rendered DOM; console output goes to OUT.html.console.log
#
# Uses a throwaway browser profile in the temp folder, never the user's own profile.
set -euo pipefail

mode=shot
if [ "${1:-}" = "--dom" ]; then
    mode=dom
    shift
fi
url="${1:?URL required}"
out="${2:?output file required}"

browser=""
for candidate in \
    "/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" \
    "/c/Program Files/Microsoft/Edge/Application/msedge.exe" \
    "/c/Program Files/Google/Chrome/Application/chrome.exe" \
    "$(command -v msedge 2>/dev/null || true)" \
    "$(command -v google-chrome 2>/dev/null || true)"; do
    if [ -n "$candidate" ] && [ -x "$candidate" ]; then
        browser="$candidate"
        break
    fi
done
if [ -z "$browser" ]; then
    echo "screenshot.sh: no Edge or Chrome found" >&2
    exit 1
fi

tmp="${TMPDIR:-${TEMP:-/tmp}}"
profile="$tmp/hoyo-headless-profile"
to_native() { if command -v cygpath >/dev/null 2>&1; then cygpath -wa "$1"; else echo "$1"; fi; }

common=(--headless=new --disable-gpu --hide-scrollbars --no-first-run --run-all-compositor-stages-before-draw
    "--user-data-dir=$(to_native "$profile")")

if [ "$mode" = dom ]; then
    budget="${3:-20000}"
    "$browser" "${common[@]}" --enable-logging=stderr --log-level=0 --virtual-time-budget="$budget" --dump-dom "$url" \
        > "$out" 2> "$out.console.log" || true
    echo "DOM: $out ($(wc -c < "$out") bytes); console: $out.console.log"
    grep -E 'CONSOLE|Uncaught|ERROR' "$out.console.log" | grep -vE 'edge_|elevation|extension|registry|lastError' | tail -n 20 || true
    exit 0
fi

width="${3:-1400}"
height="${4:-1600}"
budget="${5:-30000}"
rm -f "$out"
"$browser" "${common[@]}" --window-size="$width,$height" --virtual-time-budget="$budget" \
    --screenshot="$(to_native "$out")" "$url" > /dev/null 2>&1 || true

if [ -s "$out" ]; then
    echo "saved $out ($(wc -c < "$out") bytes)"
else
    echo "screenshot.sh: no screenshot written for $url" >&2
    exit 1
fi
