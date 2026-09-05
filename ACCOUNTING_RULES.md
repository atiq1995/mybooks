# ACCOUNTING_RULES.md

The accounting contract for My Books. When this document and the code
disagree, **this document is the specification and the code is the bug** —
unless the code is right and this document is stale, in which case fix this
document in the same commit.

Jurisdiction of record for the first release: **Pakistan** (GST + withholding
tax, PKR). The engine itself is jurisdiction-agnostic; country specifics live
in rule packs under `app/Domain/Tax/Jurisdictions/`.

---

## 1. Invariants

These hold at all times, for every organisation, in every period.

| # | Invariant | Enforced by |
|---|-----------|-------------|
| I1 | Every posted entry balances: `Σ debit = Σ credit` | `PostJournalEntry`, deferred DB trigger, `verify-ledger` |
| I2 | Balances hold in **both** currencies: transaction and base | Same three layers |
| I3 | A journal line has a debit **or** a credit, never both, never neither, never negative | `CHECK` constraint |
| I4 | Posted entries and lines are never updated or deleted | DB trigger |
| I5 | Every entry belongs to exactly one open-at-posting-time fiscal period | `PostJournalEntry` |
| I6 | `(source_type, source_id, purpose)` is unique across posted entries | Unique index |
| I7 | Every line's account belongs to the same organisation as the entry | FK + `CHECK`, RLS |
| I8 | The AR control account equals the sum of open customer balances | `verify-ledger` |
| I9 | The AP control account equals the sum of open vendor balances | `verify-ledger` |
| I10 | The inventory control account equals total stock valuation | `verify-ledger` (Phase 8) |
| I11 | Trial balance totals are equal and the balance sheet balances | `verify-ledger` |

I8–I11 are **reconciliations**, not constraints: they can only be checked
after the fact. `php artisan my-books:verify-ledger` runs them and exits
non-zero on any discrepancy. It runs nightly and in CI.

---

## 2. Money

**Never a float. Anywhere.**

| Concern | Rule |
|---------|------|
| Storage | `numeric(19,4)` for money, `numeric(19,6)` for quantities, `numeric(19,10)` for rates |
| PHP | `Brick\Money\Money` for amounts, `Brick\Math\BigDecimal` for intermediates |
| Transport | Decimal **strings** in JSON (`"1234.5600"`), never JSON numbers |
| TypeScript | `string`, formatted for display only. Never `parseFloat` for arithmetic |
| Display | `Intl.NumberFormat` with the organisation's locale and currency |

### Rounding

Default **half-up**, at 2 decimal places for most currencies — the
organisation's `rounding_mode` setting governs and is applied consistently.
Rounding happens at **defined boundaries only**:

1. Each line's net amount, after quantity × price − discount
2. Each line's tax amount, per tax component
3. The document total

Intermediate values keep full precision. Rounding early and rounding often is
how a document's total stops matching the sum of its lines.

### The rounding-difference account

When per-line rounding does not sum exactly to the document total (common
with tax-inclusive pricing), the difference — never more than a few minor
units — posts to **`9800 Rounding Difference`**. It is never absorbed
silently into revenue or tax, because a tax authority reconciles tax to the
minor unit.

---

## 3. The chart of accounts

Five root types. The **normal balance** determines whether a debit increases
or decreases the account.

| Type | Normal balance | Increases with | Statement |
|------|----------------|----------------|-----------|
| Asset | Debit | Debit | Balance sheet |
| Liability | Credit | Credit | Balance sheet |
| Equity | Credit | Credit | Balance sheet |
| Income | Credit | Credit | Profit & loss |
| Expense | Debit | Debit | Profit & loss |

Contra accounts carry the opposite normal balance within their type — trade
discounts are an income-type account with a debit normal balance.

### System accounts

Each organisation maps these roles to real accounts during onboarding. They
cannot be deleted, and their type cannot be changed once anything is posted.

