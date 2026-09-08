# ROADMAP.md

Fourteen phases. Each one ends in something genuinely usable, and each
depends only on what is already built and proven.

**Rule: do not begin Phase *n+1* while Phase *n* is unfinished.** The ledger
has to be right before invoices depend on it; invoices have to be right
before banking reconciles against them.

Current position is in `PROJECT_STATUS.md`.

---

## Phase 0 — Foundation · *complete — 2026-09-05*

Laravel + Docker + React/Inertia + the design system, at production polish.
Verification record and known gaps: `PROJECT_STATUS.md`.

| # | Deliverable | Verified by |
|---|-------------|-------------|
| 0.1 | Git repo, line-ending policy, Laravel 13 skeleton, domain structure | Clean clone builds |
| 0.2 | The seven root documents | A new session can orient from them alone |
| 0.3 | Docker stack: app, horizon, scheduler, postgres, redis, minio, mailpit, vite | `docker compose up` yields a working app |
| 0.4 | PostgreSQL two-role setup, RLS scaffolding, tenant functions | `\du` shows a non-bypassing app role |
| 0.5 | Money foundation — `Brick\Money` casts, decimal handling, rounding | Unit tests including pathological rounding |
| 0.6 | Design tokens and ~25 primitives | Every component rendered in the gallery |
| 0.7 | Application shell: sidebar, top bar, org switcher, global search, user menu | Visual review at three breakpoints |
| 0.8 | Authentication foundation and login screen | Session established; MFA scaffolding present |
| 0.9 | Dashboard shell with widget layout | Loading, empty and error states all designed |
| 0.10 | Pest suites (Unit, Feature, Accounting, Browser) + first tests | `composer test` green |
| 0.11 | Pint, Larastan, ESLint, Prettier, TypeScript strict | `composer check` and `npm run check` green |
| 0.12 | GitHub Actions CI | Pipeline green on the initial commit |

**Exit:** a stranger clones the repository, runs one command, reaches a
polished login screen, signs in, sees the application shell, and every suite
passes.

---

## Phase 1 — Identity and organisations

Auth, MFA, organisations, users, roles, permissions, invitations, audit log,
onboarding wizard.

The onboarding wizard covers company details, jurisdiction, currency, fiscal
year, tax configuration, chart of accounts, invoice settings, opening
balances and user invitations — with every optional step skippable.

**Exit:** two organisations coexist; a user of one provably cannot reach the
other's data through web routes *or* `/api/v1`; the RBAC test matrix passes;
every permission change is audited.

---

## Phase 2 — Accounting core

Chart of accounts, the ledger service, manual journals, fiscal periods,
opening balances, general ledger, trial balance, currencies and FX rates.

This is the phase that decides whether the product is trustworthy. It ships
with the full `ACCOUNTING_RULES.md` §10 test matrix and the `verify-ledger`
command.

**Exit:** trial balance balances; unbalanced postings are rejected at all
three layers; reversals restore balances exactly; closed periods refuse
postings without the override; `verify-ledger` catches a deliberately
corrupted balance.

**Status:** the ledger, its five screens and the exit criteria above are done
(see `PROJECT_STATUS.md`). Still open within this phase: year-end close,
multi-currency revaluation, and a committed browser suite. Opening balances
moved to Phase 3 — most of them are unpaid invoices and bills, which need
contacts and items to exist first.

---

## Phase 3 — Sales

Customers, items and services, taxes, estimates, sales orders, invoices,
recurring invoices, payments received, credit notes, customer statements.

Includes the keyboard-first line-item editor and the invoice PDF pipeline.

**Exit:** estimate → sales order → invoice → payment posts correctly at every
step; AR ages accurately; a customer statement reconciles to the AR control
account.

*After this phase the product can invoice real customers and the books will
be correct.*

---

## Phase 4 — Purchases · *complete — 2026-09-08*

Vendors, purchase orders, bills, payments made, vendor credits, vendor
statements, withholding tax on payment.

**Exit:** PO → bill → payment posts correctly; AP ages accurately;
withholding is recorded as a liability and reconciles.

---

## Phase 5 — Expenses · *complete — 2026-09-09*

Expense entry, categories, receipt upload, mileage, approval workflow,
billable expenses.

**Exit:** a receipt-attached expense posts with the correct tax treatment and
routes through approval; a non-claimable input tax is capitalised, not
receivable.

---

## Phase 6 — Banking

Bank and cash accounts, statement import (CSV, OFX, QIF), transaction
matching, reconciliation workflow, transfers.

**Matching suggests; it never posts.** Confirmation is an explicit,
permissioned, audited act.

**Exit:** a statement reconciles to zero difference; no suggestion has ever
posted without confirmation; a reconciled period cannot be silently altered.

---

## Phase 7 — Reports

Profit & loss, balance sheet, cash flow, trial balance, general ledger,
AR/AP ageing, tax summary, sales and expense analytics — with comparison
periods, drill-through to journal lines, and export to CSV, XLSX and PDF.

**Exit:** every report reconciles to the ledger; every figure drills through
to the journal lines that produced it; a printed report is legible.

---

## Phase 8 — Inventory

Items with stock tracking, warehouses, adjustments, weighted-average
valuation, COGS posting, stock reports.

**Exit:** stock valuation matches the inventory control account exactly, at
every point in time.

---

## Phase 9 — Portal and projects

Client portal (view and pay invoices without a My Books login), projects,
time tracking, document management.

**Exit:** a customer opens an emailed invoice link, views it, and pays —
without an account, and without any cross-tenant exposure.

---

## Phase 10 — Automation and integration

Workflow rules, notifications, webhooks, public API keys and scopes,
import/export, scheduled reports.

**Exit:** a rule fires reliably, is fully audited, and cannot post to the
ledger on its own.

---

## Phase 11 — Localisation

Translations, number and date formats per locale, additional jurisdiction
rule packs (GCC VAT, India GST, EU VAT), customisable invoice templates,
RTL support.

**Exit:** a second jurisdiction and locale ship as configuration, with no
code change to the tax engine.

---

## Phase 12 — AI

Receipt OCR, transaction categorisation, bank match suggestions, report
explanation, natural-language search, financial assistant.

Built on an abstraction layer so the model provider is swappable and can be
disabled entirely — a self-hosted deployment must be able to run with no
external AI service at all.

**Exit:** every AI output is a proposal a permitted human accepts; there is
provably no AI write path to the ledger; acceptance is audited with the model
and confidence that produced it.

---

## Phase 13 — Production hardening

Backup and restore, monitoring and alerting, performance work, penetration
review, upgrade path, operator documentation.

**Exit:** a restore from backup is verified end to end; load targets are met
with a realistic ledger; the security review is closed.

---

## Sequencing rationale

- **Ledger before documents.** Every document's correctness is defined by the
  journal it produces. Building invoices first would mean rewriting them.
- **Sales before purchases.** Same machinery, and sales is where the product
  becomes useful soonest.
- **Banking after both.** Reconciliation needs transactions to reconcile.
- **Reports after banking.** A report that cannot be reconciled is worse than
  no report.
- **Inventory after reports.** Valuation errors are only visible when the
  balance sheet is trustworthy.
- **AI last.** It suggests against a system that must already be correct
  without it.
