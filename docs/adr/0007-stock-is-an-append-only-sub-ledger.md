# 0007 — Stock is an append-only sub-ledger, not a running total

**Status:** Accepted · 2026-09-22

## Context

Phase 8 adds tracked items, warehouses, adjustments, transfers and cost of
sales. Its exit criterion is one sentence, and the whole design follows from
it:

> stock valuation matches the inventory control account exactly, **at every
> point in time**.

"At every point in time" is what makes this hard. A quantity-on-hand column
and a value column on the item, updated as documents post, will agree with the
inventory account today and be unable to answer what the shelf was worth on
31 March — which is the only question a balance sheet ever asks. Worse, when
the two do disagree there is nothing to compare: a single mutable number
carries no history of how it got there, so the difference can be observed and
never explained.

The ledger solved this problem once already. Stock is the same problem with
quantities attached.

## Decision

**Stock is a sub-ledger with the same three properties as the journal:
append-only, written by exactly one service, and carrying its own running
balance.**

`stock_movements` is the sub-ledger. Every row records what moved, in which
direction, on what date, what it was worth — and the quantity and value
**after** it. A database trigger refuses `UPDATE` and `DELETE`, as on
`journal_lines`. `stock_levels` is a projection of it, not the truth: it
exists to be read cheaply, and to be the row every writer locks.

The running balance on each movement makes the weighted average **auditable**
— every row shows the quantity, value and average it produced, so the
arithmetic can be followed step by step.

**It is not, however, how a past date is answered, and getting that wrong was
the single most expensive mistake in the phase.** `value_after` is the state
after N *writes*, and writes are not in date order: back-dating is a supported
flow, so a bill entered late carries a running balance that already includes
movements dated after it. Reading the latest row by date therefore returns a
figure that was never true on that date.

So an as-at figure is **summed**: `SUM(value) WHERE occurred_on <= d`. That is
order-independent, and it is the same arithmetic the ledger does to reach the
control account's balance — which is what makes I10 a comparison of like with
like rather than a coincidence. Today's figure still comes from the projection,
which is what `stock_levels` is for.

**`StockLedger` is the only writer.** Nothing else inserts a movement or
touches a level, for the reason `PostJournalEntry` is the only journal writer:
the weighted average is an invariant, and an invariant enforced in more than
one place is enforced in none.

**Cost is a per-warehouse weighted average.** Value moves with the goods, so a
transfer restates neither side. FIFO was rejected: it needs a layer table and
a consumption order, every outbound movement becomes a loop over layers, and
the answer it gives differs from weighted average only in the timing of
margin — not in the total.

**When the last unit leaves, it takes the whole remaining value** rather than
quantity × average. An average rounded to four places and multiplied back
leaves a fraction behind, and a shelf holding nothing but 0.0002 of value is
both meaningless and a permanent, growing difference between the stock report
and the inventory account. Emptying the shelf empties its value, exactly. The
database backs this up: `CHECK (quantity_after > 0 OR value_after = 0)`.

**Planning and committing are separate calls, deliberately.** A shipment's
journal entry needs the cost, which is not known until the average has been
read; the movement needs that entry's id, and the table is append-only so it
cannot be written and then updated. So `plan()` takes the locks and computes
every figure, the caller posts, and `commit()` writes. Both run inside the
caller's transaction — the locks taken by the first are still held by the
second, which is the point.

**The lock is `SELECT … FOR UPDATE` on the `stock_levels` row.** A weighted
average is read-modify-write. Two shipments of the same item at the same
instant, unlocked, both read the same average and both write a value computed
from a state that no longer exists, and stock and ledger part company by the
difference — silently, and only for those two documents. Serialising on the
level row is the same mechanism the document sequence uses.

**Negative stock is refused, not permitted-and-reported.** A shelf holding
minus four units has no defensible average cost, and every figure computed
from it afterwards is fiction. The refusal is in the service *and* in a
`CHECK (quantity_after >= 0 AND value_after >= 0)`, because the service can be
bypassed by a future caller and the constraint cannot.

**Cost of sales posts at shipment, and carries its own source purpose.** §4.10
dates the cost entry at despatch. The ledger's idempotency key is
`(organisation, source type, source id, purpose)`, and the invoice has already
used `issue`; reusing it would have the ledger refuse the cost entry as a
double-post of the sale — the protection working correctly on the wrong thing.
So the entry is `('sales_document', id, 'cogs')`, and a return is
`'cogs_reversal'`.

**A receipt states a VALUE, not a rate.** `capitalisedCost()` already carries
blocked tax and the line's share of any document discount. Dividing it into a
per-unit rate and multiplying back rounds twice, and leaves the shelf a cent
away from what the ledger debited on the very same transaction.

**Goods coming back are valued at the average of the moment they return**, not
at what they cost when they left. Hunting down the original shipment's rate is
only defensible when the credit note names both the invoice and the line, and
a partial credit against a re-priced invoice has no such line. The consequence
is recorded rather than hidden: a return during a period of rising costs moves
a little margin between periods.

**An adjustment asks what was COUNTED.** The difference is computed on the
server, from what is on hand at approval. A form that asks for a difference
asks somebody standing at a shelf to do arithmetic against a number they
cannot see; and a draft storing a difference computed at save time would post
the wrong one if anything moved in between.

**Undoing anything moves the value the original moved, on the date the
original's reversal is dated.** Both halves of that sentence were defects
before they were rules.

