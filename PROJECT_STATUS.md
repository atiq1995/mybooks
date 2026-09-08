# PROJECT_STATUS.md

Where the work stands. Read after `CLAUDE.md`, before doing anything.

**Updated:** 2026-09-09
**Phase:** 5 — Expenses — **complete; every exit criterion met**
**Phase 0:** complete, verified, pushed (`085d102`).
**Phase 1:** complete apart from two items listed under Gaps.
**Phase 2:** complete; every exit criterion met bar opening balances, which
was deferred because it needs contacts and items.
**Phase 3:** complete apart from three items listed at the end of its section.
**Phase 4:** complete; every exit criterion met.

**Gates, as of this update:** 620 tests / 3,293 assertions green — 106 Unit,
304 Feature, 203 Accounting, 7 Browser against a real Chromium; the accounting
suite also re-run serially, as it asserts ledger-wide state. PHPStan level max
clean; Pint clean; `tsc --noEmit` clean; ESLint (incl. `jsx-a11y`) clean;
production asset build succeeds. Every figure in this document was observed,
not assumed.

### Phase 1 (complete)

- [x] The four missing auth pages — reset password, verify email, two-factor
      challenge, confirm password — plus a shared `AuthLayout`. Closes the last
      Phase 0 gap: `/reset-password/...` returned 500 before, 200 now.
- [x] **Permission catalogue** (`app/Domain/Access/Enums/Permission.php`) — 51
      permissions in code, not in a database table, so a typo is a failing test
      rather than a silently ungranted capability.
- [x] **Roles** (`Role.php`) — owner, admin, accountant, bookkeeper, approver,
      viewer. Shaped around separation of duties: a bookkeeper prepares but
      cannot post; an approver authorises but cannot create. No non-admin role
      can both originate and authorise a payment.
- [x] **AccessControl** — role set, plus per-membership grants, minus
      revocations. Fails closed on an unknown role. Memoised per request, never
      cached beyond it, so a revoked capability cannot be served from cache.
- [x] **Gate** — one gate per permission, resolved against the active
      organisation from tenant context (never a caller-supplied one), with
      two-factor enforcement for the 16 consequential permissions.
- [x] **Audit recorder** — writes in the caller's transaction, denormalises the
      actor, redacts secrets, stores money as a decimal string, diffs only what
      changed. Immutability enforced by model and database trigger.
- [x] 28 new tests covering the RBAC matrix and the audit trail.
- [x] **Organisation creation** — action, form request, form. Choosing a
      country moves currency and financial year with it (GB → GBP/April,
      PK → PKR/July, verified in a browser). The irreversible choice — base
      currency — is called out where the decision is made.
- [x] **Setup wizard** — business details with PK-specific NTN/STRN labels,
      a financial-year review, and finish. The chart of accounts step became
      real in Phase 2; taxes remain padlocked until Phase 3.
- [x] Walked end to end in a real browser: create → set up → dashboard.

**Two real bugs found and fixed doing this**, both invisible to the test
suite because it connects as the schema owner and bypasses RLS:

1. `organizations` RLS had no `WITH CHECK`, so PostgreSQL reused `USING` as
   the INSERT check — and a brand-new organisation has no membership yet.
   Creating a set of books was impossible for real traffic. Fixed forward-only
   in `2026_09_05_120000`, with a `SECURITY DEFINER` helper to break the
   resulting policy recursion between organisations and memberships.
2. `TenantContext::set()` updated the application layer but not PostgreSQL, so
   the two isolation layers diverged mid-request and writes were refused by a
   policy for reasons that looked nothing like the cause. The context now
   publishes to the database automatically whenever it changes.

Both now have regression tests that run as the **application role**, so this
class of bug cannot recur silently.

- [x] **Invitations, end to end.** Open registration is off, so an invitation
      is the only route to an account — which means the flow has to work for
      someone with no account, no session and no tenant context. Tokens are
      48 characters, stored only as a SHA-256 hash, single-use, and expiring.
      The accept page commits to one of four situations (register, sign in,
      accept, wrong account) rather than showing a form that may not apply.
- [x] **People screen** — invite, change role inline, revoke, remove. Roles
      offered are only those the actor may actually grant. The last owner
      cannot be demoted or removed, and nobody can remove their own access.
- [x] Verified in a browser with the real queue and real SMTP: invite → email
      arrives via Horizon in 3s → account created → landed on dashboard →
      replayed link correctly refused.

**Three more real bugs found and fixed**, all in the same family — the two
isolation layers disagreeing:

3. **Queued jobs had no tenant context.** `SerializesModels` re-queries each
   model when a job runs, and a worker has no context, so RLS returned zero
   rows and the job died reporting a plainly-existing record as "not found".
   This would have broken *every* future job — invoice PDFs, statements,
   reminders. `QueueTenancy` now stamps the organisation and user onto every
   job payload and restores them around execution. It restores rather than
   clears, because on the `sync` driver a job runs inside its caller and
   blanket-clearing would wipe the dispatching request's own context.
