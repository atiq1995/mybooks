<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The trial balance: every account's debit and credit totals as at a date,
 * and the one number that says whether the books are sound.
 *
 * The totals row is the point of this screen. If debits do not equal credits
 * the balance sheet cannot balance, so the difference is shown prominently
 * rather than left for the reader to subtract — and the page says plainly
 * that something is wrong, because a silently misstated trial balance is the
 * single most expensive state this system can reach.
 *
 * @see ACCOUNTING_RULES.md I11, §6
 */
final class TrialBalanceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $organization = $this->tenant->organization();

        $asOf = $this->date($request->query('as_of')) ?? Carbon::now();
        $includeZero = $request->boolean('zero');

        $rows = DB::table('accounts')
            ->leftJoin('journal_lines', 'journal_lines.account_id', '=', 'accounts.id')
            ->leftJoin('journal_entries', function (JoinClause $join) use ($asOf): void {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString());
            })
            // Headings hold no postings; including them would double every
            // subtotal they group.
            ->where('accounts.is_header', false)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->select([
                'accounts.id',
                'accounts.code',
                'accounts.name',
                'accounts.type',
            ])
            ->selectRaw('COALESCE(SUM(journal_lines.debit_base), 0) AS debits')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_base), 0) AS credits')
            ->get();

        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();
        $lines = [];

        foreach ($rows as $row) {
            /** @var object{id: string, code: string, name: string, type: string, debits: string, credits: string} $row */
            $debits = BigDecimal::of((string) $row->debits);
            $credits = BigDecimal::of((string) $row->credits);
            $net = $debits->minus($credits);

            /*
             * A trial balance shows each account on ONE side: its net
             * position. Showing both columns for the same account would make
             * the totals meaningless, since every posting appears twice in
             * the ledger by construction.
             */
            $debit = $net->isPositive() ? $net : BigDecimal::zero();
            $credit = $net->isNegative() ? $net->negated() : BigDecimal::zero();

            if (! $includeZero && $net->isZero()) {
                continue;
            }

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);

            $type = AccountType::from($row->type);

            $lines[] = [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $type->value,
                'type_label' => $type->label(),
                'order' => $type->order(),
                'debit' => (string) $debit,
                'credit' => (string) $credit,
            ];
        }

        $difference = $totalDebit->minus($totalCredit);

        return Inertia::render('Accounting/TrialBalance', [
            'lines' => $lines,
            'totals' => [
                'debit' => (string) $totalDebit,
                'credit' => (string) $totalCredit,
                'difference' => (string) $difference,
                'balances' => $difference->isZero(),
            ],
            'filters' => [
                'as_of' => $asOf->toDateString(),
                'zero' => $includeZero,
            ],
            'baseCurrency' => $organization->base_currency,
        ]);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
