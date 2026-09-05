# My Books

A self-hosted, double-entry accounting and business management platform.
Laravel 13 · React + Inertia · PostgreSQL · Redis · MinIO · Docker.

Every figure in the system traces back to a journal entry that balances.
Invoices, bills, expenses and reconciliations all post through one ledger, so
a report is never an estimate of what the books say.

> **Status:** Phase 0 — Foundation. See [`PROJECT_STATUS.md`](PROJECT_STATUS.md)
> for exactly where things stand and [`ROADMAP.md`](ROADMAP.md) for what comes
> next.

---

## Run it

The only requirement is Docker.

```bash
git clone <repository> my-books
cd my-books
docker compose up -d
docker compose run --rm migrate
docker compose exec app php artisan db:seed --database=pgsql_owner
```

Then open **http://localhost:8080** and sign in as
`owner@my-books.local` / `password`.

| Service       | URL                           |
| ------------- | ----------------------------- |
| Application   | http://localhost:8080         |
| Mail (Mailpit)| http://localhost:8025         |
| Object store  | http://localhost:9001         |
| Queues        | http://localhost:8080/horizon |

First boot takes a few minutes while PHP dependencies install into their
volume. Full details in [`DEPLOYMENT.md`](DEPLOYMENT.md).

## Check it

```bash
docker compose exec app composer check     # Pint · Larastan · Pest
npm run check                              # tsc · ESLint · Prettier
```

## Read first

| Document                                     | What it holds                                     |
| -------------------------------------------- | ------------------------------------------------- |
| [`CLAUDE.md`](CLAUDE.md)                     | How to work on this codebase; the non-negotiables |
| [`ARCHITECTURE.md`](ARCHITECTURE.md)         | How the system is put together                    |
| [`ACCOUNTING_RULES.md`](ACCOUNTING_RULES.md) | Posting rules, invariants, tax and rounding       |
| [`SECURITY.md`](SECURITY.md)                 | Auth, tenancy, RBAC, uploads, secrets             |
| [`DEPLOYMENT.md`](DEPLOYMENT.md)             | Self-hosting, backup, restore, upgrade            |

## Principles, briefly

- **Debits equal credits**, enforced in the domain, in the database, and by a
  verifier — all three.
- **Money never touches a float.** `numeric(19,4)` in PostgreSQL, `Brick\Money`
  in PHP, decimal strings over the wire.
- **The ledger is append-only.** Corrections are reversing entries.
- **Tenant isolation is enforced twice** — an Eloquent scope and PostgreSQL
  row-level security — and neither is trusted alone.
- **The backend is authoritative.** React presents; PHP decides.
- **Everything is self-hosted.** No hosted backend, no telemetry, fonts served
  from your own server.

## Licence

Proprietary. All rights reserved.
