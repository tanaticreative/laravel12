#!/usr/bin/env bash
#
# init.sh — bootstrap this Laravel project using only Docker.
#
# No PHP, Composer, Node or npm needed on the host. Composer runs inside the
# official `composer` image; everything after that runs inside Laravel Sail.
#
# Usage:
#   ./init.sh                 install and start
#   ./init.sh --fresh         wipe vendor + containers + database volumes first
#   ./init.sh --skip-assets   don't install/build frontend assets
#   ./init.sh --no-start      install only, leave containers stopped
#
set -euo pipefail

# The script lives in scripts/, so the project root is one level up. Resolving
# it from BASH_SOURCE rather than the caller's cwd means this works no matter
# where it is invoked from.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR"

COMPOSER_IMAGE="composer:2"

FRESH=false
SKIP_ASSETS=false
NO_START=false

for arg in "$@"; do
    case "$arg" in
        --fresh)       FRESH=true ;;
        --skip-assets) SKIP_ASSETS=true ;;
        --no-start)    NO_START=true ;;
        -h|--help)     awk 'NR>1 { if (!/^#/) exit; sub(/^# ?/, ""); print }' "${BASH_SOURCE[0]}"; exit 0 ;;
        *)             echo "Unknown option: $arg (try --help)" >&2; exit 1 ;;
    esac
done

# Sail's compose file reads these to run the container as you rather than root.
# Without them the container writes root-owned files into your project.
export WWWUSER="${WWWUSER:-$(id -u)}"
export WWWGROUP="${WWWGROUP:-$(id -g)}"

step() { printf '\n\033[1;34m==>\033[0m \033[1m%s\033[0m\n' "$1"; }
info() { printf '    %s\n' "$1"; }
fail() { printf '\n\033[1;31mError:\033[0m %s\n' "$1" >&2; exit 1; }

# Runs composer in a throwaway container, as the current user so that vendor/
# ends up owned by you. COMPOSER_HOME is redirected because the container has
# no home directory for an arbitrary UID.
composer_run() {
    docker run --rm --interactive \
        --user "$WWWUSER:$WWWGROUP" \
        --volume "$PROJECT_DIR:/app" \
        --volume "$PROJECT_DIR/.docker-cache/composer:/tmp/composer" \
        --workdir /app \
        --env COMPOSER_HOME=/tmp/composer \
        "$COMPOSER_IMAGE" "$@"
}

sail() { ./vendor/bin/sail "$@"; }

# ---------------------------------------------------------------- checks ----
step "Checking prerequisites"

command -v docker >/dev/null 2>&1 || fail "Docker is not installed. See https://docs.docker.com/get-docker/"
docker info >/dev/null 2>&1 || fail "Cannot talk to the Docker daemon. Is it running, and is your user in the 'docker' group?"
docker compose version >/dev/null 2>&1 || fail "The Docker Compose plugin is missing. Install 'docker-compose-plugin'."

info "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo '?')"
info "Compose $(docker compose version --short 2>/dev/null || echo '?')"
info "Running containers as UID:GID $WWWUSER:$WWWGROUP"

# ----------------------------------------------------------------- fresh ----
if [ "$FRESH" = true ]; then
    step "Removing existing containers, volumes and dependencies"
    if [ -f vendor/bin/sail ]; then
        sail down -v --remove-orphans 2>/dev/null || true
    else
        docker compose down -v --remove-orphans 2>/dev/null || true
    fi
    rm -rf vendor node_modules
    info "Removed vendor/, node_modules/ and the database volumes"
fi

# ------------------------------------------------------------------- env ----
step "Preparing .env"

if [ -f .env ]; then
    info ".env already exists, leaving it untouched"
else
    [ -f .env.example ] || fail ".env.example is missing, cannot create .env"
    cp .env.example .env
    info "Created .env from .env.example"
fi

# ---------------------------------------------------------- dependencies ----
step "Installing PHP dependencies"

