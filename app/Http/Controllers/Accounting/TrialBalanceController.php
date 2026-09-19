<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Reports\Services\LedgerBalances;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        private readonly LedgerBalances $balances,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $organization = $this->tenant->organization();

        $asOf = $this->date($request->query('as_of')) ?? Carbon::now();
        $includeZero = $request->boolean('zero');

        /*
         * Read through the same service every report uses.
         *
         * It was its own query once, and that is how it came to include
         * entries dated AFTER the as-at date: filtering the entry in a join
         * condition leaves the line in the sum. A trial balance quietly wrong
         * for every historical date is the worst possible place for that bug,
         * since it is the screen people open to check whether anything else
         * is wrong.
         */
        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();
        $lines = [];

        foreach ($this->balances->asAt($asOf, includeZero: true) as $balance) {
            $net = $balance->net();

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

            $lines[] = [
                'id' => $balance->accountId,
                'code' => $balance->code,
                'name' => $balance->name,
                'type' => $balance->type->value,
                'type_label' => $balance->type->label(),
                'order' => $balance->type->order(),
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
