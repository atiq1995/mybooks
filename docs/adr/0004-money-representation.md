# 0004 — Money representation

**Status:** Accepted · 2026-09-05

## Context

Binary floating point cannot represent 0.1. An accounting system that lets a
`float` anywhere near money will eventually report a balance that is wrong by
a fraction of a unit, and nobody will notice for months.

## Decision

| Layer      | Representation                                                                        |
| ---------- | ------------------------------------------------------------------------------------- |
| PostgreSQL | `numeric(19,4)` money · `numeric(19,6)` quantity · `numeric(19,10)` rates             |
| PHP        | `Brick\Money\Money`, `Brick\Math\BigDecimal` for intermediates; GMP backend           |
| Wire       | Decimal **strings** in JSON                                                           |
| TypeScript | `string`; formatted with `Intl.NumberFormat`, never parsed for arithmetic             |

`MoneyCast` reads a numeric column with its sibling currency column, refuses
floats with an exception, and refuses a currency mismatch. ESLint forbids
`parseFloat` in the frontend.

Storage scale is four decimal places — more than most currencies present — so
unit prices, tax components and FX conversions survive without being rounded
on the way into the database. Presentation rounds; storage does not.

## Rejected

- _Integer minor units._ Breaks on six-decimal unit prices and on tax rates.
- _PHP `float`._ No.
- _bcmath strings by hand._ Correct but error-prone; Brick provides currency
  awareness, rounding modes and allocation.

## Consequences

- Every money column needs a currency column beside it. An amount without a
  currency is not money.
- Rounding happens at documented boundaries only (`ACCOUNTING_RULES.md` §2),
  with a rounding-difference account for residuals.
- `MoneyCastTest` proves each of these rules; a change that breaks one fails
  the Unit suite.