4. **Invitation lookup was invisible to its recipient.** A signed-out visitor
   has no context, so RLS correctly hid the invitation. Rather than punching
   through with the owner connection, possession of the token is now the
   authorisation: the application publishes the token's hash and a policy
   admits exactly that one row. Loading the issuing organisation needed the
   same treatment.
5. **The people screen leaked across organisations.**
   `OrganizationMembership` deliberately has no global scope, because the
   switcher must read memberships across organisations — and RLS permits a
   user to see their own membership everywhere for the same reason. The
   members query and the `{member}` route binding were therefore unscoped.
   The display bug was visible (one owner listed three times); the real
   problem was privilege escalation, since permissions are checked against
   the *active* organisation, so an owner of one could have promoted
   themselves inside another. Now explicitly scoped, with tests for the
   escalation path.

- [x] **Settings screens** — a settings shell split by whose settings they
      are ("You" vs the organisation, because changing something company-wide
      while believing it personal is the classic settings mistake), plus
      Profile, Security and Appearance.
- [x] **Two-factor enrolment** — enable, scan, confirm, recovery codes. The
      screen tells *this* person whether their own role depends on it rather
      than nagging everyone. Password confirmation is collected inline, so a
      half-finished setup is never abandoned to a redirect.
- [x] Container health checks now report the truth (see below).

**Three more bugs found and fixed:**

6. **Two-factor was completely non-functional.** `User` was missing Fortify's
   `TwoFactorAuthenticatable` trait, so `/user/two-factor-qr-code` raised a
   `BadMethodCallException`. Because two-factor is *mandatory* for any role
   that can post, this would have blocked posting outright the moment
   enforcement was switched on in production. `PasskeyAuthenticatable` was
   missing too.
7. **Enrolment would have stranded the user.** Enabling two-factor requires a
   recent password confirmation; as an Inertia visit, Fortify's redirect sent
   the user to a confirm screen and then to the "intended" URL — which was a
   POST. The flow now talks to those endpoints as JSON and collects the
   password inline.
8. **Every PHP container reported unhealthy for ever.** The FrankenPHP base
   image probes Caddy's admin API on :2019, which our Caddyfile deliberately
   disables — so an orchestrator would have restart-looped a healthy app, and
   `depends_on: service_healthy` would hang. The web tier now probes `/up`,
   Horizon reports its own supervisor state, and the scheduler and migrate
   job have no misleading check at all.

Still to do this phase: the organisation settings screen and the browser E2E
suite. Everything else in Phase 1 is done and verified.

---

## Session start checklist

```bash
docker compose ps                       # everything Up (healthy)?
docker compose exec app composer check  # Pint · Larastan · Pest
npm run check                           # tsc · ESLint · Prettier
git status
```

If the stack is not up: `docker compose up -d && docker compose run --rm migrate`.
Dev sign-in: `owner@my-books.local` / `password` (seed with
`php artisan db:seed --database=pgsql_owner`).

---

## Verified — Phase 0

Each line was actually run, in this repository, against the Docker stack.

### Infrastructure

- [x] Laravel **13.30.1**, PHP **8.4.25** (FrankenPHP, Debian trixie), Composer
      resolution inside the runtime image
- [x] `docker compose up -d` → app, horizon, scheduler, postgres 17, redis 7,
      minio, mailpit all **healthy**; `/health` → `{"status":"ok"}`
- [x] Horizon starts and supervises; scheduler runs `schedule:work`
- [x] Two-role PostgreSQL: `my_books` (owner, migrations) and `my_books_app`
      (runtime, **no BYPASSRLS**) — confirmed via `\du`
- [x] Migrations apply as owner; `migrate --database=pgsql_owner`
- [x] Test database `my_books_test` with matching grants
- [x] Production overlay (`docker-compose.prod.yml`) written; `prod` image
      target builds multi-stage with baked assets
- [x] Backup and restore scripts (`scripts/`)

### Tenant isolation — the security property that matters most

- [x] **Application layer:** `BelongsToOrganization` trait + `OrganizationScope`
      throws with no context, fills `organization_id` on create, immutable after
- [x] **Database layer (RLS):** verified live in psql —
      no context → **0 rows**; scoped → own rows only; cross-tenant INSERT
      **refused by PostgreSQL**; audit UPDATE/DELETE refused even for the owner
- [x] Middleware publishes `app.user_id` then `app.organization_id`, clears on exit
- [x] Organisation chosen from **session**, never URL; membership re-verified
      every request

### Money

- [x] `MoneyCast` — numeric(19,4) ↔ `Brick\Money`; **refuses floats**, refuses
      currency mismatch; 4-dp round trip. Unit suite **7/7 green**
- [x] `HASH_DRIVER=argon2id` in `.env.example`; bcrypt rounds 4 in tests

### Quality gates

