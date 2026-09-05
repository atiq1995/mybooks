# Accounting suite

Ledger invariants. Runs **serially** (`composer test:accounting`) because these
tests assert database-wide state — the trial balance, control-account
reconciliations — that parallel workers would race.

Arrives with the ledger in Phase 2. The required coverage is listed in
`ACCOUNTING_RULES.md` §10; every item there becomes a test here.