| Role | Default code | Type | Used by |
|------|--------------|------|---------|
| `accounts_receivable` | 1200 | Asset | Invoices, customer payments |
| `accounts_payable` | 2100 | Liability | Bills, vendor payments |
| `inventory` | 1300 | Asset | Stock movements |
| `gst_output` | 2300 | Liability | GST charged on sales |
| `gst_input` | 1400 | Asset | GST paid on purchases (claimable) |
| `wht_receivable` | 1450 | Asset | Tax withheld **by** customers from us |
| `wht_payable` | 2350 | Liability | Tax withheld **by us** from vendors |
| `customer_advances` | 2200 | Liability | Payments received before invoicing |
| `vendor_advances` | 1250 | Asset | Payments made before billing |
| `retained_earnings` | 3200 | Equity | Year-end close |
| `current_year_earnings` | 3300 | Equity | Computed, not posted to directly |
| `fx_gain_loss` | 7500 | Income/Expense | Currency settlement and revaluation |
| `rounding_difference` | 9800 | Expense | Rounding boundaries |
| `opening_balance_equity` | 3100 | Equity | Migration from a previous system |
| `cogs` | 5000 | Expense | Cost of goods sold |
| `sales_returns` | 4800 | Income (contra) | Credit notes |
| `trade_discounts` | 4900 | Income (contra) | Invoice discounts |

---

## 4. Posting rules

`Dr` = debit, `Cr` = credit. Every table below balances; check them.

### 4.1 Sales invoice issued

Invoice of 100,000.00 net, 5% trade discount, 18% GST on the discounted
amount.

| Account | Dr | Cr |
|---------|---:|---:|
| 1200 Accounts Receivable | 112,100.00 | |
| 4900 Trade Discounts *(contra)* | 5,000.00 | |
| 4000 Sales Revenue | | 100,000.00 |
| 2300 GST Output Payable | | 17,100.00 |
| **Total** | **117,100.00** | **117,100.00** |

Revenue is recorded **gross** and the discount posted to contra-revenue, so
gross sales and discounts given are both reportable. Netting the discount
into revenue destroys that information permanently.

Posted when the invoice moves to `sent` (or `open`), not when drafted. A
draft has no accounting effect at all.

### 4.2 Customer payment, with withholding tax

Customer pays the 112,100.00 invoice, withholding 4% income tax on the
pre-tax value (4,000.00) and remitting the rest.

| Account | Dr | Cr |
|---------|---:|---:|
| 1010 Bank | 108,100.00 | |
| 1450 Withholding Tax Receivable | 4,000.00 | |
| 1200 Accounts Receivable | | 112,100.00 |
| **Total** | **112,100.00** | **112,100.00** |

The withheld amount is an asset — advance income tax paid on our behalf,
recoverable against the annual return. Treating it as a discount or a
write-off loses a real receivable from the tax authority.

### 4.3 Customer payment, no withholding

| Account | Dr | Cr |
|---------|---:|---:|
| 1010 Bank | *amount* | |
| 1200 Accounts Receivable | | *amount* |

### 4.4 Overpayment or advance received

The unallocated portion is a liability — we owe goods, services, or a refund.

| Account | Dr | Cr |
|---------|---:|---:|
| 1010 Bank | *received* | |
| 1200 Accounts Receivable | | *allocated* |
| 2200 Customer Advances | | *unallocated* |

Allocating the advance later moves it: `Dr 2200 / Cr 1200`.

### 4.5 Credit note against an invoice

| Account | Dr | Cr |
|---------|---:|---:|
| 4800 Sales Returns *(contra)* | *net* | |
| 2300 GST Output Payable | *tax* | |
| 1200 Accounts Receivable | | *gross* |

### 4.6 Vendor bill received

| Account | Dr | Cr |
|---------|---:|---:|
| 5xxx Expense **or** 1300 Inventory | *net* | |
| 1400 GST Input Receivable | *claimable tax* | |
| 2100 Accounts Payable | | *gross* |

Non-claimable input tax is **not** posted to 1400 — it is capitalised into
the expense or inventory value, because it is a real cost, not a receivable.

### 4.7 Vendor payment, with withholding

We withhold 10% on a services bill of 50,000.00 and remit 45,000.00.