- [x] **PHPStan level max (Larastan)** — clean, zero errors
- [x] **Pint** — clean
- [x] **TypeScript strict** (`exactOptionalPropertyTypes`, `noUncheckedIndexedAccess`) — clean
- [x] **ESLint** with `jsx-a11y/strict` as errors, `parseFloat` banned — clean
- [x] **Prettier** — clean
- [x] `npm run build` — production assets, self-hosted fonts bundled
- [x] GitHub Actions workflow written (`.github/workflows/ci.yml`) — lint,
      analyse, test with real Postgres + two-role setup, frontend, prod image
      *(not yet executed: repository has no remote)*

### Tests (Pest 5, real PostgreSQL)

- [x] Unit: `MoneyCastTest` — 7 tests
- [x] Feature: `OrganizationScopeTest` (8), `RowLevelSecurityTest` (6),
      `LoginTest` (8), `ShellTest` (11)
      → **see "Last verified run" below**
- [x] Suites configured: Unit · Feature · Accounting · Browser
      (Accounting and Browser hold READMEs until Phase 2/1 deliver content)

### UI

- [x] Design tokens (`resources/css/app.css`): semantic roles, light + dark,
      density, accounting colour, print stylesheet, self-hosted IBM Plex
- [x] Primitives: Button, Input, Badge/StatusBadge, Card, Skeleton,
      TableSkeleton, EmptyState, NoResultsState, ErrorState, Logo
- [x] App shell: dark sidebar (collapsible to icon rail; mobile drawer),
      top bar with organisation switcher, search trigger, theme toggle, user menu
- [x] Command palette (Ctrl/⌘ K) — navigation search, keyboard-first,
      correct listbox/option ARIA
- [x] Dashboard shell — four metric tiles reporting **unknown, not zero**,
      two designed empty states
- [x] Login screen (two-panel, ledger illustration) and Forgot-password
- [x] Module placeholders: every navigation entry routes to a designed
      "arrives in Phase N" page — no dead links
- [x] Flash toasts; theme persisted per user without page reload
- [x] Visually reviewed at 1440 / 900 / 390 in light and dark — passes; one
      bug found and fixed (null `status` rendered an empty alert on login)

---

## Last verified run — 2026-09-05

> If this block is stale, run `composer check` and `npm run check` and trust
> those over this file.

| Check | Result |
|-------|--------|
| `pest` — all suites | **40 passed, 178 assertions, 7.9 s** (Unit 7 · Feature 33) |
| `phpstan analyse` (level max) | **0 errors** |
| `pint --test` | clean |
| `npm run check` (tsc · ESLint · Prettier) | clean |
| `npm run build` | ✓ — 316 kB JS gzipped to 100 kB, fonts bundled |
| `docker compose ps` | app · horizon · scheduler · postgres · redis · minio · mailpit — all healthy |
| `php artisan my-books:health` | database · redis · storage — OK |
| Visual review (Playwright, built assets) | 10 screens at 1440 / 900 / 390, light + dark — **pass**, no page errors |

Screens reviewed: login (desktop, mobile), dashboard (desktop, tablet, mobile,
dark), command palette, organisation switcher, module placeholder, mobile
navigation drawer.

---

## Known gaps — honest list

Things a stranger clicking around could hit. None blocks Phase 1 from
starting; all are listed so nobody is surprised.

| Gap | Impact | Resolve in |
|-----|--------|------------|
| Inertia pages missing for Fortify routes: `Auth/ResetPassword`, `Auth/VerifyEmail`, `Auth/TwoFactorChallenge`, `Auth/ConfirmPassword` | Visiting those routes errors. No seeded user needs them today. | **First task of Phase 1** |
| MFA "mandatory for posting roles" (SECURITY.md §2) | Documented intent; middleware not yet written | Phase 1 |
| Roles/permissions catalogue (`config/permissions.php`) | Referenced in docs; not yet created — no Policies exist yet | Phase 1 |
| Organisation creation UI (`/organizations/create`) | Link in switcher goes to placeholder | Phase 1 onboarding |
| Browser (E2E) suite | Directory + README only; Pest browser plugin installed, Playwright not wired into the app image | Phase 1 |
| Component gallery page | Primitives exist; no in-app gallery yet | Phase 1 |
| `my-books:verify-ledger` command | Referenced by Dockerfile HEALTHCHECK docs and restore.sh; does not exist yet | Phase 2 (with the ledger) |
| API `/api/v1` | Sanctum installed, no routes. Still not started — the screens have come first through Phases 3 and 4, and the API should serialise a settled domain rather than a moving one. Note that Phase 1's exit criterion mentions `/api/v1` isolation, which cannot be tested until routes exist | Phase 5 |
| Git | Committed and pushed to `origin/main` through Phase 3 | — |

Resolved during the session and worth knowing about: the Feature suite took
~190 s on the Windows bind mount until opcache CLI was enabled with
`revalidate_freq = 2` and a realpath cache — now 7.9 s for the whole run.

---

## Decisions made this session

Recorded fully in `docs/adr/`.