The *value*, because the ledger side of any undo is a mirror reversal —
`ReverseJournalEntry` copies the amounts and swaps the sides — so valuing the
stock side at today's average puts the two halves on different numbers by
however far the average has travelled. Where the goods have since been sold,
the undo does not fit, and it is refused rather than approximated: a document
whose goods are gone cannot be made never to have happened, and the honest
answer is a credit note.

The *date*, because a null date left each half to default for itself, and they
defaulted differently — the entry to today, the stock to the document's own
issue date. The screen sends no date, so that was every void a real person
did. The two sides landed weeks apart and the books were wrong on every date
in between while looking perfect today.

**An inventory account may receive exactly what the shelf receives, and
nothing else.** A tracked item's line is costed to that item's inventory
account whatever was asked for, and nothing that is not tracked stock may be
costed to an inventory account at all — not by naming one on a bill, not by an
item's own default purchase account pointing at one, and not on an expense
claim, which has no stock path behind it whatsoever. Otherwise the control
account carries freight, or carries goods the shelf never saw, and no later
correction makes the two agree about the past.

The rule has to hold on every door or it holds on none: the bill side had it
and the expense side did not, and one dropdown entry was enough to break the
invariant permanently.

**Each movement records the account its value went to, at the time.** Not
derived from the item afterwards, because `items.inventory_account_id` is
editable and a bill line freezes its own account when the bill is saved. A
check keyed on the item master as it reads *today* could be made to invent a
divergence by an edit — or switched off entirely by clearing a checkbox, which
dropped the account out of the reconciliation and stopped anything being
reported at all.

**`verify-ledger` proves the exit criterion rather than asserting it.** Two
checks, and both of them had to be rewritten after the first attempt measured
the wrong thing:

- `stock_projection` compares each level against the **sum** of the movements
  behind it. Ordering by date reports drift on correct books as soon as
  anything is back-dated; following `last_movement_id` fixes that but only
  proves the level equals the row it points at, so a level that simply stopped
  being updated — what a half-restored backup looks like — agreed with its
  stale pointer and passed. Summing catches both. The pointer is still checked
  separately, because one aimed at another item's movement is worth naming.
- `stock_valuation` is the criterion itself: for each inventory account, on
  **every date on which either side moved**, the summed stock value equals the
  account's balance. Not today's figure — every date there has ever been a
  figure for.

## Consequences

- An as-at valuation is one indexed pass, and stays that way as the business
  grows.
- A disagreement between stock and the ledger is always attributable: both
  sides are append-only and both are dated, so the first date they differ on
  names the document that caused it.
- Nothing can correct stock by editing it. A mistake is adjusted, with a
  reason and an approval, and the correction is itself a movement.
- The cost of the append-only rule is storage, and a two-phase writer that is
  awkward to call. It is therefore called from exactly one layer — the actions
  in `app/Domain/Inventory`.
- A tracked item bought on a bill now debits **inventory**, not an expense.
  Purchases of tracked items no longer hit the profit and loss until the goods
  are sold, which is the accounting this phase exists to deliver — and a
  visible change for anyone who traded before it.

## What the reviews taught, and what it cost

Four rounds of adversarial review ran against this design and its
implementation, confirming 51 defects between them and converging as they went
— 28, then 16, then 5, then 2. Almost every one was the same shape — **the ledger
moved and the shelf did not, or they moved by different figures** — and almost
none was visible in today's numbers, which is precisely why the phase is
judged on every date rather than the latest one.

Two lessons are worth keeping, because they generalise past inventory:

**The measuring instrument needs reviewing harder than the thing measured.**
`verify-ledger` was wrong in both directions at once: it failed permanently on
correct books the moment anything was back-dated, and it could be silenced
entirely by editing an item. A check that cries wolf gets ignored, and one
that can be switched off by a form field was never a check.

**A regression test that exercises a path production does not take proves
nothing.** Every void test passed an explicit date. The screen sends none — so
the tests were green while the only code path real users reach put the money
and the goods weeks apart. The fix was to make the parameter required, so the
two halves cannot be defaulted apart, and to test the call the controller
actually makes.

**A rule enforced on one door is enforced on none, and a rule enforced at one
moment holds only at that moment.** The bill side refused costing to an
inventory account and the expense side did not — one dropdown entry was enough
to break the invariant for good. And the guard that did exist ran at save,
while the posting happens at approval: an account that was an ordinary asset
when the document was written could be an inventory account by the time its
entry landed. Hence the same check at both moments, and at both ends — an
inventory account refuses postings that move no stock, and an account already
carrying such postings refuses to become one.

## Rejected

- **A quantity and value column on the item.** Cannot answer an as-at
  question, and cannot explain a difference once there is one.
- **FIFO or specific identification.** More machinery, a different timing of
  margin, no better answer. Revisit if a jurisdiction rule pack requires it —
  the movement table has room for a layer reference and would not need
  rebuilding.
- **Deriving valuation by replaying movements on every read.** Correct, and
  slower every month. The running balance on the movement is the cache, and it
  is written under the same lock as the movement, so it cannot drift.
- **Posting cost of sales when the invoice is issued.** Simpler by one moving
  part, wrong under §4.10, and wrong in substance: the margin belongs in the
  period the goods left.
- **Allowing negative stock with a warning.** Every downstream figure computed
  from a negative average is fiction, and warnings are not read.