| Account | Dr | Cr |
|---------|---:|---:|
| 2100 Accounts Payable | 50,000.00 | |
| 1010 Bank | | 45,000.00 |
| 2350 Withholding Tax Payable | | 5,000.00 |
| **Total** | **50,000.00** | **50,000.00** |

The withheld amount is a liability until remitted to the tax authority; the
vendor's account is settled in full.

### 4.8 Expense paid directly

| Account | Dr | Cr |
|---------|---:|---:|
| 5xxx Expense | *net* | |
| 1400 GST Input Receivable | *claimable tax* | |
| 1010 Bank / 1000 Cash | | *gross* |

### 4.9 Bank transfer

| Account | Dr | Cr |
|---------|---:|---:|
| 1020 Bank — destination | *amount* | |
| 1010 Bank — source | | *amount* |

A transfer is never income or expense on either side.

### 4.10 Cost of goods sold *(Phase 8)*

Posted on **shipment**, which is not always the invoice date.

| Account | Dr | Cr |
|---------|---:|---:|
| 5000 Cost of Goods Sold | *cost* | |
| 1300 Inventory | | *cost* |

Cost is weighted-average at the moment of shipment.

### 4.11 Realised FX gain on settlement

Invoice USD 1,000 at 278.00 (PKR 278,000). Settled at 280.50 (PKR 280,500).

| Account | Dr | Cr |
|---------|---:|---:|
| 1010 Bank | 280,500.00 | |
| 1200 Accounts Receivable | | 278,000.00 |
| 7500 FX Gain/Loss | | 2,500.00 |
| **Total** | **280,500.00** | **280,500.00** |

Receivable clears at the **rate on the invoice**, never a re-derived one.
The difference is the gain. This is why every line stores both transaction-
and base-currency amounts at posting time.

### 4.12 GST remittance

| Account | Dr | Cr |
|---------|---:|---:|
| 2300 GST Output Payable | *output for period* | |
| 1400 GST Input Receivable | | *input claimed* |
| 1010 Bank | | *net payable* |

If input exceeds output, the balance is a refund due and stays in 1400.

### 4.13 Opening balances

Migrating from another system. The contra side is opening balance equity,
which must be zero once every opening balance is entered.

| Account | Dr | Cr |
|---------|---:|---:|
| *each asset* | *balance* | |
| *each liability* | | *balance* |
| 3100 Opening Balance Equity | *balancing* | *balancing* |

### 4.14 Year-end close

Income and expense accounts close to retained earnings; balance sheet
accounts carry forward.

| Account | Dr | Cr |
|---------|---:|---:|
| *each income account* | *its credit balance* | |
| *each expense account* | | *its debit balance* |
| 3200 Retained Earnings | *or* | *net result* |

### 4.15 Reversal

Never edit a posted entry. Reverse it and post a correct one.

The reversal is a new entry with debits and credits swapped, dated in an open
period, carrying `reversal_of = <original entry id>`. Both entries remain
visible forever.

---

## 5. Tax

### Calculation order

Per line, in this order — the order changes the answer:

1. `gross = quantity × unit_price`
2. `net = gross − line_discount` (amount or percentage)
3. Apportion any **document-level** discount across lines, pro rata by net
4. `taxable = net` after all discounts
5. For each tax component, in `sequence` order:
   - simple: `tax = taxable × rate`
   - compound: `tax = (taxable + Σ preceding tax) × rate`
6. Round each tax component
7. `line_total = net + Σ tax`

### Tax-inclusive pricing

When a price includes tax, the net is extracted rather than added:

```
net = inclusive_price ÷ (1 + Σ rates)
tax = inclusive_price − net
```

Extraction happens once, at full precision, then rounds. The document total
must equal the sum of the inclusive prices entered — if the user typed
1,180.00, the invoice says 1,180.00.

### Withholding tax

Withholding is **not** a tax on the document. It is a payment-time deduction,
so it never changes the invoice or bill total — it changes how the balance is
settled. Rules 4.2 and 4.7 show both directions.

### Pakistan defaults

