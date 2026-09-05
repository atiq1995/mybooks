# CLAUDE.md — working on My Books

Read this first, every session. Then `PROJECT_STATUS.md` for where the work
actually stands.

---

## What this is

**My Books** is a self-hosted, double-entry accounting and business
management platform. Laravel 13 backend, React + Inertia frontend,
PostgreSQL, Redis, MinIO, all in Docker.

The UX benchmark is Zoho Books: a user who knows Zoho Books should recognise
the information architecture, terminology and workflows here. The
implementation is entirely our own — our code, our design system, our
branding, our icons. **No Zoho asset, stylesheet, mark or line of code enters
this repository.** Workflow patterns and accounting terminology are
industry-standard and freely usable; visual assets and code are not.

---

## Non-negotiables

These are not style preferences. Breaking one of them is a defect, however
well the code otherwise works.

1. **Debits equal credits.** Every posted journal entry balances to the cent.
   Enforced in the domain action, by a database constraint trigger, and by
   `php artisan my-books:verify-ledger`. All three, always.

2. **Money never touches a float.** Not in PHP, not in PostgreSQL, not in
   JSON, not in TypeScript. `numeric(19,4)` in the database, `Brick\Money`
   in PHP, decimal strings over the wire. A `float` in a money path is a
   rejected change, not a discussion.

3. **The ledger is append-only.** Posted journal entries and lines are never
   updated or deleted. Corrections are reversing entries. A database trigger
   enforces this; do not add an exception.

4. **Only the ledger service writes journals.** Controllers, jobs, listeners
   and React components never touch `journal_entries` or `journal_lines`.
   Documents produce a draft; `PostJournalEntry` posts it.

5. **The backend is authoritative.** Authorization, validation, accounting
   and totals are decided in PHP. TypeScript presents them. A total shown in
   the browser must have come from, or be confirmed by, the server.

6. **Tenant scope is enforced server-side, twice.** A global Eloquent scope,
   and PostgreSQL row-level security underneath it. Never rely on a
   frontend filter or a forgotten `where`.

7. **Financial writes run in a transaction.** Document, lines, totals,
   journal, audit — all of it commits together or none of it does.

8. **AI never posts.** It may suggest, classify, extract, summarise and
   explain. A human with permission accepts the suggestion, and the
   acceptance is what posts.

---

## Session start

```bash
cat PROJECT_STATUS.md          # where the work stands, what is next
git log --oneline -15          # what actually happened recently
git status
docker compose ps              # is the stack up
```

Then continue from the existing state. **Do not assume the repository is
empty**, and do not rebuild something that already exists.

---

## Running things

Everything runs in Docker. There is no PHP or Composer on the host, and that
is deliberate — the container is the only environment where the dependency
set is real.

```bash
docker compose up -d                      # start the stack
docker compose run --rm migrate           # apply migrations (never automatic)
docker compose exec app bash              # shell with php, composer, artisan
docker compose logs -f app                # follow application logs
docker compose down                       # stop
docker compose down -v                    # stop AND destroy data — careful
```

| Service      | URL                     | Notes                          |
| ------------ | ----------------------- | ------------------------------ |
| Application  | http://localhost:8080   | FrankenPHP (Caddy + PHP 8.4)   |
| Vite HMR     | http://localhost:5173   | dev assets                     |
| Mailpit      | http://localhost:8025   | every outbound mail lands here |
| MinIO console| http://localhost:9001   | `my_books` / `my_books_secret` |
| Horizon      | http://localhost:8080/horizon | queue dashboard          |
| PostgreSQL   | localhost:5432          | `my_books` / `my_books`        |

### Dependency changes

Composer resolution **must happen inside the application image**, because the
lock file records platform requirements — the exact PHP patch version and
every installed extension. Resolving on a host with different PHP produces a
lock file that is valid nowhere.

```bash
docker compose exec app composer require vendor/package
```

If `composer.json` is edited by hand, regenerate the lock the same way:

```bash
docker compose exec app composer update --no-install
```

Adding a PHP extension means editing `docker/app/Dockerfile` and rebuilding.

---

## Quality gates

Run before considering anything finished. CI runs the same commands, so a
green local run means a green pipeline.

```bash
docker compose exec app composer check    # pint + phpstan + pest
docker compose exec app composer test:accounting   # ledger invariants
npm run check                             # tsc + eslint + prettier
```

| Command                | What it protects                                  |
| ---------------------- | ------------------------------------------------- |
| `composer lint`        | Code style (Pint, Laravel preset)                  |
| `composer analyse`     | Static analysis (Larastan, max level)              |
| `composer test`        | Full Pest suite, parallel                          |
| `composer test:accounting` | Ledger invariants — serial, asserts global state |
| `composer types`       | PHP type coverage, fails under 95%                 |
| `npm run typecheck`    | TypeScript, strict                                 |
| `npm run lint`         | ESLint including `jsx-a11y`                        |

