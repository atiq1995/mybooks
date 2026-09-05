#!/usr/bin/env bash
# ===========================================================================
# My Books — container entrypoint
#
# Deliberately does NOT run migrations. Schema changes are a separate,
# explicitly invoked job (`docker compose run --rm migrate`) because an app
# container that migrates on boot will, on the day you scale to two
# replicas, run two migrations at once against one ledger.
# ===========================================================================
set -euo pipefail

log() { printf '[entrypoint] %s\n' "$*" >&2; }

# ---------------------------------------------------------------------------
# Wait for the services this process actually needs.
# ---------------------------------------------------------------------------
wait_for() {
    local name="$1" host="$2" port="$3" tries=60
    log "waiting for ${name} at ${host}:${port}"
    until nc -z "${host}" "${port}" 2>/dev/null; do
        tries=$((tries - 1))
        if [ "${tries}" -le 0 ]; then
            log "ERROR ${name} did not become reachable at ${host}:${port}"
            exit 1
        fi
        sleep 1
    done
    log "${name} is up"
}

if [ -n "${DB_HOST:-}" ]; then
    wait_for postgres "${DB_HOST}" "${DB_PORT:-5432}"
fi
if [ -n "${REDIS_HOST:-}" ]; then
    wait_for redis "${REDIS_HOST}" "${REDIS_PORT:-6379}"
fi

# ---------------------------------------------------------------------------
# Development or production?
#
# Decided by MY_BOOKS_DEV, which docker-compose.yml sets and the production
# overlay does not — NOT by APP_ENV. Laravel's own variables are deliberately
# kept out of the container environment (see docker-compose.yml), so APP_ENV
# is not reliably present here, and guessing "production" when it is absent
# would cache configuration into a development checkout and break the test
# suite's phpunit.xml overrides.
# ---------------------------------------------------------------------------
if [ "${MY_BOOKS_DEV:-0}" = "1" ]; then
    if [ ! -f /app/.env ] && [ -f /app/.env.example ]; then
        log "no .env found — seeding from .env.example"
        cp /app/.env.example /app/.env
    fi

    # Test for the autoloader, not the directory: vendor/ is a named volume,
    # so it always exists — it is just empty on the first run.
    if [ -f /app/composer.json ] && [ ! -f /app/vendor/autoload.php ]; then
        log "vendor/ is empty — running composer install (first run takes a few minutes)"
        composer install --no-interaction --prefer-dist
    fi

    if [ -f /app/artisan ] && ! grep -qE '^APP_KEY=base64:' /app/.env 2>/dev/null; then
        log "generating APP_KEY"
        php artisan key:generate --force
    fi

    # storage/ is a bind mount in dev and can arrive with host ownership.
    mkdir -p /app/storage/framework/{cache,sessions,testing,views} \
             /app/storage/logs \
             /app/bootstrap/cache
    chmod -R 775 /app/storage /app/bootstrap/cache 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# Production caches. Built at boot rather than at image build time because
# they embed environment values that only exist at run time.
#
# Never in development: a cached config silently overrides .env and every
# phpunit.xml <env>, so tests would run against the development database.
# ---------------------------------------------------------------------------
if [ "${MY_BOOKS_DEV:-0}" != "1" ] && [ -f /app/artisan ]; then
    log "caching configuration, routes, events and views"
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
    php artisan view:cache
fi

log "starting: $*"
exec "$@"
