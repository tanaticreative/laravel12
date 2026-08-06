#!/usr/bin/env bash
#
# Idempotency under load.
#
# Checks the three properties that matter when duplicate requests arrive at the
# same instant rather than one after another:
#
#   1. One key under concurrency yields exactly one hold.
#   2. Distinct keys are unaffected by each other.
#   3. A key replayed with a different payload is refused, not answered.
#
# Usage:
#   ./scripts/load-idempotency.sh [--concurrency N] [--keep-limits]
#
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

CONCURRENCY=100

while [ $# -gt 0 ]; do
    case "$1" in
        --concurrency) CONCURRENCY="$2"; shift 2 ;;
        --keep-limits) KEEP_LIMITS=true; shift ;;
        -h|--help)     sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

require_app
relax_limits
make_worker
cleanup_fixtures

# --------------------------------------- 1: one key, many simultaneous calls ----

title "One Idempotency-Key, $CONCURRENCY simultaneous requests"

SLOT="$(create_slot $((CONCURRENCY * 2)))"
KEY="$(cat /proc/sys/kernel/random/uuid)"

fire "$CONCURRENCY" POST "$BASE_URL/slots/$SLOT/hold" "$KEY" > "$WORK_DIR/same.txt"

info "Responses: $(awk '{print $1}' "$WORK_DIR/same.txt" | sort | uniq -c | tr '\n' ' ')"

DROPPED="$(report_transport_failures "$WORK_DIR/same.txt" "$CONCURRENCY")"
COMPLETED=$((CONCURRENCY - DROPPED))

CREATED="$(count_code 201 "$WORK_DIR/same.txt")"
REPLAYED="$(count_code 200 "$WORK_DIR/same.txt")"
ROWS="$(db_query "SELECT COUNT(*) FROM holds WHERE idempotency_key = '$KEY'")"
DISTINCT_IDS="$(awk '{print $2}' "$WORK_DIR/same.txt" | grep -v '^-$' | sort -u | wc -l)"

# The invariant is about the database, not about how many answers came back:
# a duplicate that lost its connection still must not have created a row.
check     "rows in database for the key"  1            "$ROWS"
check     "holds against the slot"        1            "$(db_query "SELECT COUNT(*) FROM holds WHERE slot_id = $SLOT")"
check     "distinct hold_ids returned"    1            "$DISTINCT_IDS"
check_max "requests answered 201"         1            "$CREATED"
check     "every answer was 201 or 200"   "$COMPLETED" $((CREATED + REPLAYED))

# ------------------------------------------------ 2: distinct keys are free ----

title "$CONCURRENCY distinct keys, $CONCURRENCY simultaneous requests"

SLOT2="$(create_slot $((CONCURRENCY * 2)))"

fire "$CONCURRENCY" POST "$BASE_URL/slots/$SLOT2/hold" '-' > "$WORK_DIR/distinct.txt"

info "Responses: $(awk '{print $1}' "$WORK_DIR/distinct.txt" | sort | uniq -c | tr '\n' ' ')"

DROPPED2="$(report_transport_failures "$WORK_DIR/distinct.txt" "$CONCURRENCY")"
COMPLETED2=$((CONCURRENCY - DROPPED2))

CREATED2="$(count_code 201 "$WORK_DIR/distinct.txt")"
ROWS2="$(db_query "SELECT COUNT(*) FROM holds WHERE slot_id = $SLOT2")"
DISTINCT2="$(awk '{print $2}' "$WORK_DIR/distinct.txt" | grep -v '^-$' | sort -u | wc -l)"

# Seats are plentiful here, so anything that completed must have been created —
# and each key must have produced its own hold, not shared one.
check "every completed request created a hold" "$COMPLETED2" "$CREATED2"
check "rows in database"                       "$CREATED2"   "$ROWS2"
check "distinct hold_ids"                      "$CREATED2"   "$DISTINCT2"

# --------------------------------------------- 3: same key, other payload ----

title "Replaying a key against a different slot"

SLOT3="$(create_slot 10)"
KEY3="$(cat /proc/sys/kernel/random/uuid)"

FIRST="$("$WORK_DIR/worker.sh" POST "$BASE_URL/slots/$SLOT3/hold" "$KEY3" | awk '{print $1}')"
SLOT4="$(create_slot 10)"
SECOND="$("$WORK_DIR/worker.sh" POST "$BASE_URL/slots/$SLOT4/hold" "$KEY3" | awk '{print $1}')"

check "first request"                    201 "$FIRST"
check "same key, different slot refused" 422 "$SECOND"

summary
