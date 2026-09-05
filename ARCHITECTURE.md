# ARCHITECTURE.md

How My Books is put together, and why. Decisions with a real alternative are
recorded as ADRs in `docs/adr/`.

---

## 1. Shape

A **modular monolith**. One Laravel application with hard internal
boundaries, not a set of services. Accounting is a domain where a
half-committed distributed transaction is a corrupted ledger; a single
database transaction across invoice, lines, journal and audit is worth far
more here than independent deployability.

The boundaries are drawn so a module *could* be extracted later — each domain
owns its models, exposes Actions rather than internals, and communicates
outward through events. Nothing is designed to make extraction impossible;
nothing is complicated today to make it easy.

---

## 2. Runtime topology

| Process | Image | Role |
|---------|-------|------|
| `app` | `my-books/app` | FrankenPHP — Caddy + PHP 8.4 in one process. HTTP, TLS, static assets, PHP. |
| `horizon` | same | Queue workers, supervised by Laravel Horizon |
| `scheduler` | same | `schedule:work` — recurring invoices, reminders, FX refresh |
| `migrate` | same | Run-once job. Schema changes are never automatic. |
| `vite` | `node:24` | Dev only — HMR for the frontend |
| `postgres` | `postgres:17` | System of record |
| `redis` | `redis:7` | Cache, sessions, queues, rate limits. Never a source of truth. |
| `minio` | `minio/minio` | S3-compatible object storage |
| `mailpit` | `axllent/mailpit` | Dev only — captures every outbound mail |

Every PHP process runs the **same image with the same environment**; only the
command differs. A queue worker therefore cannot drift from the web tier.

Nothing here is a hosted SaaS dependency. `docker compose up` produces a
complete system on a laptop or a small VPS.

---

## 3. Request path

```
Browser
  │
  ▼
Caddy (in the app container)
  │  static asset?  →  served from public/build, immutable cache
  ▼
PHP — Laravel
  │
  ├─ Middleware
  │    EnsureAuthenticated
  │    ResolveOrganization          ← reads the active org from the session
  │    SetPostgresTenantContext     ← SET LOCAL app.organization_id
  │    VerifyOrganizationMembership
  │
  ├─ Route → Controller (thin)
  │            ├─ FormRequest        validation + authorization
  │            ├─ Policy / Gate      may this user do this here?
  │            └─ Action             the actual work, in a transaction
  │                   ├─ Domain models and services
  │                   ├─ PostJournalEntry (for financial documents)
  │                   ├─ AuditLog write, same transaction
  │                   └─ Events dispatched after commit
  │
  └─ Response
       ├─ Inertia::render(...)  → React page with typed props
       └─ JsonResource          → /api/v1
```

Both response types come from the same Actions. The browser has no
privileged path that the API lacks — which is what makes the client portal,
the public API and any future mobile client possible without re-architecture.

---

## 4. Domain layer

```
app/Domain/<Domain>/
├── Actions/       single-purpose units of work — the verbs of the system
├── Models/        Eloquent models
├── Data/          typed DTOs (spatie/laravel-data); also Inertia props
├── Enums/         backed enums for statuses and types
├── Events/        past-tense facts: InvoicePosted, PaymentRecorded
├── Exceptions/    named domain failures
├── Rules/         domain-specific validation
└── Services/      only where an Action would be doing too much
```

Domains: `Accounting`, `Sales`, `Purchases`, `Expenses`, `Banking`,
`Inventory`, `Tax`, `Organizations`, `Contacts`, `Documents`, `Reporting`,
`Automation`.

**Actions** are the core idea. One class, one invokable method, one job:

```php
final readonly class PostInvoice
{
    public function __construct(
        private PostJournalEntry $postJournalEntry,
        private BuildInvoicePosting $buildPosting,
    ) {}

    public function handle(Invoice $invoice, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            // guard → number → totals → journal → audit → event
        });
    }
}
```

Why Actions rather than a service class per domain: a `SalesService` with
forty methods becomes untestable and unreviewable, and every developer adds
one more method to it. An Action has one reason to change and a name that
says what it does.

### Layering rules

- Controllers resolve input, authorize, call **one** Action, and respond.
- Actions may call other Actions. They may not call controllers.
- **No accounting arithmetic outside `app/Domain`.** Not in a controller,
  not in Blade, not in React.
- No repository per model — Eloquent is the data layer. A repository appears
  only where a genuine query abstraction earns its place (the ledger's
  balance queries are the likely first case).
- No interface without a second implementation or a real test seam.

---

## 5. The accounting core

The most important boundary in the system:

**`app/Domain/Accounting/Actions/PostJournalEntry` is the only code in the
application permitted to write `journal_entries` and `journal_lines`.**

Business documents are *sources*. Each produces a `JournalDraft` from a pure
builder, and the ledger posts it:

```
BuildInvoicePosting(invoice, config) → JournalDraft      pure, no I/O
        │
        ▼
PostJournalEntry(draft, source: ['invoice', $id, 'issue'])
        ├─ balanced, to the cent, in both currencies?
        ├─ is the period open — or does the actor hold the override?
        ├─ do all accounts exist, are active, and belong to this org?
        ├─ has this exact source already posted?   (idempotency)
        └─ INSERT, then a deferred trigger re-checks the balance in SQL
```

The builders are pure functions with no database access, which is what makes
the entire posting rule set testable in milliseconds and provably free of
side effects. `ACCOUNTING_RULES.md` is their specification.

---

## 6. Data

### Tenancy — two layers, neither trusted alone