| Tax | Typical rate | Behaviour |
|-----|--------------|-----------|
| GST — goods | 18% | Output on sales, input claimable on purchases |
| GST — services (provincial) | 13–16% | Varies by province; separate liability accounts |
| Income tax withholding — services | 10% (filer) | Deducted at payment |
| Income tax withholding — goods | 4–5% (filer) | Deducted at payment |
| Further/extra tax | 3% | Applied to unregistered buyers |

Rates are **data**, seeded per organisation and versioned with effective
dates. A rate change must never retroactively alter a posted document.

---

## 6. Documents and their accounting effect

| Document | Posts? | When |
|----------|--------|------|
| Estimate / Quote | No | Never — not a financial event |
| Sales order | No | Commitment only |
| **Invoice** | **Yes** | On issue (`draft` → `sent`) |
| Recurring invoice | Yes | On each generated invoice, at its own date |
| **Customer payment** | **Yes** | On receipt |
| **Credit note** | **Yes** | On issue |
| Purchase order | No | Commitment only |
| **Bill** | **Yes** | On approval |
| **Vendor payment** | **Yes** | On payment |
| **Vendor credit** | **Yes** | On issue |
| **Expense** | **Yes** | On record |
| **Bank transfer** | **Yes** | On record |
| Bank statement import | No | Import is not posting |
| **Reconciliation match** | **Yes** | On explicit confirmation, never automatically |
| **Manual journal** | **Yes** | On post |
| **Inventory adjustment** | **Yes** | On approval |

### Status lifecycles

```
Invoice   draft → sent → partially_paid → paid
                    ↓         ↓
                overdue ← ────┘
                    ↓
                 void  (reverses; never deletes)

Bill      draft → open → partially_paid → paid
                             ↓
                          overdue

Payment   draft → completed → (refunded | bounced)
```

`void` always posts a reversing entry. Deletion of a posted document is not
possible, by design and by trigger.

---

## 7. Fiscal periods

| State | Posting allowed | How to change |
|-------|-----------------|---------------|
| `open` | Yes | — |
| `closed` | Only with `accounting.post_to_closed_period` | Reopened by an admin; audited |
| `locked` | Never | Cannot be reopened. Set after filing. |

Year-end close generates the 4.14 entry and moves every period of that year
to `closed`.

---

## 8. Multi-currency

- Every organisation has one **base currency**, set at creation, immutable
  once anything is posted.
- Every journal line stores `amount` (transaction currency) **and**
  `base_amount`, plus the `exchange_rate` used.
- The base amount is computed once, at posting, and never recomputed.
- **Realised** gain/loss arises on settlement (4.11).
- **Unrealised** gain/loss arises from revaluing open foreign-currency
  balances at period end, and is reversed at the start of the next period.

---

## 9. Document numbering

Gap-free, per organisation, per document type. `INV-000001`, `BILL-000001`.

Obtained by locking a row in `document_sequences` (`SELECT … FOR UPDATE`)
inside the posting transaction. PostgreSQL sequences are **not** used: they
leave gaps on rollback, and many tax authorities treat a gap in invoice
numbering as evidence of a deleted invoice.

The prefix, padding and reset policy (never / yearly / monthly) are per-type
settings.

---

## 10. Test coverage required

Any change to posting logic ships with tests asserting the resulting journal
lines — account, debit, credit — not merely an HTTP 200.

The `Accounting` suite must cover, at minimum:

- [ ] Balanced entry posts; unbalanced entry is rejected at all three layers
- [ ] A line with both debit and credit is rejected
- [ ] A negative debit or credit is rejected
- [ ] Every posting rule in §4, verified line by line
- [ ] Discounts: line-level, document-level, percentage, fixed
- [ ] Tax: exclusive, inclusive, compound, multi-component, zero-rated, exempt
- [ ] Withholding on both customer receipts and vendor payments
- [ ] Rounding: the pathological cases that do not sum cleanly
- [ ] Multi-currency: posting, settlement, realised and unrealised FX
- [ ] Reversal restores every affected account balance exactly
- [ ] Posting into a closed period is refused without the permission
- [ ] Cross-organisation posting is impossible
- [ ] Idempotency: the same source cannot post twice
- [ ] Gap-free numbering survives concurrent posting and rollback
- [ ] `verify-ledger` catches a deliberately corrupted balance
