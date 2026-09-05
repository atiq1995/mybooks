# SECURITY.md

Security posture for My Books. This is an accounting system: the data it
holds is commercially sensitive, legally significant, and attractive to
fraud. Treat every item here as a requirement, not a recommendation.

Report a vulnerability privately to the repository owner. Do not open a
public issue.

---

## 1. Threat model

What we are actually defending against, in rough order of likelihood:

| Threat | Consequence | Primary control |
|--------|-------------|-----------------|
| Cross-tenant data access | One company reads another's books | Global scope + PostgreSQL RLS (§3) |
| Privilege escalation within an org | A clerk posts or approves what they may not | Policies and Gates, server-side (§4) |
| Credential stuffing | Account takeover | Rate limiting, argon2id, MFA (§2) |
| Session hijacking | Account takeover | httpOnly + encrypted + `SameSite` cookies (§2) |
| Silent ledger tampering | Misstated financials, undetected fraud | Append-only ledger, audit log (§6) |
| Malicious upload | RCE or stored XSS via a "receipt" | Generated keys, no execution, presigned reads (§7) |
| Secret leakage through logs | Full compromise | Structured logging with redaction (§8) |
| Dependency compromise | Full compromise | Lock files, CI audit (§9) |

Insider risk is explicitly in scope. The audit log exists to make an
authorised action attributable, not merely to satisfy an auditor.

---

## 2. Authentication

Session-based, first-party, via **Laravel Fortify**. There is no third-party
identity provider in the default deployment — a self-hosted product must not
require one.

| Control | Setting |
|---------|---------|
| Password hashing | argon2id (`BCRYPT_ROUNDS` retained for legacy verification only) |
| Password policy | Minimum 12 characters, checked against known-breach lists |
| Session store | Redis, **encrypted**, `httpOnly`, `SameSite=Lax` |
| Session lifetime | 480 minutes idle; absolute cap enforced server-side |
| `Secure` cookie | Required in production (`SESSION_SECURE_COOKIE=true`) |
| Login throttling | 5 attempts per email+IP, 15-minute decay |
| MFA | TOTP with recovery codes; WebAuthn/passkeys supported |
| Email verification | Required before any financial action |
| Password reset | Single-use, 60-minute token; invalidates every session |
| Session invalidation | Password change, MFA change, or role change ends all other sessions |

**MFA is mandatory for any role that can post to the ledger, approve a
payment, or manage users.** This is enforced by middleware, not by policy
documentation.

### Programmatic access

`/api/v1` uses **Sanctum** tokens with explicit scopes. Tokens are shown once
at creation, stored hashed, carry an optional expiry, and are listed with
last-used timestamps so an unused key is visible and revocable. A token never
grants more than the issuing user holds.

---

## 3. Multi-tenancy

**The single most important control in this system.** Two independent layers,
neither trusted alone.

### Layer 1 — application

Organisation-owned models use the `BelongsToOrganization` trait:

- a global Eloquent scope constrains every query to the active organisation
- `organization_id` is set automatically on create and is immutable after
- the scope cannot be removed without an explicit, audited call reserved for
  maintenance commands

### Layer 2 — database

PostgreSQL row-level security.

- The application connects as **`my_books_app`**, a role that **owns
  nothing**. In PostgreSQL a table owner bypasses RLS; a non-owner cannot.
- Migrations use a separate `pgsql_owner` connection as the schema owner,
  because a migration legitimately needs to bypass RLS.
- `SetPostgresTenantContext` middleware issues `SET LOCAL app.organization_id`
  inside the request transaction. Policies read it via
  `app_current_organization_id()`.
- No tenant context means **no rows**, not all rows. A query outside a
  request context returns nothing rather than everything.

An application bug is therefore not sufficient to leak one organisation's
books to another. That is the entire point of the arrangement.

### Verification

Every module ships a test asserting that a user of organisation A receives
404 (not 403 — existence is itself information) for a resource in
organisation B, through **both** the web routes and `/api/v1`.

---

## 4. Authorization

Server-side, always. **A React conditional is a convenience for the user, not
a security control.** The frontend hides what a user cannot do; the backend
refuses it.

- **Policies** for resource decisions: `InvoicePolicy@post`.
- **Gates** for capability decisions: `accounting.post_to_closed_period`.
- **FormRequest::authorize()** is the entry point for every mutating route.
- Roles are per-organisation. The same person may be an accountant in one
  organisation and read-only in another; permissions never leak across.
- Permissions are a fixed catalogue in code, not free text in the database —
  a typo must be a failing test, not a silently ungranted permission.

Sensitive capabilities are separated deliberately, so no single role can both
create and approve:

