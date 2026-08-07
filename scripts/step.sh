#!/usr/bin/env bash
#
# step.sh — walk the project's build history one step at a time.
#
# The service was built in stages, each ending in a commit. This moves the
# working tree between those checkpoints so the code can be read in the order
# it was written.
#
# Usage:
#   ./scripts/step.sh                  where am I
#   ./scripts/step.sh list             every step, current one marked
#   ./scripts/step.sh next             forward one step
#   ./scripts/step.sh prev             back one step
#   ./scripts/step.sh 2                jump to step 2
#   ./scripts/step.sh first            jump to the first step
#   ./scripts/step.sh final            back to the tip of main
#   ./scripts/step.sh diff             what the current step changed
#
# Flags:
#   --migrate    after switching, rebuild the database to match this step
#                (runs migrate:fresh --seed — DESTROYS the current data)
#   --force      switch even with uncommitted changes (git may refuse anyway)
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR"

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'
BLUE=$'\033[0;34m'; DIM=$'\033[2m'; BOLD=$'\033[1m'; OFF=$'\033[0m'

FINAL_BRANCH="main"

# ---------------------------------------------------------------- steps ----
#
# Checkpoints, not every commit: `git checkout` of a step brings everything
# before it too, so an intermediate commit only earns a slot here if stopping
# at it teaches something. Commit dad43f5 (services) is folded into step 3,
# which is where its exception class lands and the stage becomes coherent.
#
# If history is ever rewritten, these hashes go stale — the script checks them
# on startup and says so rather than checking out something unintended.

STEP_SHA=(
    b1496fe
    8054ea2
    865c686
    dbc49a6
    9812918
    302cd08
)

STEP_NAME=(
    "Skeleton"
    "Data model"
    "HTTP layer"
    "Domain logic"
    "JSON contract"
    "Error masking"
)

STEP_DETAIL=(
    "Laravel 12 + Sail, compose.yaml, .env.example, scripts/init.sh"
    "Enums/HoldStatus, Models/Booking/{Slot,Hold}, both migrations"
    "routes/api.php (apiPrefix ''), HoldController, AvailabilityController, CreateHoldRequest, HoldResource, withoutWrapping()"
    "HoldPolicy, SlotService, AvailabilityCacheService, ApiException, 3 events, LogHoldActivity, ActorKey"
    "ForceJsonResponse, UniformNotFoundResponse, config/booking.php, feature tests"
    "UniformServerErrorResponse, ServerErrorTest, DatabaseSeeder wiring, README"
)

