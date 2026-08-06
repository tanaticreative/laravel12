#!/usr/bin/env bash
#
# Oversell protection under load.
#
# The seat counter must never go below zero and never be handed out twice, no
# matter how many requests arrive at once — at hold time and at confirm time.
#
# Usage:
#   ./scripts/load-oversell.sh [--concurrency N] [--keep-limits]
#
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

CONCURRENCY=50

while [ $# -gt 0 ]; do
    case "$1" in
        --concurrency) CONCURRENCY="$2"; shift 2 ;;
        --keep-limits) KEEP_LIMITS=true; shift ;;
        -h|--help)     sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

require_app
relax_limits
make_worker
cleanup_fixtures

# --------------------------------------------- contention for the last seat ----

title "$CONCURRENCY simultaneous holds against a single seat"

SLOT="$(create_slot 1)"

fire "$CONCURRENCY" POST "$BASE_URL/slots/$SLOT/hold" '-' > "$WORK_DIR/last-seat.txt"

info "Responses: $(awk '{print $1}' "$WORK_DIR/last-seat.txt" | sort | uniq -c | tr '\n' ' ')"

DROPPED="$(report_transport_failures "$WORK_DIR/last-seat.txt" "$CONCURRENCY")"
COMPLETED=$((CONCURRENCY - DROPPED))

WON="$(count_code 201 "$WORK_DIR/last-seat.txt")"
LOST="$(count_code 409 "$WORK_DIR/last-seat.txt")"

# One seat existed, so exactly one hold may exist — whatever the network did
# to the answers.
check     "holds in database"      1            "$(db_query "SELECT COUNT(*) FROM holds WHERE slot_id = $SLOT")"
check_max "requests that won"      1            "$WON"
check     "every answer was 201 or 409" "$COMPLETED" $((WON + LOST))

# ------------------------------------------- contention at confirmation time ----

SEATS=$((CONCURRENCY / 5))
[ "$SEATS" -lt 1 ] && SEATS=1

title "$CONCURRENCY simultaneous confirmations for $SEATS remaining seats"

SLOT2="$(create_slot "$CONCURRENCY")"

# Holds are seeded directly, because going through the API would cap them at
# the number of free seats and there would be nothing to contend over.
#
# They must carry the actor this script is seen as, or HoldPolicy answers 404
# and the whole phase measures nothing. Rather than guessing the address, ask
# the application: create one hold through the API and copy its actor.
PROBE_SLOT="$(create_slot 1)"
"$WORK_DIR/worker.sh" POST "$BASE_URL/slots/$PROBE_SLOT/hold" '-' >/dev/null
ACTOR="$(db_query "SELECT actor_key FROM holds WHERE slot_id = $PROBE_SLOT LIMIT 1")"

if [ -z "$ACTOR" ]; then
    warn "Could not determine this client's actor key; confirmations would 404."
    exit 1
fi

info "Seeding $CONCURRENCY holds owned by '$ACTOR'"

for _ in $(seq "$CONCURRENCY"); do
    db_query "INSERT INTO holds
                (slot_id, actor_key, idempotency_key, request_hash, status, expires_at, created_at, updated_at)
              VALUES
                ($SLOT2, '$ACTOR', UUID(), REPEAT('0', 64), 'held', NOW() + INTERVAL 5 MINUTE, NOW(), NOW());" >/dev/null
done

# Seats vanished behind the holds' backs; only $SEATS are really left.
db_query "UPDATE slots SET remaining = $SEATS WHERE id = $SLOT2" >/dev/null

db_query "SELECT id FROM holds WHERE slot_id = $SLOT2" > "$WORK_DIR/hold-ids.txt"

xargs -P "$CONCURRENCY" -I{} "$WORK_DIR/worker.sh" POST "$BASE_URL/holds/{}/confirm" '@' \
    < "$WORK_DIR/hold-ids.txt" > "$WORK_DIR/confirms.txt"

info "Responses: $(awk '{print $1}' "$WORK_DIR/confirms.txt" | sort | uniq -c | tr '\n' ' ')"

DROPPED2="$(report_transport_failures "$WORK_DIR/confirms.txt" "$CONCURRENCY")"

CONFIRMED_HTTP="$(count_code 200 "$WORK_DIR/confirms.txt")"
REJECTED_HTTP="$(count_code 409 "$WORK_DIR/confirms.txt")"
CONFIRMED_DB="$(db_query "SELECT COUNT(*) FROM holds WHERE slot_id = $SLOT2 AND status = 'confirmed'")"
REMAINING="$(db_query "SELECT remaining FROM slots WHERE id = $SLOT2")"
OVER_CAPACITY="$(db_query "SELECT COUNT(*) FROM slots WHERE remaining > capacity")"

# Guard against a hollow pass: if every request 404s, "no oversell" is true
# and meaningless. The phase only counts if seats were actually contested.
UNEXPECTED=$(( CONCURRENCY - DROPPED2 - CONFIRMED_HTTP - REJECTED_HTTP ))
check "answers other than 200/409"             0        "$UNEXPECTED"
check "seats actually consumed"                "$SEATS" "$CONFIRMED_DB"

# Conservation is the real statement: every seat is either still free or spent
# on exactly one confirmation. It holds even if answers were lost in transit,
# which counting 200s would not.
check     "seats conserved (confirmed + left)" "$SEATS" $((CONFIRMED_DB + REMAINING))
check_max "confirmations never exceed seats"   "$SEATS" "$CONFIRMED_DB"
check     "database agrees with the answers"   "$CONFIRMED_HTTP" "$CONFIRMED_DB"
check     "slots over capacity"                0        "$OVER_CAPACITY"

summary