1. **Application.** Organisation-owned models use the `BelongsToOrganization`
   trait, which adds a global Eloquent scope and fills `organization_id` on
   create. Forgetting a `where` is not enough to leak data.

2. **Database.** PostgreSQL row-level security. The application connects as
   `my_books_app`, which **owns nothing** — so RLS applies to it without
   exception. Migrations use a separate `pgsql_owner` connection as the
   schema owner, which bypasses RLS because a migration must.

   `SetPostgresTenantContext` middleware issues `SET LOCAL app.organization_id`
   per request; policies read it through `app_current_organization_id()`.

An application bug is therefore not sufficient to expose one organisation's
books to another. See `docs/adr/0002-multi-tenancy.md`.

### Types

| Kind | Type | Why |
|------|------|-----|
| Money | `numeric(19,4)` | Exact. Never `float`, `double` or `real`. |
| Quantity | `numeric(19,6)` | Fractional units — hours, kilograms, litres |
| Rates | `numeric(19,10)` | FX and tax rates need more precision than money |
| Identifiers | `uuid` v7 | Time-ordered, so index locality is good; non-enumerable, so an id in a URL leaks no volume information |
| Timestamps | `timestamptz` | Always UTC; display converts to the organisation's timezone |

### Immutability

Posted ledger rows are append-only, enforced by trigger. Master data archives
via `archived_at`. Transactions are voided — which posts a reversal — never
deleted.

### Migrations

Forward-only once merged. Laravel migrations for structure; raw SQL inside
them for `CHECK` constraints, triggers, RLS policies and partial indexes,
because those are the parts that make the invariants real.

---

## 7. Frontend

**Inertia**, not a separate SPA with its own API client. The pages are React,
the routing and authorization are Laravel's, and there is no second
authentication system or duplicated validation layer to keep in step.

```
resources/js/
├── Pages/          Inertia pages, mirroring module structure
├── Layouts/        AppLayout (the shell), AuthLayout, PrintLayout
├── DesignSystem/   tokens-driven primitives. No application logic.
├── Components/     composed, app-aware components
├── Hooks/  Types/  Utils/
```

### The contract with PHP

- **Types are generated**, never hand-written:
  `php artisan typescript:transform` turns `Data` classes and enums into
  `resources/js/Types/generated.d.ts`. The two cannot drift.
- **Routes come from Ziggy**: `route('invoices.show', id)`, never a string.
- **The backend is authoritative.** React renders totals; it does not decide
  them. The line-item editor shows an immediate subtotal for responsiveness,
  and the server returns the figures that actually post — so a complex tax
  case can never display one number and record another.

### Page archetypes

Consistency across forty screens is structural, not a matter of discipline.
Four components carry it:

| Archetype | Provides |
|-----------|----------|
| `ListPage` | Title, primary action, search, filters, bulk actions, table, pagination — and four distinct states: loading, no data yet, no results for this filter, error |
| `DetailPage` | Status header, summary, actions, tabs, related documents, activity and audit timeline |
| `FormPage` | Sectioned fields, progressive disclosure, inline validation, sticky footer, unsaved-changes guard |
| `ReportPage` | Date range, comparison period, drill-through, export, print stylesheet |

A new module composes these. It does not invent a fifth shape.

---

## 8. Design system

`resources/js/DesignSystem` owns tokens and primitives and imports nothing
from the application. Every colour, size, radius and shadow is a token in
`resources/css/app.css`; a raw hex or arbitrary pixel value in a component is
a defect.

- **Density is a token**, not a per-table prop. Compact is the default,
  because the primary user is scanning two hundred rows.
- **Money is `tabular-nums`, right-aligned**, and only negatives take colour.
  Colouring every figure green is noise.
- Interaction behaviour is built on accessible primitives; every pixel of
  appearance is ours. Icons are Lucide (ISC). Fonts are self-hosted — a
  privacy-first product does not tell a CDN who is reading their books.

---

## 9. Background work

Redis-backed queues under Horizon.

| Queue | Work | Why not synchronous |
|-------|------|---------------------|
| `default` | notifications, webhooks | not worth blocking a response |
| `documents` | PDF rendering, statement generation | seconds, not milliseconds |
| `imports` | bank statements, contact and item imports | minutes |
| `reports` | large exports | minutes |
| `maintenance` | ledger verification, FX refresh, backups | scheduled |

Queued **only** when the user does not need the result immediately. Posting a
journal is synchronous: the user must know it worked.

---

## 10. Security posture

Detail in `SECURITY.md`. The architectural commitments:

- Authorization is server-side, always — Policies and Gates, never a React
  conditional. The frontend hides what a user cannot do; the backend refuses
  it.
- Sessions are Redis-backed, encrypted, httpOnly. `/api/v1` uses Sanctum
  tokens with scopes.
- Uploads go to object storage under generated keys, are served via
  short-lived presigned URLs, and are never executed.
- Audit rows are written in the same transaction as the change they describe.
- Logs are structured JSON with redaction; a log line never carries a
  password, token, or a customer's balance.

---

## 11. What is deliberately absent

| Not used | Why |
|----------|-----|
| Microservices | A distributed ledger transaction is a corrupted ledger |
| Event sourcing for the ledger | Double-entry *is* the event log; a second one adds risk without adding truth |
| A repository per model | Eloquent is already the data layer |
| SQLite for tests | Constraints, triggers and RLS are the logic under test |
| Client-side accounting | The backend is authoritative |
| Octane worker mode (for now) | Statefulness risk before the app is well understood; a Phase 13 optimisation |
| `gd`, OCR, PDF engines in the base image | Added when the feature that needs them arrives |
