# 0002 — Tenant isolation in two layers

**Status:** Accepted · 2026-09-05

## Context

Multiple organisations share one database. A user of one must never see
another's books. In an accounting product this is the single most important
security property, and "we filter by organisation_id in every query" is a
promise that one forgotten `where` breaks.

## Decision

Two independent layers, neither trusted alone:

1. **Application.** `BelongsToOrganization` adds a global Eloquent scope that
   constrains every query to the active organisation, fills
   `organization_id` on create, and makes it immutable. With no tenant
   context the scope **throws** rather than returning everything.

2. **Database.** PostgreSQL row-level security. The application connects as
   `my_books_app`, a role that **owns nothing** — a table owner bypasses RLS,
   a non-owner cannot. Migrations use a separate owner connection. Middleware
   publishes `app.user_id` and `app.organization_id` per request. No context
   means **no rows**, not all rows.

The organisation id comes from the **session**, never a URL parameter.

## Rejected

- _Schema-per-tenant._ Operationally heavy for a self-hosted product, and
  migrations become N× slower and N× riskier.
- _Database-per-tenant._ Same, worse.
- _Application scope only._ The failure mode of a missed filter is total
  exposure. Not acceptable.
- _RLS only._ Opaque to developers; a missing context looks like "no data"
  rather than a clear error at the call site.

## Consequences

- Seeders, console commands and the ledger verifier run as the owner
  (`--database=pgsql_owner`) or inside `TenantContext::runUnscoped()`.
- Tests connect as the owner for fixtures; RLS is exercised explicitly with
  `SET ROLE my_books_app` in `RowLevelSecurityTest`.
- Every migration that creates an organisation-owned table must add it to
  `SCOPED_TABLES` in the RLS migration.
- Verified 2026-09-05 against the running cluster: no context → 0 rows;
  scoped → own rows only; cross-tenant INSERT refused by PostgreSQL.
