# 0006 — Every report reads the ledger through one service

**Status:** Accepted · 2026-09-19

## Context

Phase 7 adds a profit and loss, a balance sheet, a cash flow, a tax summary
and four analytical views, on top of a trial balance and a general ledger that
already existed.

The obvious way to build them is one query per report. It is also how a set of
reports comes to disagree with itself: one aggregate forgets to exclude
heading accounts, another rounds at a different point, a third uses the
transaction amount where the rest use base currency. Each report looks right
on its own. The set is wrong, and the disagreement is always found by somebody
outside the business.

Two further questions came with the phase. What does "every figure drills
through to its journal lines" mean precisely enough to test? And what is an
export — the same report, or a second implementation of it?

## Decision

**One service reads the ledger: `LedgerBalances`.** Base currency only,
posted entries only, never a heading account, and the same date semantics
everywhere. Every statement is built from the `AccountBalance` rows it
returns and from nothing else, so the statements reconcile to each other by
construction rather than by being checked afterwards. The trial balance, which
predated the phase, was moved onto it too.

**One classification: `AccountGroup`.** Derived from the account's subtype,
falling back to its type, and used by all three statements. An account with no
subtype still lands somewhere on every statement — a silent exclusion would
misstate the statement, not the account.

**Two ways to sign a balance, and the distinction is deliberate.**
`signed()` reads an account in its own direction, which is what a profit and
loss wants: sales returns show as a positive 20,000 that is then deducted.
`forStatement()` signs by account TYPE, which is what a balance sheet needs:
accumulated depreciation is an asset carrying a credit balance, and reading it
in its own direction would add it to non-current assets instead of subtracting
it, putting the sheet out by twice the accumulated charge.

**The cash flow falls out of the ledger identity rather than a rule set.**
Because every movement nets to zero,

    Δcash = profit − Σ(net movement of every other non-P&L account)

so each adjustment line is simply the negative of an account's net movement.
A receivable that grew subtracts; a payable that grew adds; depreciation
credited to accumulated depreciation adds back. None of those is a special
case in the code — the sections only decide where a line is printed. The
statement then compares its own net change against the actual movement on the
cash accounts and reports a difference rather than hiding one.

**A drill-through is carried by the ROW, not assembled by the screen.** Each
row ships the account and the exact bounds its figure was summed over, and the
link is built from those. A balance sheet row has no lower bound at all, so a
screen assembling the filter from its own date pickers would show a different
set of lines than the total was built from. A drill-through that disagrees
with its total is worse than none: it looks like proof.

**An export is the same report, not a second implementation.** `?format=csv`,
`xlsx` or `print` on the same URL returns the same `ReportTable` the screen
renders. Tests assert the screen's own figure appears in each export.

**Reading a report and exporting one are different permissions.**
`reports.view` opens a statement inside a session; `reports.export` produces a
copy that leaves with whoever asked for it. A bookkeeper, an approver and a
viewer have the first and not the second.

## Rejected

- **A query per report.** Faster to write, and it produces a set of statements
  that disagree. The disagreement is found late and by the wrong people.
- **A materialised balances table.** A second source of truth about figures
  the ledger already holds, needing invalidation on every post, and wrong in
  exactly the window where somebody is reading a report after a correction.
- **A spreadsheet library.** What is needed of xlsx here is one sheet, inline
  strings, numbers with a display format and a frozen header row. A library
  would add megabytes and a large API surface to produce that, and would still
  need as much code to drive it. `ZipArchive` and six XML parts do it, and the
  format is understood rather than delegated.
- **A server-rendered PDF.** The exit criterion is that a printed report is
  legible, and a print view the browser saves as a PDF meets it at the
  reader's own paper size and margins. A rendering engine in the image would
  fix those choices for everybody and produce a worse document. This is a
  decision to revisit if reports ever have to be generated unattended — an
  emailed month-end pack, say — where there is no browser to print them.

## Found while building this

Two defects, both invisible on today's figures and wrong on every historical
one:

1. **The trial balance included entries dated after its as-at date.** Filtering
   `journal_entries` in the `ON` clause of a left join leaves the joined
   `journal_lines` row in the result, and the sum is over the lines. Every
   figure "as at" a past date silently included everything posted after it.
   Fixed by aggregating in a derived table, and by moving the trial balance
   onto `LedgerBalances` so the shape exists in one place.

2. **Report queries were not tenant-scoped in the application layer.** A query
   builder is not an Eloquent model, so the global scope that keeps every
   other read inside one organisation did not apply. PostgreSQL row-level
   security still sat underneath, but a report relying on the second layer
   alone is one connection-role change away from showing another company's
   figures. Every report query now filters `organization_id` explicitly, and a
   test asserts a second organisation sees zero.
