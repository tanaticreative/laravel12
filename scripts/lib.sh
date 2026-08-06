#!/usr/bin/env bash
#
# Shared helpers for the load scripts. Not meant to be run directly.
#
# shellcheck disable=SC2034

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

BASE_URL="${BASE_URL:-http://localhost}"

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'
BLUE=$'\033[0;34m'; BOLD=$'\033[1m'; OFF=$'\033[0m'

FAILURES=0

hr()    { printf '%s\n' "------------------------------------------------------------"; }
title() { printf '\n%s==>%s %s%s%s\n' "$BLUE" "$OFF" "$BOLD" "$1" "$OFF"; }
info()  { printf '    %s\n' "$1"; }
# Diagnostics go to stderr: report_transport_failures() returns its count on
# stdout via command substitution, and a warning printed there would be
# captured as part of the number.
warn()  { printf '    %s! %s%s\n' "$YELLOW" "$1" "$OFF" >&2; }

# Report one assertion. Every check goes through here so the exit code and the
# summary cannot drift from what was printed.
check() {
    local label="$1" expected="$2" actual="$3"

    if [ "$expected" = "$actual" ]; then
        printf '    %s PASS %s %-46s %s\n' "$GREEN" "$OFF" "$label" "$actual"
    else
        printf '    %s FAIL %s %-46s %s (expected %s)\n' "$RED" "$OFF" "$label" "$actual" "$expected"
        FAILURES=$((FAILURES + 1))
    fi
}

# Same, but passes when the value is at or below a ceiling.
check_max() {
    local label="$1" ceiling="$2" actual="$3"

    if [ "$actual" -le "$ceiling" ]; then
        printf '    %s PASS %s %-46s %s (limit %s)\n' "$GREEN" "$OFF" "$label" "$actual" "$ceiling"
    else
        printf '    %s FAIL %s %-46s %s (limit %s)\n' "$RED" "$OFF" "$label" "$actual" "$ceiling"
        FAILURES=$((FAILURES + 1))
    fi
}

summary() {
    hr
    if [ "$FAILURES" -eq 0 ]; then
        printf '%s ALL CHECKS PASSED %s\n' "$GREEN$BOLD" "$OFF"
        return 0
    fi
    printf '%s %s CHECK(S) FAILED %s\n' "$RED$BOLD" "$FAILURES" "$OFF"
    return 1
}

# ------------------------------------------------------------------ env ----

env_value() {
    grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"'"'" || true
}

DB_DATABASE="$(env_value DB_DATABASE)"; DB_DATABASE="${DB_DATABASE:-laravel}"
DB_USERNAME="$(env_value DB_USERNAME)"; DB_USERNAME="${DB_USERNAME:-sail}"
DB_PASSWORD="$(env_value DB_PASSWORD)"; DB_PASSWORD="${DB_PASSWORD:-password}"
DB_HOST="$(env_value DB_HOST)";         DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)";         DB_PORT="${DB_PORT:-3306}"

# Prefer Sail's container when it is up: then the compose-internal DB_HOST
# ("mysql") resolves, which it would not from the host.
MYSQL_CONTAINER=""
if command -v docker >/dev/null 2>&1; then
    MYSQL_CONTAINER="$(docker compose ps -q mysql 2>/dev/null | head -1 || true)"
fi

artisan() {
    if [ -n "$MYSQL_CONTAINER" ] && [ -x vendor/bin/sail ]; then
        WWWUSER="${WWWUSER:-$(id -u)}" WWWGROUP="${WWWGROUP:-$(id -g)}" \
            ./vendor/bin/sail artisan "$@"
    else
        php artisan "$@"
    fi
}

db_query() {
    if [ -n "$MYSQL_CONTAINER" ]; then
        docker exec "$MYSQL_CONTAINER" \
            mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" -N -B -e "$1" "$DB_DATABASE" 2>/dev/null
    else
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
            -N -B -e "$1" "$DB_DATABASE" 2>/dev/null
    fi
}

# Cumulative SELECT counter. Server-wide, so keep the window around the load
# tight and do not run anything else against this database meanwhile.
db_selects() {
    if [ -n "$MYSQL_CONTAINER" ]; then
        docker exec "$MYSQL_CONTAINER" \
            mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" -N -B \
            -e "SHOW GLOBAL STATUS LIKE 'Com_select'" 2>/dev/null | awk '{print $2}'
    else
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" -N -B \
            -e "SHOW GLOBAL STATUS LIKE 'Com_select'" 2>/dev/null | awk '{print $2}'
    fi
}

# --------------------------------------------------------------- checks ----

require_app() {
    local code deadline
    deadline=$(( $(date +%s) + 20 ))

    # Retried rather than checked once: when these scripts run back to back, the
    # previous one's .env restore may still be bouncing `artisan serve`.
    # 429 also counts as up — the app answered, it just remembers earlier
    # traffic, and raising the limits below clears the way.
    while :; do
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE_URL/slots/availability" 2>/dev/null || echo 000)"
        { [ "$code" = "200" ] || [ "$code" = "429" ]; } && break
        [ "$(date +%s)" -ge "$deadline" ] && break
        sleep 0.5
    done

    if [ "$code" != "200" ] && [ "$code" != "429" ]; then
        printf '%sCannot reach %s/slots/availability (HTTP %s).%s\n' "$RED" "$BASE_URL" "$code" "$OFF" >&2
        printf 'Start the app first, or set BASE_URL.\n' >&2
        exit 1
    fi

    if [ -z "$(db_query 'SELECT 1')" ]; then
        printf '%sCannot query the database.%s\n' "$RED" "$OFF" >&2
        exit 1
    fi
}

