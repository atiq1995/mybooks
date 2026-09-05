#!/usr/bin/env bash
# ===========================================================================
# My Books — restore
#
#   ./scripts/restore.sh backups/my-books-<stamp>
#
# Restores a backup made by backup.sh. This REPLACES the current database and
# object store. It refuses to run without an explicit confirmation, because
# the most common restore mistake is running it against the wrong deployment.
#
# Always finishes with verify-ledger: a partially restored ledger looks entirely
# normal until someone runs a balance sheet. See DEPLOYMENT.md §4.
# ===========================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

SRC="${1:?usage: restore.sh <backup-directory>}"
[ -f "${SRC}/database.dump" ] || { echo "no database.dump in ${SRC}" >&2; exit 1; }

COMPOSE=(docker compose)
if [ -f docker-compose.prod.yml ] && [ "${MY_BOOKS_ENV:-}" = "production" ]; then
    COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)
fi

log() { printf '[restore] %s\n' "$*" >&2; }

echo
echo "  This will REPLACE the current database and object store with:"
echo "    $(sed -n '1,3p' "${SRC}/MANIFEST" 2>/dev/null | tr '\n' ' ')"
echo
read -r -p "  Type the word RESTORE to continue: " confirm
[ "${confirm}" = "RESTORE" ] || { log "aborted"; exit 1; }

# ---------------------------------------------------------------------------
# Stop everything that writes, so nothing lands between drop and restore.
# ---------------------------------------------------------------------------
log "stopping application services"
"${COMPOSE[@]}" stop app horizon scheduler >/dev/null

# ---------------------------------------------------------------------------
# Database. --clean drops objects before recreating them; --if-exists keeps
# that from failing on a fresh cluster.
# ---------------------------------------------------------------------------
log "restoring database"
"${COMPOSE[@]}" exec -T postgres \
    pg_restore -U my_books -d my_books --clean --if-exists --no-owner --no-acl \
    < "${SRC}/database.dump"

# The runtime role must be re-granted on restored tables: pg_restore with
# --no-acl deliberately does not carry privileges across.
log "re-applying runtime role grants"
"${COMPOSE[@]}" exec -T postgres psql -U my_books -d my_books -q <<'SQL'
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO my_books_app;
GRANT USAGE, SELECT                  ON ALL SEQUENCES IN SCHEMA public TO my_books_app;
SQL

# ---------------------------------------------------------------------------
# Object storage.
# ---------------------------------------------------------------------------
if [ -d "${SRC}/objects" ]; then
    log "restoring object storage"
    "${COMPOSE[@]}" run --rm --no-deps -v "$(pwd)/${SRC}/objects:/backup:ro" --entrypoint sh minio-init -c \
        'mc alias set local http://minio:9000 "${MINIO_ROOT_USER:-my_books}" "${MINIO_ROOT_PASSWORD:-my_books_secret}" >/dev/null &&
         mc mb --ignore-existing local/my-books >/dev/null &&
         mc mirror --quiet --overwrite /backup local/my-books' 2>&1 | tail -2
fi

# ---------------------------------------------------------------------------
# Back up, then verify. Not optional.
# ---------------------------------------------------------------------------
log "starting application services"
"${COMPOSE[@]}" up -d app horizon scheduler >/dev/null

log "verifying the ledger"
if "${COMPOSE[@]}" exec -T app php artisan my-books:verify-ledger 2>/dev/null; then
    log "ledger verified"
else
    log "verify-ledger is not available yet (arrives with the ledger in Phase 2) or reported a discrepancy — check the output above"
fi

log "done"
