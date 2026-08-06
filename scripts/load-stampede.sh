#!/usr/bin/env bash
#
# Cache stampede under load.
#
# The claim being tested is about cost, not correctness: many simultaneous
# readers hitting a cold or just-expired cache must collapse into a single
# recomputation instead of each running its own query.
#
# Measured as the delta of the server's Com_select counter across the load, so
# nothing else should touch this database while it runs.
#
# Usage:
#   ./scripts/load-stampede.sh [--concurrency N] [--duration S] [--keep-limits]
#
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

CONCURRENCY=100
DURATION=30

while [ $# -gt 0 ]; do
    case "$1" in
        --concurrency) CONCURRENCY="$2"; shift 2 ;;
        --duration)    DURATION="$2"; shift 2 ;;
        --keep-limits) KEEP_LIMITS=true; shift ;;
        -h|--help)     sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

require_app
relax_limits
make_worker

URL="$BASE_URL/slots/availability"

# ---------------------------------------------- phase 1: cold-cache burst ----

title "Phase 1 — $CONCURRENCY simultaneous readers, cold cache"

artisan cache:clear >/dev/null 2>&1
sleep 1

BEFORE="$(db_selects)"
fire "$CONCURRENCY" GET "$URL" '@' > "$WORK_DIR/burst.txt"
AFTER="$(db_selects)"

BURST_SELECTS=$((AFTER - BEFORE))
OK_COUNT="$(count_code 200 "$WORK_DIR/burst.txt")"

info "Responses:  $(awk '{print $1}' "$WORK_DIR/burst.txt" | sort | uniq -c | tr '\n' ' ')"
info "DB SELECTs: $BURST_SELECTS  (without single-flight this approaches $CONCURRENCY)"

DROPPED="$(report_transport_failures "$WORK_DIR/burst.txt" "$CONCURRENCY")"

check     "every completed read served"  $((CONCURRENCY - DROPPED)) "$OK_COUNT"
# One recompute is expected; a couple more only if the burst straddles a TTL.
check_max "recomputes during cold burst"  5             "$BURST_SELECTS"

# ------------------------------------------------ phase 2: sustained load ----

title "Phase 2 — sustained load for ${DURATION}s across $CONCURRENCY workers"
info "TTL is randomised 5-15s, so the cache expires several times under load."

artisan cache:clear >/dev/null 2>&1
sleep 1

BEFORE="$(db_selects)"
END=$(( $(date +%s) + DURATION ))
REQ_FILE="$WORK_DIR/sustained.txt"
: > "$REQ_FILE"

for _ in $(seq "$CONCURRENCY"); do
    (
        while [ "$(date +%s)" -lt "$END" ]; do
            curl -s -o /dev/null -m 30 -w '%{http_code}\n' "$URL" -H 'Accept: application/json'
        done
    ) >> "$REQ_FILE" &
done
wait

AFTER="$(db_selects)"
SUSTAINED_SELECTS=$((AFTER - BEFORE))
TOTAL_REQ="$(wc -l < "$REQ_FILE")"
OK_SUSTAINED="$(grep -c '^200$' "$REQ_FILE" || true)"
BAD_SUSTAINED="$(grep -vcE '^(200|000)$' "$REQ_FILE" || true)"
DROPPED_SUSTAINED="$(grep -c '^000$' "$REQ_FILE" || true)"

[ "$DROPPED_SUSTAINED" -gt 0 ] && warn "$DROPPED_SUSTAINED/$TOTAL_REQ requests got no response (dev server saturation)."

# A recompute cannot happen more often than the shortest TTL, so the ceiling is
# duration/5 plus slack for the cold start and boundary effects.
CEILING=$(( DURATION / 5 + 3 ))

DIVISOR=$(( SUSTAINED_SELECTS > 0 ? SUSTAINED_SELECTS : 1 ))

info "Requests served: $OK_SUSTAINED of $TOTAL_REQ  (~$((TOTAL_REQ / DURATION))/s)"
info "DB SELECTs:      $SUSTAINED_SELECTS"
info "Ratio:           1 query per $((OK_SUSTAINED / DIVISOR)) served requests"

check     "no error responses under load" 0           "$BAD_SUSTAINED"
check_max "recomputes over ${DURATION}s"  "$CEILING"  "$SUSTAINED_SELECTS"

summary
