# 0005 — The bank statement is evidence, not an entry

**Status:** Accepted · 2026-09-14

## Context

Phase 6 brings in bank data: statement import, matching, reconciliation and
transfers. Every accounting product has to decide what an imported bank line
*is*, and the decision shapes everything after it.

The tempting design — the one most bookkeeping tools start with — treats the
import as a source of transactions: a line arrives, a rule categorises it, and
an entry is posted. It demonstrates well. It is also how a set of books ends up
containing entries nobody chose to make, dated by a bank's clearing schedule
rather than by when anything happened, categorised by a rule somebody wrote
once and forgot.

Two further questions came with it. What makes a reconciliation trustworthy
after the fact? And what stops a reconciliation reaching zero while being
wrong?

## Decision

**A statement line is the bank's record, and it never posts.** Import writes
rows in `bank_statement_lines` and nothing else — no journal entry, not one
line. `ACCOUNTING_RULES.md` §8 already said as much; Phase 6 makes it
structural. Importing is its own permission (`banking.import`) precisely
because it is harmless, which is what lets a bookkeeper do it.

**A match is an assertion by a person, and it posts nothing either.** Both
sides existed before it: the journal entry was posted when the payment was
recorded, and the statement line came from the bank. `bank_transaction_matches`
records who said so and when. It takes `banking.reconcile` — a permission a
bookkeeper does not have, because deciding what a line *means* is the judgement
the books rest on.

**Suggestions require an exact amount.** Never "close", never "within
rounding". A near-amount suggestion is the mechanism by which a reconciliation
is forced to zero while being wrong: 12,450 accepted against 12,540, the
difference absorbed into a matched pair, and nothing on any screen saying so
afterwards. A line with no exact counterpart is telling the truth about the
books, and the screen says that instead.

**Four refusals guard the match**, each closing a different way to zero:
the entry must be on this bank account; it must move money the same way the
statement says; it must not already have cleared something else (a unique index
on `journal_line_id`); and the matches on a line may never exceed what the line
is worth.

**A completed reconciliation is frozen by the database.** Completing stamps
every statement line and match in the period with the reconciliation's id, and
two triggers refuse every subsequent update and delete on a stamped row, plus
any change to a completed reconciliation itself. Not a check in a controller: a
console command, a future import path or a stray `->update()` would each bypass
that, and "these books were reconciled to zero on 30 June" has to be a claim
somebody can still check a year later.

**Reconciling proves the books, not the file.** Alongside the four statement
figures, the screen carries the ledger balance at the period end and the
entries of ours no statement line has cleared. When every earlier period
reconciled, `cleared = ledger balance − unpresented` holds exactly, and the
accounting test suite asserts it.

**A transfer is the one banking document that posts.** §4.9: debit where it
landed, credit where it left, never income or expense on either side. It needs
`banking.transfer` *and* `accounting.post`, because moving money between
accounts and writing in the ledger are different permissions. Across
currencies, both amounts are recorded as they actually happened and the
base-currency difference is a realised FX gain or loss — deriving the far side
at a rate would make the cost of converting disappear and leave one of the two
accounts unable to reconcile for ever.

## Rejected

- **Posting from the statement, with categorisation rules.** Fast to
  demonstrate, and it produces entries nobody chose. It also inverts the
  authority model: a bank feed would be writing in the ledger while a
  bookkeeper cannot.
- **Automatic matching above a confidence threshold.** The threshold is the
  problem. Whatever it is set to, it converts a judgement into a default, and
  the errors it makes are the invisible kind.
- **Caching a balance on the bank account.** A second answer to a question the
  chart of accounts already answers. The two would part company the first time
  anything posted around it.
- **Reopening a completed reconciliation.** Corrections go forward, in a later
  period, exactly as they do for a posted entry. Reopening would silently
  withdraw a statement about the books at a date.
- **Storing full bank account numbers.** A payment instruction, not
  reconciliation data. Only the last four digits are kept, and the masking
  happens in the domain action so no other entry point can skip it.
