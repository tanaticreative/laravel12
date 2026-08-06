#!/usr/bin/env bash
#
# Runs every load check and reports a combined verdict.
#
# Usage:
#   ./scripts/load-all.sh [--concurrency N] [--duration S]
#
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

BOLD=$'\033[1m'; RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; OFF=$'\033[0m'

FAILED=()

# Only the stampede script takes --duration, so arguments are routed rather
# than forwarded wholesale.
COMMON=()
DURATION_ARGS=()

while [ $# -gt 0 ]; do
    case "$1" in
        --concurrency) COMMON+=("$1" "$2"); shift 2 ;;
        --duration)    DURATION_ARGS+=("$1" "$2"); shift 2 ;;
        --keep-limits) COMMON+=("$1"); shift ;;
        -h|--help)     sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

for script in load-stampede.sh load-idempotency.sh load-oversell.sh; do
    args=("${COMMON[@]+"${COMMON[@]}"}")
    [ "$script" = load-stampede.sh ] && args+=("${DURATION_ARGS[@]+"${DURATION_ARGS[@]}"}")

    printf '\n%s============================================================%s\n' "$BOLD" "$OFF"
    printf '%s %s %s\n' "$BOLD" "$script" "$OFF"
    printf '%s============================================================%s\n' "$BOLD" "$OFF"

    if ! "$DIR/$script" "${args[@]+"${args[@]}"}"; then
        FAILED+=("$script")
    fi
done

printf '\n%s============================================================%s\n' "$BOLD" "$OFF"

if [ "${#FAILED[@]}" -eq 0 ]; then
    printf '%s ALL LOAD CHECKS PASSED %s\n' "$GREEN$BOLD" "$OFF"
    exit 0
fi

printf '%s FAILED: %s %s\n' "$RED$BOLD" "${FAILED[*]}" "$OFF"
exit 1
