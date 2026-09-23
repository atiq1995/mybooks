<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Re-derives the ledger's invariants from raw journal lines and fails loudly
 * on any discrepancy.
 *
 * This is the THIRD independent enforcement of the balance rule. The domain
 * checks a draft before posting; a deferred constraint trigger checks it at
 * COMMIT; and this checks the whole ledger after the fact. Any one of the
 * three can be defeated by a mistake — a migration that drops a trigger, a
 * direct SQL fix applied at 2am — and the cost of a silently misstated
 * balance is measured in months.
 *
 * Runs nightly, in CI, and after every restore. A partially restored ledger
 * looks entirely normal until somebody runs a balance sheet.
 *
 * Exits non-zero on any discrepancy, so a scheduler or pipeline notices.
 *
 * @see ACCOUNTING_RULES.md §1
 */
final class VerifyLedgerCommand extends Command
{
    protected $signature = 'my-books:verify-ledger
                            {--organization= : Verify one organisation by slug}
                            {--json : Machine-readable output}';

    protected $description = 'Re-derive ledger invariants from journal lines and report any discrepancy';

    public function handle(TenantContext $tenant): int
    {
        /** @var list<array{organization: string, check: string, detail: string}> $problems */
        $problems = [];
        $checked = 0;

        $organizations = $tenant->runUnscoped(function (): array {
            $query = Organization::query()->whereNull('archived_at')->orderBy('name');

            if (is_string($slug = $this->option('organization')) && $slug !== '') {
                $query->where('slug', $slug);
            }

            return $query->get()->all();
        });

        foreach ($organizations as $organization) {
            $checked++;

            $tenant->runAs($organization, function () use ($organization, &$problems): void {
                foreach ($this->checks($organization->id) as $name => $check) {
                    foreach ($check() as $detail) {
                        $problems[] = [
                            'organization' => $organization->name,
                            'check' => $name,
                            'detail' => $detail,
                        ];
                    }
                }
            });
        }

        return $this->report($problems, $checked);
    }

    /**
     * Every check names the organisation explicitly rather than leaning on
     * row-level security to scope it.
     *
     * That matters because this command is the one thing likely to be run by a
     * role that bypasses RLS — an operator investigating a restore, a nightly
     * job on the owner connection. Without the predicate every check would
     * silently examine the whole database and attribute what it found to
     * whichever organisation the loop happened to be on, which is worse than
     * not checking: it sends somebody looking in the wrong books.
     *
     * @return array<string, callable(): list<string>>
     */
    private function checks(string $organizationId): array
    {
        return [
            'entry_balances' => fn (): array => $this->entriesThatDoNotBalance($organizationId),
            'header_matches_lines' => fn (): array => $this->headersThatDisagreeWithLines($organizationId),
            'line_sides' => fn (): array => $this->linesWithBothOrNeitherSide($organizationId),
            'trial_balance' => fn (): array => $this->trialBalanceDoesNotBalance($organizationId),
            'orphan_lines' => fn (): array => $this->linesWithoutAnEntry($organizationId),
            'cross_tenant_lines' => fn (): array => $this->linesPointingAtAnotherOrganisation($organizationId),
            'stock_projection' => fn (): array => $this->stockLevelsThatDisagreeWithMovements($organizationId),
            'stock_pointer' => fn (): array => $this->stockLevelsPointingAtTheWrongMovement($organizationId),
            'stock_valuation' => fn (): array => $this->stockThatDisagreesWithTheLedger($organizationId),
        ];
    }