# ------------------------------------------------- rate limits (temporary) ----

LIMITS_RELAXED=false

# Load testing an endpoint that is deliberately rate limited measures the rate
# limiter, not the thing under test. The limits are raised for the duration and
# restored by an EXIT trap, so an interrupted run still puts them back.
relax_limits() {
    [ "${KEEP_LIMITS:-false}" = true ] && { warn "Rate limits left as configured (--keep-limits)."; return; }

    if [ -f bootstrap/cache/config.php ]; then
        warn "Cached config found; clearing it so .env changes take effect."
        artisan config:clear >/dev/null 2>&1
    fi

    cp .env "$ENV_BACKUP"
    LIMITS_RELAXED=true

    sed -i -E 's/^RATE_LIMIT_WRITES=.*/RATE_LIMIT_WRITES=1000000,1/' .env
    sed -i -E 's/^RATE_LIMIT_AVAILABILITY=.*/RATE_LIMIT_AVAILABILITY=1000000,1/' .env
    grep -qE '^RATE_LIMIT_WRITES=' .env      || echo 'RATE_LIMIT_WRITES=1000000,1' >> .env
    grep -qE '^RATE_LIMIT_AVAILABILITY=' .env || echo 'RATE_LIMIT_AVAILABILITY=1000000,1' >> .env

    info "Rate limits raised for this run (restored on exit)."

    # `php artisan serve` watches .env by mtime and restarts on any change,
    # dropping every in-flight connection. Editing the file and generating load
    # immediately produces a wall of transport errors that look like
    # application failures but are the dev server bouncing.
    wait_for_app
}

wait_for_app() {
    local deadline=$(( $(date +%s) + 30 )) code

    while [ "$(date +%s)" -lt "$deadline" ]; do
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$BASE_URL/slots/availability" 2>/dev/null || echo 000)"
        if [ "$code" = "200" ] || [ "$code" = "429" ]; then
            sleep 1   # let the remaining workers come back up
            return 0
        fi
        sleep 0.5
    done

    printf '%sApp did not come back after the config change.%s\n' "$RED" "$OFF" >&2
    exit 1
}

# Transport-level failures (curl code 000): the request never got an answer.
# They say something about the stack under load, not about booking logic, so
# they are surfaced separately rather than folded into the assertions.
report_transport_failures() {
    local file="$1" total="$2" dropped
    dropped="$(count_code 000 "$file")"

    if [ "$dropped" -gt 0 ]; then
        warn "$dropped/$total requests got no response (connection dropped)."
        warn "The correctness checks below cover the requests that completed."
        warn "'php artisan serve' is a dev server; use PHP-FPM or Octane for real load."
    fi

    echo "$dropped"
}

restore_limits() {
    if [ "$LIMITS_RELAXED" = true ] && [ -f "$ENV_BACKUP" ]; then
        mv "$ENV_BACKUP" .env
        LIMITS_RELAXED=false
    fi
}

ENV_BACKUP="$PROJECT_DIR/.env.loadtest-backup"

# -------------------------------------------------------------- fixtures ----

FIXTURE_NAME='loadtest'

# Fixtures are tagged by name so cleanup can never touch real slots.
create_slot() {
    local capacity="$1" remaining="${2:-$1}"

    db_query "INSERT INTO slots (name, capacity, remaining, created_at, updated_at)
              VALUES ('$FIXTURE_NAME', $capacity, $remaining, NOW(), NOW());
              SELECT LAST_INSERT_ID();"
}

cleanup_fixtures() {
    db_query "DELETE h FROM holds h JOIN slots s ON s.id = h.slot_id WHERE s.name = '$FIXTURE_NAME';
              DELETE FROM slots WHERE name = '$FIXTURE_NAME';" >/dev/null 2>&1 || true
}

# One trap for everything that must be undone.
teardown() {
    local code=$?
    restore_limits
    cleanup_fixtures
    rm -rf "${WORK_DIR:-}" 2>/dev/null || true
    exit $code
}

WORK_DIR="$(mktemp -d)"
trap teardown EXIT INT TERM

# ------------------------------------------------------------ concurrency ----

# Writes one "<http_code> <hold_id>" line per request to stdout.
# Kept as a generated file rather than an exported function so xargs behaves
# the same across shells.
make_worker() {
    cat > "$WORK_DIR/worker.sh" <<'WORKER'
#!/usr/bin/env bash
# $1 method  $2 url  $3 idempotency key ("-" for a fresh one, "@" for none)
method="$1"; url="$2"; key="${3:--}"

[ "$key" = "-" ] && key="$(cat /proc/sys/kernel/random/uuid)"

if [ "$key" = "@" ]; then
    resp="$(curl -s -m 30 -w '\n%{http_code}' -X "$method" "$url" -H 'Accept: application/json')"
else
    resp="$(curl -s -m 30 -w '\n%{http_code}' -X "$method" "$url" \
        -H "Idempotency-Key: $key" -H 'Accept: application/json')"
fi

code="$(printf '%s' "$resp" | tail -1)"
body="$(printf '%s' "$resp" | sed '$d')"
hold="$(printf '%s' "$body" | grep -o '"hold_id":[0-9]*' | head -1 | cut -d: -f2)"

printf '%s %s\n' "${code:-000}" "${hold:--}"
WORKER
    chmod +x "$WORK_DIR/worker.sh"
}

# fire <concurrency> <method> <url> [key]
fire() {
    local n="$1" method="$2" url="$3" key="${4:--}"
    seq "$n" | xargs -P "$n" -I{} "$WORK_DIR/worker.sh" "$method" "$url" "$key"
}

count_code() { grep -c "^$1 " "$2" || true; }
