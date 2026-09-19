<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Reports\Data\AccountBalance;
use App\Domain\Reports\Data\ReportPeriod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a report reads the ledger.
 *
 * Every figure on every statement comes through here, and that is deliberate.
 * Three reports each writing their own aggregate query is three chances to
 * filter differently — one forgetting reversed entries, one including
 * headings, one rounding at a different point — and the resulting statements
 * disagree in ways nobody can trace.
 *
 * Three rules, applied identically everywhere:
 *
 *   **Base currency only.** `debit_base` / `credit_base`, never the
 *   transaction amounts. A statement in mixed currencies adds up to nothing.
 *
 *   **Posted entries only.** A reversed entry and its reversal both remain in
 *   the ledger and both count: the reversal is what corrects the figure, and
 *   excluding the original would double the correction.
 *
 *   **Never a heading.** Headings hold no postings, and including them would
 *   double every subtotal they group.
 */
final readonly class LedgerBalances
{
    public function __construct(
        private TenantContext $tenant,
    ) {}

    /**
     * Movement over a span — the profit and loss view.
     *
     * @return list<AccountBalance>
     */
    public function movements(ReportPeriod $period, bool $includeZero = false): array
    {
        return $this->query($period->fromDate(), $period->toDate(), $includeZero);
    }

    /**
     * Cumulative position at a date — the balance sheet view.
     *
     * No lower bound, because a balance sheet is everything that ever
     * happened up to the date, including the opening entries.
     *
     * @return list<AccountBalance>
     */
    public function asAt(Carbon $asOf, bool $includeZero = false): array
    {
        return $this->query(null, $asOf->toDateString(), $includeZero);
    }

    /**
     * Balances keyed by account id, for arithmetic rather than display.
     *
     * @param  list<AccountBalance>  $balances
     * @return array<string, AccountBalance>
     */
    public static function keyById(array $balances): array
    {
        $keyed = [];

        foreach ($balances as $balance) {
            $keyed[$balance->accountId] = $balance;
        }

        return $keyed;
    }

    /**
     * @return list<AccountBalance>
     */
    private function query(?string $from, string $to, bool $includeZero): array
    {
        /*
         * The movement is aggregated in a DERIVED TABLE, then joined to the
         * chart — not filtered in the join condition of a chain of left
         * joins.
         *
         * The difference is not stylistic. Left-joining `journal_lines` and
         * then filtering `journal_entries` by date leaves every line in the
         * result whatever its entry's date, because the sum is over the LINE
         * table and only the ENTRY was filtered away. Every figure "as at" a
         * past date silently includes everything posted after it, and the
         * error grows the further back you look — so it is invisible on
         * today's numbers and wrong on every historical one.
         */
        $organizationId = $this->tenant->organization()->getKey();

        $movements = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            /*
             * Scoped explicitly, because a query builder is not an Eloquent
             * model: the global scope that keeps every other read inside one
             * organisation does not apply here. Row-level security still sits
             * underneath in production, but a report that depends on the
             * second layer alone is one connection-role change away from
             * showing another company's figures.
             */
            ->where('jl.organization_id', $organizationId)
            ->where('je.entry_date', '<=', $to)
            ->when($from !== null, fn (Builder $query) => $query->where('je.entry_date', '>=', $from))
            ->groupBy('jl.account_id')
            ->select('jl.account_id')
            ->selectRaw('SUM(jl.debit_base) AS debit')
            ->selectRaw('SUM(jl.credit_base) AS credit');

        $rows = DB::table('accounts')
            ->leftJoinSub($movements, 'm', fn (JoinClause $join) => $join
                ->on('m.account_id', '=', 'accounts.id'))
            ->where('accounts.organization_id', $organizationId)
            ->where('accounts.is_header', false)
            ->orderBy('accounts.code')
            ->select([
                'accounts.id',
                'accounts.code',
                'accounts.name',
                'accounts.type',
                'accounts.subtype',
                'accounts.normal_balance',
            ])
            ->selectRaw('COALESCE(m.debit, 0) AS debit')
            ->selectRaw('COALESCE(m.credit, 0) AS credit')
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            /** @var object{id: string, code: string, name: string, type: string, subtype: ?string, normal_balance: string, debit: string, credit: string} $row */
            $balance = new AccountBalance(
                accountId: $row->id,
                code: $row->code,
                name: $row->name,
                type: AccountType::from($row->type),
                subtype: $row->subtype,
                normalBalance: NormalBalance::from($row->normal_balance),
                debit: (string) $row->debit,
                credit: (string) $row->credit,
            );

            if (! $includeZero && $balance->isZero()) {
                continue;
            }

            $balances[] = $balance;
        }

        return $balances;
    }

    /**
     * The journal lines behind one figure — the drill-through query.
     *
     * Deliberately the same filters the aggregate uses, expressed once. A
     * drill-through that selects rows by different rules than the total is
     * worse than none: it looks like proof and is not.
     */
    public function linesFor(string $accountId, ?string $from, string $to): Builder
    {
        return DB::table('journal_lines')
            ->where('journal_lines.organization_id', $this->tenant->organization()->getKey())
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.entry_date', '<=', $to)
            ->when($from !== null, fn (Builder $query) => $query
                ->where('journal_entries.entry_date', '>=', $from));
    }
}