    /**
     * I10, first half — the projection agrees with the movements behind it.
     *
     * `stock_levels` is a cache of the last movement's running balance, kept
     * because it is the row every writer locks. A cache is a second source of
     * truth, and this is what stops it being one: if the two ever differ,
     * something wrote a level without a movement, and every valuation since
     * is wrong.
     *
     * The level is compared against the SUM of the movements behind it, not
     * against the last one by any ordering.
     *
     * Summing is the only form that holds both ways. Ordering by date reports
     * drift on correct books the moment anything is back-dated, because the
     * latest movement by date is not the one that wrote the level. Following
     * `last_movement_id` fixes that but only proves the level equals the row
     * it points AT — so a level that simply stopped being updated, which is
     * exactly what a half-restored backup looks like, agrees with its stale
     * pointer and passes. Summing catches both, and is back-dating-safe for
     * the same reason the valuation half is: addition does not care about
     * order.
     *
     * The pointer is still checked, separately, because a `last_movement_id`
     * aimed at another item's movement is worth naming on its own.
     *
     * @return list<string>
     */
    private function stockLevelsThatDisagreeWithMovements(string $organizationId): array
    {
        $rows = DB::select(<<<'SQL'
            WITH pairs AS (
                /*
                 * Every (item, warehouse) either side knows about.
                 *
                 * Anchoring on `stock_levels` alone would only ever ask "is
                 * this level right", so movements with no level row at all —
                 * the shape a half-restored backup or a stray insert leaves
                 * behind — were invisible to a check whose whole job is to
                 * notice them.
                 */
                SELECT item_id, warehouse_id
                  FROM stock_levels
                 WHERE organization_id = ?
                 UNION
                SELECT item_id, warehouse_id
                  FROM stock_movements
                 WHERE organization_id = ?
            )
            SELECT i.name AS item,
                   w.code AS warehouse,
                   COALESCE(sl.quantity, 0) AS level_quantity,
                   COALESCE(sl.value, 0)    AS level_value,
                   t.q AS movement_quantity,
                   t.v AS movement_value
              FROM pairs p
              JOIN items i      ON i.id = p.item_id
              JOIN warehouses w ON w.id = p.warehouse_id
              LEFT JOIN stock_levels sl
                     ON sl.organization_id = ?
                    AND sl.item_id         = p.item_id
                    AND sl.warehouse_id    = p.warehouse_id
              LEFT JOIN LATERAL (
                    SELECT COALESCE(SUM(m.quantity), 0) AS q,
                           COALESCE(SUM(m.value), 0)    AS v
                      FROM stock_movements m
                     WHERE m.organization_id = ?
                       AND m.item_id         = p.item_id
                       AND m.warehouse_id    = p.warehouse_id
              ) t ON TRUE
             WHERE COALESCE(sl.quantity, 0) <> t.q
                OR COALESCE(sl.value, 0)    <> t.v
        SQL, [$organizationId, $organizationId, $organizationId, $organizationId]);

        /** @var list<object{item: string, warehouse: string, level_quantity: string, level_value: string, movement_quantity: string, movement_value: string}> $rows */
        return array_values(array_map(
            static fn (object $row): string => sprintf(
                '%s at %s: the level says %s units worth %s, the movements say %s units worth %s',
                $row->item,
                $row->warehouse,
                $row->level_quantity,
                $row->level_value,
                $row->movement_quantity,
                $row->movement_value,
            ),
            $rows,
        ));
    }

    /**
     * Every level's `last_movement_id` points at one of its own movements.
     *
     * Its own check, with its own sentence, because it is a different fault
     * from a level that has drifted: the figures can be perfectly correct
     * while the pointer names another item's row. Reporting it through the
     * drift message would have had to invent movement totals to fill the
     * sentence with, and a diagnostic that states a figure it did not measure
     * is worse than one that says nothing.
     *
     * @return list<string>
     */
    private function stockLevelsPointingAtTheWrongMovement(string $organizationId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT i.name AS item,
                   w.code AS warehouse
              FROM stock_levels sl
              JOIN items i      ON i.id = sl.item_id
              JOIN warehouses w ON w.id = sl.warehouse_id
              LEFT JOIN stock_movements m ON m.id = sl.last_movement_id
             WHERE sl.organization_id = ?
               AND sl.last_movement_id IS NOT NULL
               AND (m.id IS NULL
                 OR m.organization_id <> sl.organization_id
                 OR m.item_id         <> sl.item_id
                 OR m.warehouse_id    <> sl.warehouse_id)
        SQL, [$organizationId]);

