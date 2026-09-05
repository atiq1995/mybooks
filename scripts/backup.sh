#!/usr/bin/env bash
# ===========================================================================
# My Books — backup
#
#   ./scripts/backup.sh [destination-dir]
#
# Captures the three things that must be restored TOGETHER, from the same
# moment: the PostgreSQL ledger, the object store, and the .env that holds the
# APP_KEY without which encrypted values are unrecoverable.
#
# A backup you have never restored is not a backup. Test restore.sh on a copy
# before you need it in anger. See DEPLOYMENT.md §4.
# ===========================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

DEST="${1:-backups}"
STAMP="$(date -u +%Y-%m-%dT%H%M%SZ)"
DIR="${DEST}/my-books-${STAMP}"

COMPOSE=(docker compose)
if [ -f docker-compose.prod.yml ] && [ "${MY_BOOKS_ENV:-}" = "production" ]; then
    COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)
fi

log() { printf '[backup] %s\n' "$*" >&2; }

mkdir -p "${DIR}"

# ---------------------------------------------------------------------------
# 1. Database — a consistent logical dump in custom format (compressed,
#    restorable table-by-table with pg_restore).
# ---------------------------------------------------------------------------
log "database → ${DIR}/database.dump"
"${COMPOSE[@]}" exec -T postgres \
    pg_dump -U my_books -d my_books --format=custom --no-owner --no-acl \
    > "${DIR}/database.dump"

# ---------------------------------------------------------------------------
# 2. Object storage — receipts, attachments, generated PDFs.
# ---------------------------------------------------------------------------
log "object storage → ${DIR}/objects/"
mkdir -p "${DIR}/objects"
"${COMPOSE[@]}" run --rm --no-deps -v "$(pwd)/${DIR}/objects:/backup" --entrypoint sh minio-init -c \
    'mc alias set local http://minio:9000 "${MINIO_ROOT_USER:-my_books}" "${MINIO_ROOT_PASSWORD:-my_books_secret}" >/dev/null &&
     mc mirror --quiet --overwrite local/my-books /backup' 2>&1 | tail -2

# ---------------------------------------------------------------------------
# 3. Environment — the APP_KEY decrypts stored secrets. Kept alongside the
#    dump here for convenience; in production, store it SEPARATELY from the
#    database backup. Someone who holds both holds everything.
# ---------------------------------------------------------------------------
if [ -f .env ]; then
    log "environment → ${DIR}/env (restrict access to this file)"
    cp .env "${DIR}/env"
    chmod 600 "${DIR}/env"
fi

# ---------------------------------------------------------------------------
# 4. Manifest, so a restore knows what it is looking at.
# ---------------------------------------------------------------------------
cat > "${DIR}/MANIFEST" <<EOF
my-books backup
created_at=${STAMP}
git_commit=$(git rev-parse --short HEAD 2>/dev/null || echo unknown)
database=database.dump (pg_dump custom format)
objects=objects/ (MinIO bucket my-books)
env=env (contains APP_KEY — protect)
EOF

log "done: ${DIR}"
du -sh "${DIR}" >&2