LAST=$(( ${#STEP_SHA[@]} - 1 ))

MIGRATE=false
FORCE=false
COMMAND=""

# ------------------------------------------------------------- plumbing ----

die()  { printf '%s%s%s\n' "$RED" "$1" "$OFF" >&2; exit 1; }
info() { printf '    %s\n' "$1"; }
warn() { printf '    %s! %s%s\n' "$YELLOW" "$1" "$OFF"; }

# Every hash is resolved once, up front. A stale table must fail loudly here
# rather than half-way through a checkout.
verify_steps() {
    local i
    for i in "${!STEP_SHA[@]}"; do
        git rev-parse --verify --quiet "${STEP_SHA[$i]}^{commit}" >/dev/null \
            || die "Step $i points at ${STEP_SHA[$i]}, which is not a commit in this repository.
History was probably rewritten. Update STEP_SHA in $0."
    done
}

# Index of the step HEAD is sitting on, or -1 when it is somewhere else.
current_index() {
    local head i
    head="$(git rev-parse HEAD)"
    for i in "${!STEP_SHA[@]}"; do
        if [ "$head" = "$(git rev-parse "${STEP_SHA[$i]}^{commit}")" ]; then
            echo "$i"; return
        fi
    done
    echo "-1"
}

# Only *tracked* modifications block a switch. Untracked files survive a
# checkout untouched — this script is itself untracked on a first run, and
# refusing to move because of that would be absurd. Where an untracked file
# would be clobbered by a tracked one, git refuses on its own terms and says
# which file, which is a better message than anything guessed here.
require_clean_tree() {
    [ "$FORCE" = true ] && return 0
    git diff-index --quiet HEAD -- 2>/dev/null && return 0

    printf '%sUncommitted changes to tracked files.%s\n' "$RED" "$OFF" >&2
    printf 'Switching steps would carry them along or be refused by git. Either:\n' >&2
    printf '  git stash            park them, restore later with `git stash pop`\n' >&2
    printf '  git commit -am ...   keep them\n' >&2
    printf '  %s --force           switch anyway\n' "$0" >&2
    printf '\n%s\n' "Modified:" >&2
    git diff-index --name-only HEAD -- | sed 's/^/  /' >&2
    exit 1
}

# Rebuilding is opt-in because it drops every table: an earlier step has fewer
# migrations, so its schema is a subset, and stale tables from a later step
# would still be sitting there pretending to belong.
sync_database() {
    [ "$MIGRATE" = false ] && return 0

    printf '\n%s==>%s rebuilding database for this step\n' "$BLUE" "$OFF"

    if [ -x vendor/bin/sail ] && docker compose ps -q mysql >/dev/null 2>&1 \
        && [ -n "$(docker compose ps -q mysql 2>/dev/null)" ]; then
        ./vendor/bin/sail artisan migrate:fresh --seed --force
    else
        php artisan migrate:fresh --seed --force
    fi
}

show_position() {
    local idx="$1" head_short
    head_short="$(git rev-parse --short HEAD)"

    printf '\n'
    if [ "$idx" -lt 0 ]; then
        printf '%sHEAD is at %s — not one of the steps.%s\n' "$YELLOW" "$head_short" "$OFF"
        printf '%s\n' "${DIM}Run \`$0 list\` to see them, or \`$0 final\` to return to $FINAL_BRANCH.${OFF}"
        return
    fi

    printf '%sStep %s/%s — %s%s%s  %s(%s)%s\n' \
        "$BOLD" "$idx" "$LAST" "$GREEN" "${STEP_NAME[$idx]}" "$OFF" "$DIM" "$head_short" "$OFF"
    printf '    %s\n' "${STEP_DETAIL[$idx]}"

    if [ "$idx" -lt "$LAST" ]; then
        printf '\n%s\n' "${DIM}next: $0 next  →  step $((idx + 1)) (${STEP_NAME[$((idx + 1))]})${OFF}"
    else
        printf '\n%s\n' "${DIM}This is the last step. \`$0 final\` reattaches to $FINAL_BRANCH.${OFF}"
    fi
}

goto_step() {
    local idx="$1"

    [ "$idx" -lt 0 ] || [ "$idx" -gt "$LAST" ] \
        && die "No step $idx. Valid range is 0..$LAST — run \`$0 list\`."

    require_clean_tree
    git checkout --quiet "${STEP_SHA[$idx]}"

    show_position "$idx"

    # Detached HEAD is the correct state for reading history, but it silently
    # discards commits made here, so it is called out every time rather than
    # left for the user to notice later.
    warn "Detached HEAD. Commits made now belong to no branch — \`$0 final\` returns to $FINAL_BRANCH."
    [ "$MIGRATE" = false ] && info "${DIM}Database still matches whatever step it was built for; --migrate rebuilds it.${OFF}"

    sync_database
}

cmd_list() {
    local cur i marker
    cur="$(current_index)"

    printf '\n%sBuild steps%s\n\n' "$BOLD" "$OFF"
    for i in "${!STEP_SHA[@]}"; do
        if [ "$i" = "$cur" ]; then
            marker="${GREEN}→${OFF}"
            printf ' %s %s%s%s  %-14s %s%s%s\n' "$marker" "$BOLD" "$i" "$OFF" \
                "${STEP_NAME[$i]}" "$DIM" "${STEP_SHA[$i]}" "$OFF"
        else
            printf '   %s  %-14s %s%s%s\n' "$i" "${STEP_NAME[$i]}" "$DIM" "${STEP_SHA[$i]}" "$OFF"
        fi
        printf '      %s%s%s\n' "$DIM" "${STEP_DETAIL[$i]}" "$OFF"
    done

    [ "$cur" -lt 0 ] && printf '\n%sHEAD is not on any step (%s).%s\n' \
        "$YELLOW" "$(git rev-parse --short HEAD)" "$OFF"
    printf '\n'
}

cmd_diff() {
    local cur
    cur="$(current_index)"
    [ "$cur" -lt 0 ] && die "HEAD is not on a step, so there is no step diff to show."

    if [ "$cur" -eq 0 ]; then
        git show --stat "${STEP_SHA[0]}"
    else
        # Range, not `git show`: a step may fold in intermediate commits, and
        # showing only its tip would hide their files.
        git diff --stat "${STEP_SHA[$((cur - 1))]}..${STEP_SHA[$cur]}"
    fi
}

cmd_final() {
    require_clean_tree
    git checkout --quiet "$FINAL_BRANCH"
    printf '\n%sBack on %s%s at %s.\n' "$GREEN" "$FINAL_BRANCH" "$OFF" "$(git rev-parse --short HEAD)"
    sync_database
}

# Prints the header comment, stopping at the first line that is not a comment.
# A hardcoded line range starts leaking code the moment the header is edited.
usage() {
    awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
}

# ----------------------------------------------------------------- main ----

for arg in "$@"; do
    case "$arg" in
        --migrate) MIGRATE=true ;;
        --force)   FORCE=true ;;
        -h|--help|help) usage; exit 0 ;;
        -*) die "Unknown flag: $arg (try --help)" ;;
        *)  [ -n "$COMMAND" ] && die "Only one command at a time (got '$COMMAND' and '$arg')."
            COMMAND="$arg" ;;
    esac
done

git rev-parse --git-dir >/dev/null 2>&1 || die "Not a git repository."
verify_steps

CURRENT="$(current_index)"

case "${COMMAND:-status}" in
    status)  show_position "$CURRENT" ;;
    list|ls) cmd_list ;;
    diff)    cmd_diff ;;
    final|last|end) cmd_final ;;
    first)   goto_step 0 ;;
    next)
        [ "$CURRENT" -lt 0 ] && die "HEAD is not on a step, so 'next' has no meaning. Use a number or \`$0 first\`."
        [ "$CURRENT" -ge "$LAST" ] && die "Already at the last step ($LAST). \`$0 final\` returns to $FINAL_BRANCH."
        goto_step $((CURRENT + 1)) ;;
    prev|back)
        [ "$CURRENT" -lt 0 ] && die "HEAD is not on a step, so 'prev' has no meaning. Use a number or \`$0 list\`."
        [ "$CURRENT" -le 0 ] && die "Already at the first step (0)."
        goto_step $((CURRENT - 1)) ;;
    ''|*[!0-9]*) die "Unknown command: '$COMMAND' (try --help)" ;;
    *)       goto_step "$COMMAND" ;;
esac
