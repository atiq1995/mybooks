# PROJECT_STATUS.md

Where the work stands. Read after `CLAUDE.md`, before doing anything.

**Updated:** 2026-09-08
**Phase:** 2 — Accounting core — **in progress**
**Phase 0:** complete, verified, pushed (`085d102`).
**Phase 1:** complete apart from two items listed under Gaps.

**Gates, as of this update:** 268 tests / 1,649 assertions green; PHPStan
level max clean; Pint clean; `tsc --noEmit` clean; ESLint (incl. `jsx-a11y`)
clean; production asset build succeeds. Every figure in this document was
observed, not assumed.

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
| API `/api/v1` | Sanctum installed, no routes | Phase 3 |
| Git | Repository initialised; **nothing committed yet** | Ask owner |

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
Fiscal Periods (open/close/reopen/lock, open a financial year).

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

### Tests added (93 domain + 41 HTTP)

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
- [ ] Multi-currency revaluation and the FX gain/loss run
- [ ] Year-end close (`accounting.close_year` exists; the Action does not)
- [ ] Committed browser suite for the accounting screens

---

## Next

1. Year-end close: post net income to retained earnings, lock the year
2. Multi-currency: exchange-rate entry UI, revaluation, FX gain/loss posting
3. Browser (Pest 4) suite over the five accounting screens — the visual
   review gate in `CLAUDE.md` has been met by hand, not by a committed test
4. Phase 1 leftovers: organisation settings screen; committed browser E2E
5. Then Phase 3 — Sales