- Stack override accepted: **Laravel 13 + Inertia/React**, not Node (ADR 0001)
- **Pakistan first** — GST + WHT, PKR, July fiscal year (owner's choice)
- **Multi-tenant from the start**, two isolation layers (ADR 0002)
- **FrankenPHP classic mode**, Debian base (ADR 0003)
- **numeric(19,4) + Brick\Money**, floats prohibited (ADR 0004)
- Laravel config lives in `.env`, **not** Compose `environment:` — Compose
  env becomes `$_SERVER` and silently overrides phpunit.xml
- Entrypoint decides dev/prod by `MY_BOOKS_DEV`, not `APP_ENV`
- Tests connect as schema owner; RLS tested explicitly via `SET ROLE`
- Factories resolved by class basename (`Factory::guessFactoryNamesUsing`)
- Open registration **off**; access is by invitation (Phase 1)

---

## Phase 2 — Accounting core

### Schema (all four migrations applied)

- `accounts` — five root types, `normal_balance` stored per account so a
  contra account can invert its type. Self-referencing parent FK added
  *after* the table exists; declared inside `Schema::create` it runs before
  the primary key and PostgreSQL rejects it.
- `fiscal_years` / `fiscal_periods` — a `btree_gist` EXCLUDE constraint makes
  overlapping periods impossible, so a posting date can never belong to two.
- `journal_entries` / `journal_lines` — the ledger. Balance enforced by a
  `DEFERRABLE INITIALLY DEFERRED` constraint trigger (lines arrive one at a
  time, so the entry is transiently unbalanced mid-transaction); append-only
  enforced by a `BEFORE UPDATE OR DELETE` trigger that permits exactly one
  edit — marking an entry reversed.
- `document_sequences` / `exchange_rates` — numbering is a locked row, not a
  PostgreSQL sequence: sequences are non-transactional and leave a gap on
  rollback, which many tax authorities read as a deleted invoice.

### Domain

- `JournalDraft` / `JournalLineDraft` — pure, no database. Every posting rule
  in the application is therefore assertable in microseconds.
- `PostJournalEntry` — the only code permitted to write the ledger. Balance →
  base currency → idempotency → period → accounts, then entry, lines and
  audit in one transaction.
- `ReverseJournalEntry` — mirror image, dated today by default, base amounts
  carried over verbatim so the pair nets to exactly zero.
- `CreateChartOfAccounts`, `CreateFiscalYear`, `PrepareLedger` — all
  idempotent, so a resumed or double-submitted wizard cannot produce a second
  chart or a duplicate year.
- `DocumentNumberGenerator` — `SELECT … FOR UPDATE`, gap-free.
- `my-books:verify-ledger` — six checks re-derived from raw lines. Now runs
  nightly at 02:15 via `routes/console.php`, `--json`, one server only.

### Screens

Chart of Accounts (tree, live balances, create/edit/archive/restore),
Manual Journals (list, filters, create with running totals, detail, reverse),
General Ledger (opening balance, movements, running balance, closing),
Trial Balance (net position per account, verdict stated outright),
Fiscal Periods (open/close/reopen/lock, open a year, close a year),
Currencies (rate store plus a previewed period-end revaluation).

`/accounting` and `/settings` now redirect to their first real screen instead
of a placeholder. Only `/accounting/opening-balances` is still a placeholder —
it waits on Phase 3, since most opening balances are unpaid invoices and bills
rather than plain journal lines.

Onboarding gained a **chart of accounts** step, and `POST /onboarding/complete`
now refuses while the ledger is missing. An organisation marked ready that
refuses every posting is worse than one still visibly in setup: the error
would otherwise surface later, on somebody's first invoice.

### Four bugs the tests could not have found, because the code had never run

1. `JournalLineDraft` declared both a static `debit()` constructor and an
   instance `debit()` accessor. PHP refuses that outright — the entire
   accounting domain was unloadable. Accessors renamed to `*Value()`.
2. `JournalDraft` used `$this` inside two `static fn` closures.
3. brick/math 0.14 turned `RoundingMode` into an enum, so every
   `RoundingMode::HALF_UP` was an undefined constant. Now `HalfUp`.
4. `DB::statement` runs a *prepared* statement and accepts exactly one
   command; three migrations passed it multi-command SQL. Now `DB::unprepared`.

### One real defect found by writing the tests

`VerifyLedgerCommand` scoped every check by row-level security alone. It is
the one command likely to be run by a role that *bypasses* RLS — an operator
investigating a restore, a nightly job on the owner connection — so
`--organization` was silently ignored and findings were attributed to whichever
organisation the loop happened to be on. Every check now names the
organisation explicitly.

### Tests added (124 domain + 49 HTTP + 8 under the runtime DB role)

- `tests/Unit/Accounting/JournalDraftTest` — I1–I3 on pure drafts
- `tests/Accounting/PostJournalEntryTest` — every refusal asserted twice, once
  in the domain and once in raw SQL against the constraints and triggers
- `tests/Accounting/ReverseJournalEntryTest` — exactness, account by account
- `tests/Accounting/DocumentNumberGeneratorTest` — gap-free, including that a
  rolled-back transaction consumes no number
- `tests/Accounting/VerifyLedgerCommandTest` — corrupts the ledger with
  triggers disabled, the way a hand-written SQL fix would, and asserts the
  command notices and exits non-zero
- `tests/Accounting/LedgerSetupTest` — chart and year, idempotence, no overlaps
- `tests/Feature/Tenancy/LedgerRowLevelSecurityTest` — the ledger driven as
  `my_books_app`, so the two isolation layers are proven to agree
- `tests/Feature/Accounting/AccountingScreensTest` — authorisation asserted
  per **role** rather than per permission (a permission list that looks right
  while a role composes it wrongly would pass a permission-level test), plus
  cross-tenant 404s through the web routes

### Phase 2 exit criteria

- [x] Trial balance balances, and says so plainly when it does not
- [x] Unbalanced postings rejected at all three layers
- [x] Reversals restore balances exactly
- [x] Closed periods refuse postings without the override; locked refuse all
- [x] `verify-ledger` catches a deliberately corrupted balance
- [x] Gap-free numbering under rollback
- [ ] Opening balances (deferred to Phase 3 — needs contacts and items)
- [x] Multi-currency: rate store, "latest on or before" lookup, period-end
      revaluation with next-day reversal, FX gain/loss posting
- [x] Year-end close — profit or loss to retained earnings, periods closed
      (not locked), reversible like any other entry
- [x] Committed browser suite — 7 journeys through a real Chromium

---

## Phase 3 — Sales

### Schema

- `taxes` and `tax_components` — a tax is a named container, its rates live in
  dated components. A rate change is a new component, never an edit: an
  invoice filed under 17% must still read as 17% after the rate becomes 18%.
- `contacts` — customers and vendors in one table with a `kind`, because in
  practice the same company is often both.
- `items` — goods and services, with the revenue and expense account each
  posts to. `is_tracked` exists but inventory movement does not (Phase 8).
- `sales_documents` and `sales_document_lines` — one table for estimates,
  sales orders, invoices and credit notes. They share a lifecycle, a numbering
  scheme and a line editor; only two of them post.
- `sales_document_line_taxes` — the per-line, per-component breakdown, stored
  rather than recomputed, so a filed return stays reproducible.
- `payments` and `payment_allocations` — money received, and what it settled.
  A receipt can settle several invoices, part of one, or nothing at all.

The database refuses what §6 forbids, not just the domain:

```sql
ADD CONSTRAINT sales_documents_commitments_never_post
    CHECK (journal_entry_id IS NULL OR type IN ('invoice','credit_note'));
```

An estimate cannot acquire a journal entry even by direct SQL.

### Domain

- `TaxCalculator` — the pure engine for §5's order of operations: line
  discount, then document discount apportioned by net, then tax, compound
  components stacking on the running total. Works at 12 decimal places
  internally and reports at 4.
- `SaveSalesDocument`, `IssueSalesDocument`, `VoidSalesDocument`,
  `ConvertSalesDocument` — the document lifecycle. Issuing an invoice or a
  credit note builds a draft and hands it to `PostJournalEntry`; issuing an
  estimate or a sales order posts nothing.
- `RecordCustomerPayment` — allocation, withholding, advances, and realised FX
  as the balancing plug.
- Settlement entries post in **base** currency, with the foreign amount and
  rate in the memo. The realised gain exists only in base currency, so an
  entry in the transaction currency would need a line worth zero dollars and
  a non-zero number of rupees — which invariant I3 refuses, correctly.

### Screens

Ten pages: the document list, editor and view for all four types; customers
list and statement; items; payments list and entry; receivables ageing; and
tax rates under settings.

- The ageing report states whether it reconciles to the receivables control
  account, in words, at the top. An ageing report that quietly disagrees with
  the ledger is worse than no report: somebody chases the wrong customer while
  the real discrepancy stays hidden.
- Overdue is a predicate, never a stored status. It changes at midnight
  without anything happening to the document, so a column would need a nightly
  job to stay true — and a job that can fail is worse than a derived value
  that cannot.

### Defects found and fixed by writing the tests

Two in Phase 2's ledger, found by Phase 3's lifecycle tests:

1. **`Account::balance()` filtered on `status = 'posted'`**, which excludes a
   reversed original while still counting its reversal. Every balance was
   wrong by the value of anything voided, in the opposite direction. Same bug
   in `ChartOfAccountsController`.
2. **`CloseFiscalYear`'s balancing line was inverted** — the comment described
   debit-on-loss, the code credited.

And in Phase 3's own code:

3. **Document-discount apportionment lost its remainder.** Shares were
   computed at working scale, so the remainder landed in the 12th decimal and
   vanished on rounding: 100 across three lines gave 99.9999. Shares are now
   rounded to money scale before the remainder is computed.
4. **A bookkeeper could post revenue** by pressing Issue. `issue()` asked only
   for `sales.send`, which the role has — so the one role whose entire
   definition is "prepares documents, cannot post" could post. Issuing a type
   that posts now requires `accounting.post` as well, and voiding one requires
   `accounting.reverse`. The buttons follow the same rule, so a control that
   would only ever be refused is absent rather than present.
5. **The list header and its own filter used different clocks.** The overdue
   summary read PostgreSQL's `CURRENT_DATE` while the filter beneath it asked
   PHP, so the header could say nothing was overdue while the rows below
   listed an overdue invoice. The date is now bound from PHP in both.
6. **`/sales/widgets` answered 200**, announcing that Recurring Invoices were
   arriving in Phase 4. The placeholder matched any segment under a known
   module, so every typo and every stale link invented a roadmap entry. It now
   has an allowlist of sections it may make a promise about.
7. **Two test files each declared a global `const AR`.** A top-level `const` in
   a Pest file is global to the process, so the suite passed or failed
   depending on how the parallel runner distributed files — which changed the
   moment a new test file existed.
8. **The parallel worker databases had no grants for the runtime role.**
   `my_books_test` is set up by the container's init script, but Pest creates
   `my_books_test_test_1..12` from template1, which carries neither the grants
   nor the default privileges. Every test that does `SET ROLE my_books_app` to
   observe row-level security died with "permission denied" instead of testing
   anything — and a permission error looks enough like an RLS refusal to be
   mistaken for one. `AppServiceProvider` now probes and repairs this per
   worker, so a fresh CI machine and a developer's machine with leftover
   databases behave the same.

### Tests added

- 54 unit tests over the tax engine and the posting rules — each of §4's
  worked examples asserted line by line, plus the places where a plausible
  alternative would also balance but destroy information.
- 33 accounting tests over the lifecycle: estimate → sales order → invoice →
  payment, asserted against a real database, with `verify-ledger` clean
  afterwards.
- 35 HTTP tests over the screens (`tests/Feature/Sales/SalesScreensTest.php`).
  These cover what the domain tests cannot reach: that every write is refused
  without its own permission, that a URL naming another organisation's record
  produces a 404 rather than that record, and that the four document types
  share one set of routes correctly. Authorisation is asserted per **role**,
  because roles are what people are actually given.

The clock is frozen in the screen tests. Overdue, the ageing buckets and the
receivables as-at date are all derived from today, so a test on the real date
asserts something different every day it runs.

### Phase 3 exit criteria

- [x] Estimate → sales order → invoice → payment, each posting correctly
- [x] Tax computed per §5's order, including compound components and
      tax-inclusive pricing
- [x] Withholding deducted by a customer, posted to WHT receivable rather
      than written off — asserted alongside realised FX on the same receipt.
      Filer and non-filer rates exist as jurisdiction defaults; withholding in
      the other direction waits on purchases in Phase 4
- [x] Credit notes, and voiding by reversal rather than deletion
- [x] Ageing report reconciles to the receivables control account, and says so
- [x] Gap-free numbering per document type
- [x] Cross-tenant access refused at the HTTP layer, per role
- [ ] Recurring invoices — deferred to Phase 4; needs a template model and the
      scheduler, which is its own piece of work rather than a variation on an
      invoice
- [ ] Invoice PDF pipeline — deferred; needs the document/attachment store
- [ ] Opening balances — inherited from Phase 2 and still open. Contacts and
      items now exist, so nothing blocks it

---

## Phase 4 — Purchases

### Schema

Separate tables from sales, not one `documents` table with a direction. The
two sides look alike on screen and differ underneath: a sales line names the
revenue account it credits, a purchase line names the expense **or asset**
account it debits and may capitalise its tax instead of claiming it. Merging
them would mean a nullable column per difference, and the first purchase-only
column would weaken a sales-only guarantee.

- `purchase_documents`, `purchase_document_lines`,
  `purchase_document_line_taxes` — mirroring the sales trio, plus
  `vendor_reference` (their number, not ours), `tax_claimable_total`, and
  `tax_is_claimable` per line.
- `purchase_payment_allocations` — `payments` already carried a `direction`,
  so it serves both sides unchanged; only the allocation table is new.

Two constraints carry rules the code must not be able to lose:

```sql
ADD CONSTRAINT purchase_documents_commitments_never_post
    CHECK (journal_entry_id IS NULL OR type IN ('bill','vendor_credit')),
-- Approval is what posts a bill, so the two facts travel together.
ADD CONSTRAINT purchase_documents_approval_matches_posting
    CHECK (type <> 'bill' OR (approved_at IS NULL) = (journal_entry_id IS NULL));
```

### Domain

- `BillPosting` (§4.6), `VendorCreditPosting`, `VendorPaymentPosting` (§4.7 and
  §4.11) — pure, so every figure is asserted line by line without a database.
- `SavePurchaseDocument`, `ApprovePurchaseDocument`, `VoidPurchaseDocument`,
  `ConvertPurchaseDocument`, `RecordVendorPayment`.
- A bill posts on **approval**, not on entry. That is §6's rule and the
  substantive difference from sales: an invoice is issued by whoever wrote it,
  so writing and issuing are one decision; a bill arrives from outside, and
  somebody has to agree we owe it before the liability is ours to recognise.

Three places where the obvious alternative also balances and is wrong:

1. **Non-claimable input tax is capitalised into the cost**, not booked to GST
   Input Receivable. Booking it as a receivable overstates assets by its value
   and understates the cost of the purchase by the same amount — and nothing
   would ever flag the receivable that can never be recovered. Decided per
   line, because claimability is a fact about what was bought.
2. **A purchase discount is netted into the cost**, which is the opposite of
   the sales side's grossed-up contra-revenue. "What did we sell and what did
   we give away" cannot be recovered from a net figure, so sales keeps both;
   the cost of an asset simply *is* what was paid for it, and grossing it up
   would state an inventory value the business never paid.
3. **A vendor credit credits the account the bill debited** — again the
   opposite of the sales side, where a credit note debits Sales Returns rather
   than reversing revenue. Gross sales is a headline that must not fall when
   goods come back; an expense account has no headline, only the question
   "what did the period cost", and a purchase returned cost nothing. Where the
   goods went to stock it matters more than presentation: only crediting
   inventory brings the stock value back down.

### Screens

Six pages: the document list, editor and view shared by purchase orders, bills
and vendor credits; payments made; payments entry; and the payables ageing
report. Vendors are the contacts screen filtered by kind rather than a second
directory of people, and the vendor statement sits on the contact page beside
the customer one.

- **Payables leads with what falls due soonest**, where receivables leads with
  the oldest money. Different question: the receivables reader is deciding who
  to chase, and the oldest debt is the least collectable; the payables reader
  is deciding what to pay this week, and a list headed by a year-old disputed
  invoice would bury the bill due on Friday.
- Two figures sit above the ageing table for the same reason — due within
  seven days, and already late. Neither is visible in the buckets, where "not
  yet due" mixes tomorrow with two months away.
- The list's third summary figure is **awaiting approval**, which has no sales
  equivalent and is the one worth saying out loud: that money is owed and the
  books do not know about it yet.
- A contact who is both a customer and a vendor shows **both balances, never
  netted**. Netting them would hide a receivable behind a payable and leave
  neither collectable nor payable on its own.

### Defects found by writing the tests

Two in code that predates this phase, both found by the purchase tests:

1. **`Tax::componentsOn()` could return two versions of the same component.**
   A rate change is a new row at the same sequence with a later
   `effective_from`, and whoever writes it is supposed to close the old one.
   The date-window filter trusted that: an unclosed old version matched too,
   both were handed to the calculator, and the tax was charged **twice**.
   Nobody would notice until a return was filed at 38%. The latest applicable
   version per sequence now wins, so an unclosed row is untidy rather than a
   double charge.
2. **`NormalBalance::signedBalance()` returned an unscaled `0`** for an
   account with no movement, while a used account returned `0.0000` — so two
   equal balances compared unequal as strings, which is the only safe way to
   compare money. Always at the money scale now.

### Tests added

- 19 unit tests over the three purchase posting rules — §4.6's and §4.7's
  worked examples to the cent, plus each place where the plausible alternative
  balances but states something false.
- 31 accounting tests over the lifecycle: purchase order → bill → payment
  against a real database, with `verify-ledger` clean afterwards.
- 38 HTTP tests over the screens
  (`tests/Feature/Purchases/PurchaseScreensTest.php`), asserted per role.

One test-infrastructure fix: `entryLines()` moved into `tests/Pest.php`. Both
lifecycle suites assert against it, and a helper declared at the top level of
a Pest file is global to the process — so the second suite to want it either
could not see it or collided with it, depending on how the parallel runner
distributed files. The same trap as the `const AR` collision in Phase 3.

### Phase 4 exit criteria

- [x] Purchase order → bill → payment posts correctly at every step
- [x] Payables age accurately, and the report says whether it reconciles to
      the AP control account
- [x] Withholding recorded as a liability, with the vendor settled in full,
      and reconciling — asserted through the ledger, not just the document
- [x] Non-claimable input tax capitalised per §4.6, per line
- [x] Vendor credits, and voiding by reversal rather than deletion
- [x] Vendor statements, on the contact page, never netted against the
      customer side
- [x] Duplicate-bill guard on the vendor's own reference, naming the bill it
      collides with
- [x] Separation of duties: a bookkeeper prepares and cannot approve; an
      approver approves and cannot create

---

## Phase 5 — Expenses

### Schema

- `attachments` — generic from the start rather than an `expense_receipts`
  table that would be copied for bills, then journals, then contacts. The row
  is the index; the bytes live in object storage. It carries the original
  name, size, type and a **SHA-256 checksum** — the checksum is what makes
  "we already have this receipt" answerable, which matters because a
  duplicate receipt is usually a duplicate claim.
- `expenses`, `expense_lines`, `expense_line_taxes` — its own table rather
  than a fourth purchase document type, because an expense is usually paid at
  the moment it is recorded (no payable to age), and where it is not the money
  is owed to a PERSON rather than a vendor.
- `mileage_rates` — dated, like a tax rate. The rate is copied onto the claim,
  so the table is only where a default comes from.
- A new system account: **2150 Employee Reimbursements**, with a forward-only
  data migration giving it to every existing organisation. Its own role rather
  than accounts payable — an employee is not a vendor, and a payables ageing
  full of staff claims would make the vendor balances unreadable.

Three constraints carry rules the code must not be able to lose:

```sql
-- A company-paid expense names the account it came out of; a reimbursable
-- one must not, because it did not come out of one.
CHECK ((payment_mode = 'company') = (paid_through_account_id IS NOT NULL)),
-- Approval is what posts, so the two facts travel together.
CHECK ((approved_at IS NULL) = (journal_entry_id IS NULL)),
-- A rejection has to say why: without a reason it is a dead end for
-- whoever submitted it.
CHECK (status <> 'rejected' OR btrim(coalesce(rejection_reason, '')) <> '')
```

### Domain

- `ExpensePosting` (§4.8) — pure. One rule with a switched credit rather than
  two rules: everything above the line is identical, and the credit varies by
  the single fact of whose money was spent.
- `SaveExpense`, `SubmitExpense`, `ApproveExpense`, `RejectExpense`,
  `VoidExpense`, `RebillExpenses`, `StoreAttachment`.
- **The approver cannot be the submitter.** Enforced in the domain, not only
  the controller, because an import or an API call has to hit it too. Without
  it the workflow is two clicks by the same person — a formality that makes
  the books look reviewed when they are not. Self-approval is permitted only
  for a single-member organisation, which has nobody to ask, and the audit row
  records that it happened either way.
- **Mileage reuses quantity × price.** A distance at a rate per kilometre is
  the same multiplication as a quantity at a price, so the tax engine needs no
  special case. The rate is resolved as at the expense's own date and copied
  onto the line: a rate raised in October must not restate September.
- **Non-claimable input tax is capitalised** — §4.6 again, and it bites
  hardest here, because expenses are where blocked input tax actually turns
  up: entertainment, staff welfare, a car.
- **Receipts stream through the application**, never from a storage URL. A
  presigned URL is a financial record that leaks with no audit trail, no
  permission check and no expiry anybody can rely on. The type is read from
  the file's contents rather than the browser's claim, against an allowlist.

### Screens

Four pages: the expense list, the form, the detail view with its receipts, and
mileage rates under settings.

- The list leads with the two questions people arrive with — what needs
  approving, what needs billing on — each a figure that is also a filter. The
  third is **what we owe our own people**, a number a payroll run needs and
  nothing else in the product shows.
- A missing receipt is called out per row, because the person who can fix it
  is the person reading the list.
- The approve button is **hidden** from whoever submitted the claim, not
  merely refused. A control that appears and then always fails teaches people
  the software is broken rather than that the rule exists.
- Categories are the chart of accounts, and the navigation says so. An expense
  line charges an expense or asset account directly, which is what a category
  is; a second table naming the same thing would be a second source of truth
  about where a cost belongs.

### A defect found by writing the tests

**A model in a string-cast attribute hung the request instead of failing it.**
In the mileage-rate controller the validated string and the `MileageRate`
model were both called `$rate`; the model went into the `rate` attribute,
which is cast to a string, so casting called `__toString()`, which serialised
the model, which cast the attribute again — 27,000 stack frames and a
900-second test run rather than an error.

Two things let it through, and both are fixed. The variable names now differ
(`$rateValue`, `$outgoing`, `$mileageRate`), and the test asserts the stored
FIGURE rather than only the row count — the row existed; its rate was a stack
overflow.

### Tests added

- 10 unit tests over §4.8, including both halves of the credit and both halves
  of the claimable/blocked split.
- 38 accounting tests over the lifecycle: receipt → submit → approve against a
  real database, plus mileage resolution by date, self-approval, rejection and
  re-submission, voiding, and rebilling — with `verify-ledger` clean after a
  set of books containing both payment modes and both tax treatments.
- 40 HTTP tests over the screens
  (`tests/Feature/Expenses/ExpenseScreensTest.php`), asserted per role. The
  fixture deliberately has **two** members: with one, the controller treats the
  organisation as a sole trader and permits self-approval, so a single-member
  fixture would have made every separation-of-duties assertion vacuous.

### Phase 5 exit criteria

- [x] A receipt-attached expense posts with the correct tax treatment and
      routes through approval — asserted end to end, against the ledger
- [x] Non-claimable input tax capitalised, not made a receivable — per line,
      and asserted against the alternative
- [x] Approval workflow with a real separation of duties: the claimant cannot
      approve their own expense, whatever permissions they hold
- [x] Receipt capture, served through the application rather than from storage
- [x] Mileage at a dated rate, copied onto the claim
- [x] Billable expenses onto a draft invoice at cost, one customer at a time
- [x] Reimbursable expenses as a liability to a person, separate from payables

---

## Next

1. Opening balances — unblocked since Phase 3, and the oldest outstanding item
2. Phase 1 leftover: an organisation settings screen
3. Browser journeys through the sales, purchase and expense screens
4. Then Phase 6 — Banking, plus recurring invoices