| Capability | Held by |
|------------|---------|
| `accounting.post` | Accountant, Admin |
| `accounting.post_to_closed_period` | Admin only, and audited on every use |
| `accounting.reverse` | Accountant, Admin |
| `banking.reconcile` | Accountant, Admin |
| `payments.approve` | Approver, Admin — never the payment's creator |
| `users.manage` | Admin |
| `settings.accounting` | Admin |

---

## 5. Input and output

| Concern | Control |
|---------|---------|
| Validation | FormRequests on every mutating route. Never trust a client-supplied total, tax, or id. |
| Mass assignment | Explicit `$fillable`; `organization_id`, totals and status are never fillable |
| SQL injection | Eloquent and bound parameters. Raw SQL is reviewed and always parameterised |
| XSS | React escapes by default. `dangerouslySetInnerHTML` is prohibited outside a reviewed, sanitised email-preview component |
| CSRF | Laravel's token on every session-authenticated mutating request |
| Clickjacking | `X-Frame-Options: DENY`, `frame-ancestors 'none'` |
| MIME sniffing | `X-Content-Type-Options: nosniff` |
| CSP | Strict; no `unsafe-inline` scripts in production |
| HSTS | `max-age=31536000; includeSubDomains` when served over TLS |
| Rate limiting | Per-user and per-IP on auth, `/api/v1`, exports and imports |

Headers are set both by Caddy and by the application, because a static asset
served straight off disk never reaches PHP.

---

## 6. Audit and ledger integrity

- **The ledger is append-only.** Posted `journal_entries` and `journal_lines`
  cannot be updated or deleted; a database trigger enforces it. Corrections
  are reversing entries.
- **Audit rows are written in the same transaction** as the change they
  describe. An audit trail that can be committed separately is an audit trail
  that can be lost.
- Every audit entry records actor, organisation, action, subject, before and
  after values, IP, user agent, and request id.
- Audit records are **immutable and never purged**. Retention is a legal
  requirement, not a storage preference.
- `php artisan my-books:verify-ledger` re-derives control-account balances
  from raw journal lines and exits non-zero on any discrepancy. It runs
  nightly and in CI.

Specifically audited: every posting and reversal, period open/close, opening
balances, permission and role changes, user invitation and removal, MFA
changes, API token issue and revocation, tax-rate changes, bank reconciliation
confirmation, and every use of an override permission.

---

## 7. File uploads

Receipts and bank statements are attacker-controlled files.

- Stored in object storage (MinIO/S3), **never on the application filesystem**
- Object keys are generated; the user's filename is metadata only and is
  never used as a path
- Content type is verified from the file's contents, not its extension or the
  supplied header
- Allow-list only: PDF, PNG, JPEG, CSV, OFX, QIF, XLSX
- Size limits enforced at the proxy and again in the application
- Served exclusively through **short-lived presigned URLs**; the bucket is
  private and anonymous access is explicitly removed at provisioning
- Nothing uploaded is ever executed, included, or rendered as HTML

---

## 8. Secrets and logging

- `APP_KEY` is generated per deployment. Rotating it invalidates every
  encrypted value — see `DEPLOYMENT.md` before doing it.
- `.env` is never committed. `.env.example` carries structure and comments,
  never real values.
- The default credentials in `docker-compose.yml` are for **local
  development only** and must be replaced before any networked deployment.
- Logs are structured JSON with redaction of `password`, `token`, `secret`,
  `authorization`, `api_key`, `card`, and cookie values.
- **Financial detail does not belong in application logs.** Log that invoice
  `X` was posted by user `Y`; do not log its amounts or the customer's
  balance. The audit log is the record of what happened; logs are for
  diagnosing how the system behaved.
- Exceptions never surface stack traces to a browser. `APP_DEBUG=false` in
  production is verified by a startup check.

---

## 9. Dependencies and supply chain

- `composer.lock` and `package-lock.json` are committed and authoritative.
- Composer resolution happens **inside the application image**, so the lock
  reflects the real platform.
- CI runs `composer audit` and `npm audit`; a known-exploited advisory fails
  the build.
- Base images are pinned to a major-version tag; production deployments
  should pin by digest.
- New dependencies require justification in the pull request. Every package
  is code we are choosing to trust with a ledger.

---

## 10. Before exposing this to a network

A checklist, not a suggestion.

- [ ] `APP_DEBUG=false` and `APP_ENV=production`
- [ ] `APP_KEY` generated fresh for this deployment
- [ ] Every default password in `docker-compose.yml` replaced
- [ ] `SESSION_SECURE_COOKIE=true` and TLS terminating correctly
- [ ] `MY_BOOKS_TRUSTED_HOSTS` set to the real hostname
- [ ] PostgreSQL and Redis ports **not** published to the host
- [ ] MinIO console not publicly reachable
- [ ] Automated backups running **and a restore actually tested**
- [ ] MFA enrolled for every administrator
- [ ] `verify-ledger` scheduled
- [ ] Log shipping configured, with redaction confirmed by inspection