mkdir -p .docker-cache/composer

if [ -d vendor ] && [ -f vendor/autoload.php ]; then
    info "vendor/ is already present, skipping (use --fresh to reinstall)"
else
    # Post-install scripts call artisan, which needs a valid APP_KEY. It may not
    # exist yet on a first run, so defer them until the key is generated.
    composer_run install --no-interaction --prefer-dist --no-scripts
    info "Dependencies installed"
fi

[ -f vendor/bin/sail ] || fail "laravel/sail is not installed. Add it with: ./init.sh --fresh"

# ------------------------------------------------------------------- key ----
step "Generating application key"

if grep -qE '^APP_KEY=base64:' .env; then
    info "APP_KEY is already set"
else
    composer_run php artisan key:generate --ansi --no-interaction
fi

composer_run php artisan package:discover --ansi

# ------------------------------------------------------------- containers ----
if [ "$NO_START" = true ]; then
    step "Done (--no-start given, containers were not started)"
    info "Start them later with: WWWUSER=\$(id -u) WWWGROUP=\$(id -g) ./vendor/bin/sail up -d"
    exit 0
fi

step "Building and starting containers"
info "The first run downloads and builds images — this can take several minutes."

if ! sail up -d --build; then
    fail "Containers failed to start. If a port is already taken, set APP_PORT, FORWARD_DB_PORT
       or FORWARD_MAILPIT_PORT in .env and run this script again."
fi

# ---------------------------------------------------------------- database ----
DB_CONNECTION="$(grep -E '^DB_CONNECTION=' .env | head -1 | cut -d= -f2- | tr -d '"'"'"' ' || true)"

if [ "$DB_CONNECTION" = "mysql" ] || [ "$DB_CONNECTION" = "mariadb" ] || [ "$DB_CONNECTION" = "pgsql" ]; then
    step "Waiting for the database to accept connections"

    # The compose healthcheck has no start_period, so the container can report
    # "unhealthy" while it is still initialising. Poll the app's own connection
    # instead — that is what actually has to work.
    ready=false
    for attempt in $(seq 1 60); do
        if sail artisan db:show >/dev/null 2>&1; then
            ready=true
            info "Database is ready (after ${attempt}s)"
            break
        fi
        sleep 1
    done

    [ "$ready" = true ] || fail "Database did not become ready within 60s. Check: ./vendor/bin/sail logs $DB_CONNECTION"
fi

step "Running migrations"
sail artisan migrate --force

# ------------------------------------------------------------------ assets ----
if [ "$SKIP_ASSETS" = true ]; then
    step "Skipping frontend assets (--skip-assets)"
elif [ -f package.json ]; then
    step "Installing frontend dependencies"
    # Node runs inside the container, so the host needs no npm.
    if [ -f package-lock.json ]; then
        sail npm ci
    else
        sail npm install
    fi
    info "Frontend dependencies installed"
fi

# ----------------------------------------------------------------- summary ----
APP_PORT="$(grep -E '^APP_PORT=' .env | head -1 | cut -d= -f2- || true)"
MAILPIT_PORT="$(grep -E '^FORWARD_MAILPIT_DASHBOARD_PORT=' .env | head -1 | cut -d= -f2- || true)"

step "Ready"
printf '\n'
info "Application    http://localhost${APP_PORT:+:$APP_PORT}"
info "Mailpit inbox  http://localhost:${MAILPIT_PORT:-8025}"
printf '\n'
info "Add this to your shell profile so Sail always runs as you:"
info "    export WWWUSER=\$(id -u) WWWGROUP=\$(id -g)"
info "    export PATH=\"./vendor/bin:\$PATH\""
printf '\n'
info "Common commands:"
info "    ./vendor/bin/sail npm run dev      start Vite for frontend development"
info "    ./vendor/bin/sail artisan tinker   open a REPL"
info "    ./vendor/bin/sail down             stop the containers"
printf '\n'