        /** @var list<object{item: string, warehouse: string}> $rows */
        return array_values(array_map(
            static fn (object $row): string => sprintf(
                '%s at %s: the level names a last movement that is missing, or belongs to '
                .'another item, warehouse or organisation',
                $row->item,
                $row->warehouse,
            ),
            $rows,
        ));
    }

    /**
     * I10, second half — **stock valuation equals the inventory control
     * account, at every point in time**.
     *
     * Phase 8's exit criterion, and the reason it is phrased that way: a
     * check of today's figures alone passes on books that were wrong for six
     * months and were accidentally corrected. So every date on which either
     * side moved is checked, and the first date they diverge is reported —
     * which is also the date whose documents somebody needs to look at.
     *
     * Per inventory ACCOUNT, because items may be tracked into different
     * ones. And the account balance deliberately does NOT filter on entry
     * status: a reversal and its original both count, exactly as
     * {@see Account::balance()} explains.
     *
     * Two things about how it measures, both of which it got wrong at first
     * and which are the reason this docblock is long:
     *
     * **The stock side sums signed movement values; it does not look up a
     * running balance.** `value_after` is the state after N writes, not after
     * a date, and back-dating is supported — so on any set of books where a
     * document was entered late, the last movement BY DATE is not the last
     * movement written, and its `value_after` is a figure that was never true
     * at that date. `SUM(value) WHERE occurred_on <= d` is order-independent,
     * which is exactly what the ledger side already is, and putting both
     * halves on the same basis is the only way the comparison means anything.
     *
     * **Accounts come from the movements, not from the item master.** An
     * item's `inventory_account_id` can be edited and its `is_tracked` can be
     * cleared. Deriving the account set from `items` therefore let a checkbox
     * on an item form silently switch the invariant off — the account left
     * the set, and drift on it stopped being reported at all — and an account
     * change re-attributed an item's whole history to an account it had never
     * posted to. Every movement now records the account its value went to at
     * the time, and that is what is reconciled.
     *
     * @return list<string>
     */
    private function stockThatDisagreesWithTheLedger(string $organizationId): array
    {
        $rows = DB::select(<<<'SQL'
            WITH movement_accounts AS (
                /*
                 * What the movement itself recorded, falling back to the
                 * item's account where it recorded nothing.
                 *
                 * The recorded value comes first so that editing an item
                 * cannot re-attribute history it was never posted to. The
                 * fallback is what stops a row written around the stock
                 * ledger — an import, a hand-run INSERT — from being invisible
                 * to the check simply because it left the column null.
                 */
                SELECT m.id,
                       m.occurred_on,
                       m.value,
                       COALESCE(m.inventory_account_id, mi.inventory_account_id) AS account_id
                  FROM stock_movements m
                  JOIN items mi ON mi.id = m.item_id
                 WHERE m.organization_id = ?
            ),
            inventory_accounts AS (
                SELECT DISTINCT ma.account_id
                  FROM movement_accounts ma
                 WHERE ma.account_id IS NOT NULL
                 UNION
                SELECT DISTINCT i.inventory_account_id
                  FROM items i
                 WHERE i.organization_id = ?
                   AND i.inventory_account_id IS NOT NULL
            ),
            dates AS (
                SELECT DISTINCT occurred_on AS on_date
                  FROM stock_movements
                 WHERE organization_id = ?
                 UNION
                SELECT DISTINCT e.entry_date
                  FROM journal_lines l
                  JOIN journal_entries e ON e.id = l.journal_entry_id
                  JOIN inventory_accounts ia ON ia.account_id = l.account_id
                 WHERE l.organization_id = ?
            ),
            stock AS (
                SELECT d.on_date,
                       ia.account_id,
                       COALESCE((
                           SELECT SUM(ma.value)
                             FROM movement_accounts ma
                            WHERE ma.account_id = ia.account_id
                              AND ma.occurred_on <= d.on_date
                       ), 0) AS stock_value
                  FROM dates d
                 CROSS JOIN inventory_accounts ia
            ),
            ledger AS (
                SELECT d.on_date,
                       ia.account_id,
                       COALESCE((
                           SELECT SUM(l.debit_base - l.credit_base)
                             FROM journal_lines l
                             JOIN journal_entries e ON e.id = l.journal_entry_id
                            WHERE l.organization_id = ?
                              AND l.account_id = ia.account_id
                              AND e.entry_date <= d.on_date
                       ), 0) AS ledger_value
                  FROM dates d
                 CROSS JOIN inventory_accounts ia
            )
            SELECT s.on_date,
                   a.code AS account_code,
                   a.name AS account_name,
                   s.stock_value,
                   l.ledger_value
              FROM stock s
              JOIN ledger l ON l.on_date = s.on_date AND l.account_id = s.account_id
              JOIN accounts a ON a.id = s.account_id
             WHERE s.stock_value <> l.ledger_value
             ORDER BY s.on_date, a.code
        SQL, [
            $organizationId,
            $organizationId,
            $organizationId,
            $organizationId,
            $organizationId,
        ]);

        /** @var list<object{on_date: string, account_code: string, account_name: string, stock_value: string, ledger_value: string}> $rows */
        return array_values(array_map(
            static fn (object $row): string => sprintf(
                'As at %s, %s %s holds %s but stock is worth %s — out by %s',
                Carbon::parse($row->on_date)->toDateString(),
                $row->account_code,
                $row->account_name,
                $row->ledger_value,
                $row->stock_value,
                (string) BigDecimal::of($row->ledger_value)->minus(BigDecimal::of($row->stock_value)),
            ),
            $rows,
        ));
    }

    /**
     * I1/I2 — every entry balances, in both currencies.
     *
     * @return list<string>
     */
    private function entriesThatDoNotBalance(string $organizationId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT e.entry_no,
                   SUM(l.debit)       AS debits,
                   SUM(l.credit)      AS credits,
                   SUM(l.debit_base)  AS debits_base,
                   SUM(l.credit_base) AS credits_base
              FROM journal_entries e
              JOIN journal_lines l ON l.journal_entry_id = e.id
             WHERE e.organization_id = ?
             GROUP BY e.id, e.entry_no
            HAVING SUM(l.debit) <> SUM(l.credit)
                OR SUM(l.debit_base) <> SUM(l.credit_base)
        SQL, [$organizationId]);

        /** @var list<object{entry_no: string, debits: string, credits: string, debits_base: string, credits_base: string}> $rows */
        return array_values(array_map(
            static fn (object $row): string => sprintf(
                'Entry %s: debits %s vs credits %s (base: %s vs %s)',
                $row->entry_no,
                $row->debits,
                $row->credits,
                $row->debits_base,
                $row->credits_base,
            ),
            $rows,
        ));
    }

    /**
     * The denormalised totals must agree with the lines, or every report that
     * trusts them is quietly wrong.
     *
     * @return list<string>
     */
    private function headersThatDisagreeWithLines(string $organizationId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT e.entry_no, e.total_debit, SUM(l.debit) AS line_debit
              FROM journal_entries e
              JOIN journal_lines l ON l.journal_entry_id = e.id
             WHERE e.organization_id = ?
             GROUP BY e.id, e.entry_no, e.total_debit
            HAVING e.total_debit <> SUM(l.debit)
        SQL, [$organizationId]);

        /** @var list<object{entry_no: string, total_debit: string, line_debit: string}> $rows */
        return array_values(array_map(
            static fn (object $row): string => sprintf(
                'Entry %s: header says %s, lines total %s',
                $row->entry_no,
                $row->total_debit,
                $row->line_debit,
            ),
            $rows,
        ));
    }

    /**
     * I3 — a line is a debit or a credit, never both, never neither.
     *
     * @return list<string>
     */
    private function linesWithBothOrNeitherSide(string $organizationId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT e.entry_no, l.line_no, l.debit, l.credit
              FROM journal_lines l
              JOIN journal_entries e ON e.id = l.journal_entry_id
             WHERE l.organization_id = ?
               AND ((l.debit > 0 AND l.credit > 0)
                OR (l.debit = 0 AND l.credit = 0)
                OR l.debit < 0
                OR l.credit < 0)
        SQL, [$organizationId]);

        /** @var list<object{entry_no: string, line_no: int, debit: string, credit: string}> $rows */
        return array_values(array_map(
            static fn (object $row): string => sprintf(
                'Entry %s line %s: debit %s, credit %s',
                $row->entry_no,
                $row->line_no,
                $row->debit,
                $row->credit,
            ),
            $rows,
        ));
    }

    /**
     * I11 — the whole ledger's debits equal its credits.
     *
     * The single most important number in the system: if this is not zero, the
     * balance sheet does not balance.
     *
     * @return list<string>
     */
    private function trialBalanceDoesNotBalance(string $organizationId): array
    {
        /** @var object{debits: string|null, credits: string|null}|null $totals */
        $totals = DB::selectOne(<<<'SQL'
            SELECT COALESCE(SUM(debit_base), 0) AS debits,
                   COALESCE(SUM(credit_base), 0) AS credits
              FROM journal_lines
             WHERE organization_id = ?
        SQL, [$organizationId]);

        if ($totals === null) {
            return [];
        }

        $debits = BigDecimal::of($totals->debits ?? '0');
        $credits = BigDecimal::of($totals->credits ?? '0');

        if ($debits->isEqualTo($credits)) {
            return [];
        }

        return [sprintf(
            'Trial balance is out by %s (debits %s, credits %s)',
            (string) $debits->minus($credits),
            (string) $debits,
            (string) $credits,
        )];
    }

    /**
     * @return list<string>
     */
    private function linesWithoutAnEntry(string $organizationId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT l.id
              FROM journal_lines l
              LEFT JOIN journal_entries e ON e.id = l.journal_entry_id
             WHERE l.organization_id = ?
               AND e.id IS NULL
        SQL, [$organizationId]);

        /** @var list<object{id: string}> $rows */
        return array_values(array_map(
            static fn (object $row): string => "Orphaned line {$row->id} has no entry",
            $rows,
        ));
    }

    /**
     * A line, its entry and its account must all belong to the same
     * organisation. Foreign keys do not express that on their own.
     *
     * @return list<string>
     */
    private function linesPointingAtAnotherOrganisation(string $organizationId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT e.entry_no, l.line_no
              FROM journal_lines l
              JOIN journal_entries e ON e.id = l.journal_entry_id
              JOIN accounts a ON a.id = l.account_id
             WHERE (e.organization_id = ? OR l.organization_id = ?)
               AND (l.organization_id <> e.organization_id
                OR a.organization_id <> l.organization_id)
        SQL, [$organizationId, $organizationId]);

        /** @var list<object{entry_no: string, line_no: int}> $rows */
        return array_values(array_map(
            static fn (object $row): string => sprintf(
                'Entry %s line %s crosses organisations',
                $row->entry_no,
                $row->line_no,
            ),
            $rows,
        ));
    }

    /**
     * @param  list<array{organization: string, check: string, detail: string}>  $problems
     */
    private function report(array $problems, int $checked): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $problems === [] ? 'ok' : 'discrepancies',
                'organizations_checked' => $checked,
                'problems' => $problems,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($problems === []) {
            $this->info("Ledger verified across {$checked} organisation(s). Every invariant holds.");

            return self::SUCCESS;
        }

        $this->error(count($problems).' discrepancy(ies) found:');

        foreach ($problems as $problem) {
            $this->line("  <fg=red>{$problem['check']}</> [{$problem['organization']}] {$problem['detail']}");
        }

        $this->newLine();
        $this->warn('The ledger is the source of truth. Investigate before relying on any report.');

        return self::FAILURE;
    }
}
