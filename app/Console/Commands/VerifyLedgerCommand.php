<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
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
        ];
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