---

## Where code goes

```
app/
├── Domain/                  business logic, organised by domain
│   └── <Domain>/
│       ├── Actions/         single-purpose, invokable, the unit of work
│       ├── Models/          Eloquent models
│       ├── Data/            typed DTOs (spatie/laravel-data), also Inertia props
│       ├── Enums/           statuses, types, kinds
│       ├── Events/          things that happened, past tense
│       ├── Exceptions/      domain failures with meaningful names
│       ├── Rules/           validation rules specific to the domain
│       └── Services/        only when an Action would be doing too much
│
├── Http/
│   ├── Controllers/         thin. Resolve input, call an Action, return.
│   ├── Requests/            validation and authorization of the request
│   ├── Resources/           API v1 serialisation
│   └── Middleware/
│
├── Jobs/         Listeners/         Policies/         Support/
```

```
resources/js/
├── Pages/           Inertia pages, mirroring the module structure
├── Layouts/         AppLayout (the shell), AuthLayout, PrintLayout
├── DesignSystem/    tokens-driven primitives — no application logic
├── Components/      composed, app-aware components
├── Hooks/  Types/  Utils/
```

### The rules that keep this honest

- **Controllers stay thin.** Resolve, authorize, call one Action, respond. A
  controller with business logic in it is the beginning of the end.
- **Actions do one thing** and are named for it: `PostInvoice`,
  `RecordCustomerPayment`, `ReverseJournalEntry`.
- **No accounting arithmetic outside `app/Domain`.** Not in controllers, not
  in Blade, not in React.
- **No repository per model.** Eloquent is the data layer. Add a repository
  only where a genuine query abstraction earns its place.
- **No interface without a second implementation** or a real test seam.
- **`resources/js/DesignSystem` imports nothing from `Pages` or
  `Components`.** The dependency runs one way.

---

## Frontend contract

Types come from PHP. `spatie/laravel-typescript-transformer` generates
`resources/js/Types/generated.d.ts` from the `Data` classes and enums:

```bash
docker compose exec app php artisan typescript:transform
```

Do not hand-write a TypeScript interface that mirrors a PHP DTO — generate
it, so the two cannot drift.

Routes come from PHP too, via Ziggy: `route('invoices.show', invoice.id)`.
Never hard-code a URL string.

---

## Testing

| Suite        | Location                | Runs against                    |
| ------------ | ----------------------- | ------------------------------- |
| `Unit`       | `tests/Unit`            | nothing — pure functions        |
| `Feature`    | `tests/Feature`         | real PostgreSQL                 |
| `Accounting` | `tests/Accounting`      | real PostgreSQL, serial         |
| `Browser`    | `tests/Browser`         | the running application         |

Feature tests use a real database, never SQLite. Constraints, triggers and
row-level security *are* part of the logic being tested; a test that mocks
them away proves nothing about production.

Every accounting change needs a test that would fail without it. Assert the
resulting journal lines — account, debit, credit — not just that the HTTP
request returned 200.

---

## How to work

Follow the pipeline for each feature, in order:

**Plan → migration → domain → API → UX spec → UI → integration → tests →
visual review → docs → done.**

- Work in phase order (`ROADMAP.md`). Do not start Phase *n+1* while Phase
  *n* is unfinished.
- Update `PROJECT_STATUS.md` as you go. It is how the next session knows
  where things stand.
- Record architectural decisions in `docs/adr/` — what was decided, what was
  rejected, and why.
- **Never report a passing test you did not run.** If something is unverified,
  say so plainly.
- Migrations are forward-only once merged. Fix a mistake with a new migration.

### Before calling any UI finished

- [ ] Loading, empty, no-results, and error states all designed
- [ ] Keyboard path works end to end; focus is always visible
- [ ] 1280px, tablet and 390px all check out
- [ ] Money is right-aligned and tabular; negatives are unmistakable
- [ ] Destructive actions confirm first
- [ ] Design tokens only — no raw hex, no arbitrary pixel values

---

## Reference

| Document              | Contains                                        |
| --------------------- | ----------------------------------------------- |
| `PROJECT_STATUS.md`   | Current phase, what is done, what is next       |
| `ROADMAP.md`          | All phases with exit criteria                   |
| `ARCHITECTURE.md`     | System design and how the pieces fit            |
| `ACCOUNTING_RULES.md` | Posting rules, debit/credit tables, invariants  |
| `SECURITY.md`         | Auth, tenancy, RBAC, secrets, uploads           |
| `DEPLOYMENT.md`       | Self-hosting, backup, restore, upgrade          |
| `docs/adr/`           | Why things are the way they are                 |
