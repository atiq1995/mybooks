# DEPLOYMENT.md

Running My Books, from a laptop to a production server. The whole product is
self-hostable: no hosted backend, no proprietary SaaS dependency, no phone
home.

---

## 1. Local development

### Requirements

Docker Desktop (or Docker Engine + Compose v2). Nothing else — there is
deliberately no PHP, Composer or Node on the host, because the container is
the only place the dependency set is real.

### Start

```bash
git clone <repository> my-books
cd my-books
docker compose up -d
docker compose run --rm migrate
```

First boot takes a few minutes: it installs PHP dependencies into the
`vendor` volume and generates `.env` and `APP_KEY` automatically.

| Service | URL | Credentials |
|---------|-----|-------------|
| Application | http://localhost:8080 | |
| Vite HMR | http://localhost:5173 | |
| Mailpit | http://localhost:8025 | every outbound mail lands here |
| MinIO console | http://localhost:9001 | `my_books` / `my_books_secret` |
| Horizon | http://localhost:8080/horizon | |
| PostgreSQL | localhost:5432 | `my_books` / `my_books` |

### Everyday commands

```bash
docker compose exec app bash              # php, composer, artisan
docker compose exec app php artisan ...
docker compose run --rm migrate           # apply migrations
docker compose logs -f app
docker compose restart horizon            # after changing job code
docker compose down                       # stop
docker compose down -v                    # stop AND DESTROY all data
```

`down -v` deletes the database volume. There is no confirmation prompt.

### Windows notes

Development happens on Windows; the application runs in Linux containers.
Three things keep that from hurting:

- `.gitattributes` forces LF everywhere, so a shell script never arrives with
  CRLF and fails to execute.
- `vendor/` and `node_modules/` are **named volumes, not bind mounts**. A
  Linux-built dependency tree on an NTFS bind mount is both slow and wrong.
- Vite polls for file changes, because Windows bind mounts do not deliver
  inotify events reliably.

---

## 2. Production

### Requirements

| Resource | Minimum | Comfortable |
|----------|---------|-------------|
| CPU | 2 cores | 4 cores |
| RAM | 4 GB | 8 GB |
| Disk | 40 GB SSD | 100 GB SSD |
| OS | Any Docker host | |

A single organisation with a few users runs comfortably on the minimum. Sizing
is driven by ledger volume and document storage, not user count.

### Before first launch

Work through the checklist in `SECURITY.md` §10. The items that most commonly
get missed:

- Replace **every** default password in the compose environment
- `APP_ENV=production`, `APP_DEBUG=false`
- Generate a fresh `APP_KEY` (`php artisan key:generate`)
- `SESSION_SECURE_COOKIE=true` — requires working TLS
- Do **not** publish PostgreSQL, Redis or the MinIO console to the host

### Launch

```bash
cp .env.example .env          # then edit it properly
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm migrate
```

The production overlay swaps the build target to `prod` (source and compiled
assets baked into the image, opcache with timestamp validation off), removes
the source bind mount, drops the Vite and Mailpit services, and stops
publishing database ports.

### TLS

Caddy is embedded in the application container and provisions certificates
automatically. Point DNS at the host and set:

```
SERVER_NAME=books.example.com
```

Behind an existing reverse proxy or load balancer instead, set
`SERVER_NAME=:80` and configure `TrustProxies` for the correct forwarded
headers — otherwise every request appears to come from the proxy and rate
limiting becomes useless.

---

## 3. Upgrades

Migrations are forward-only. A downgrade means restoring a backup.

```bash
# 1. Back up first. Every time. No exceptions.
./scripts/backup.sh

# 2. Fetch and build
git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml build

# 3. Migrate, then restart
docker compose ... run --rm migrate
docker compose ... up -d

# 4. Verify the ledger survived intact
docker compose exec app php artisan my-books:verify-ledger
```

Read the release notes before upgrading across a major version. A migration
that touches posted data will say so explicitly.

---

## 4. Backup and restore

**A backup you have never restored is not a backup.** Test the restore path
on a copy before you need it in anger.

Three things must be backed up together, from the same moment:

| What | Where | Why |
|------|-------|-----|
| PostgreSQL | `pgdata` volume | The ledger. Everything else is replaceable. |
| Object storage | `miniodata` volume | Receipts and attachments — often legally required |
| `.env` | Host filesystem | `APP_KEY` decrypts stored values; without it, encrypted data is lost |

### Backup

```bash
# Database — a consistent logical dump
docker compose exec -T postgres \
    pg_dump -U my_books -d my_books --format=custom \
    > backups/my-books-$(date +%F).dump

# Object storage
docker compose exec -T minio \
    mc mirror --overwrite local/my-books /backup/my-books
```

Store `APP_KEY` separately from the database dump. Someone who holds both
holds everything.

### Restore

```bash
docker compose up -d postgres
docker compose exec -T postgres \
    pg_restore -U my_books -d my_books --clean --if-exists < backups/<file>.dump

docker compose up -d
docker compose exec app php artisan my-books:verify-ledger    # always
```

`verify-ledger` after a restore is not optional. A partially restored ledger
looks entirely normal until someone runs a balance sheet.

---

## 5. Operations

### Health

| Endpoint | Meaning |
|----------|---------|
| `/up` | The process is alive |
| `/health` | Postgres, Redis and object storage are all reachable |

### Scheduled work

The `scheduler` container runs `schedule:work`. It handles recurring
invoices, payment reminders, FX rate refresh, nightly `verify-ledger`, and
backup triggers. **If this container is not running, recurring invoices
silently stop being generated** — monitor it.

### Queues

Horizon supervises the workers at `/horizon`. Watch for a growing `failed`
count: a failed PDF job is an invoice a customer never received.

```bash
docker compose exec app php artisan horizon:status
docker compose exec app php artisan queue:failed
docker compose exec app php artisan queue:retry all
```

### Scaling

Horizontal scaling works for `app` and `horizon` — both are stateless, with
sessions and queues in Redis.

```bash
docker compose up -d --scale app=3 --scale horizon=2
```

Two constraints. Only one `scheduler` may run, or every scheduled task fires
multiple times. And `migrate` is a one-shot job — never let application
containers migrate on boot, which is why the entrypoint deliberately does
not.

PostgreSQL scales vertically here. If the ledger outgrows one node, that is a
Phase 13 conversation, and the answer is read replicas for reporting rather
than sharding the ledger.

---

## 6. Troubleshooting

| Symptom | Likely cause |
|---------|--------------|
| `app` restarts in a loop | Read the logs — usually a bad `.env` or unreachable database |
| `vendor/autoload.php` not found | First-run install was interrupted: `docker compose exec app composer install` |
| 419 on every form | Session/cookie misconfiguration — check `SESSION_SECURE_COOKIE` against your actual TLS |
| Assets 404 in production | The `assets` build stage did not run: rebuild with the prod overlay |
| Jobs never run | The `horizon` container is down, or Redis is unreachable |
| Recurring invoices missing | The `scheduler` container is down |
| Permission denied on storage | `chown -R app:app storage bootstrap/cache` inside the container |
| Slow queries after growth | Check `log_min_duration_statement` output before adding indexes by guesswork |
