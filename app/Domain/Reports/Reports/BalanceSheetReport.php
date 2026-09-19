<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Data\AccountBalance;
use App\Domain\Reports\Data\ReportColumn;
use App\Domain\Reports\Data\ReportPeriod;
use App\Domain\Reports\Data\ReportRow;
use App\Domain\Reports\Data\ReportSection;
use App\Domain\Reports\Data\ReportTable;
use App\Domain\Reports\Enums\AccountGroup;
use App\Domain\Reports\Services\LedgerBalances;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The balance sheet as at a date.
 *
 * The figure that makes this statement work before year-end is **profit for
 * the year to date**, computed from the profit and loss and carried into
 * equity. Without it the sheet would not balance on any day but the last one
 * of a closed year, because until the year is closed the period's earnings
 * sit in the income and expense accounts rather than in equity.
 *
 * It is computed by {@see ProfitAndLossReport::profitFor()} rather than
 * re-derived here, so the two statements cannot disagree about what the year
 * has earned. After a year-end close, that figure is zero for the closed year
 * and the amount sits in retained earnings instead — the same total, in the
 * place the close put it.
 *
 * The bottom line is not "assets = liabilities + equity" asserted; it is
 * computed and shown. If the two sides differ, the statement says so, loudly,
 * because a balance sheet that quietly does not balance is the single most
 * misleading document this system could produce.
 *
 * @see ACCOUNTING_RULES.md I11
 */
final readonly class BalanceSheetReport
{
    public function __construct(
        private TenantContext $tenant,
        private LedgerBalances $balances,
        private ProfitAndLossReport $profitAndLoss,
    ) {}

    public function build(
        Carbon $asOf,
        ?Carbon $comparisonAsOf = null,
        bool $includeZero = false,
    ): ReportTable {
        $organization = $this->tenant->organization();

        $current = $this->grouped($this->balances->asAt($asOf, $includeZero));
        $prior = $comparisonAsOf === null
            ? []
            : $this->grouped($this->balances->asAt($comparisonAsOf, $includeZero));

        $period = new ReportPeriod($asOf->copy()->startOfDay(), $asOf->copy()->endOfDay(), 'As at '.$asOf->format('j M Y'));
        $comparison = $comparisonAsOf === null
            ? null
            : new ReportPeriod(
                $comparisonAsOf->copy()->startOfDay(),
                $comparisonAsOf->copy()->endOfDay(),
                'As at '.$comparisonAsOf->format('j M Y'),
            );

        $columns = [ReportColumn::text('account', 'Account'), ReportColumn::money('amount', $period->label)];

        if ($comparison !== null) {
            $columns[] = ReportColumn::money('comparison', $comparison->label);
            $columns[] = ReportColumn::money('change', 'Change');
        }

        $earnings = $this->profitAndLoss->profitFor(
            ReportPeriod::fiscalYearToDate($organization, $asOf),
        );

        $priorEarnings = $comparisonAsOf === null
            ? BigDecimal::zero()
            : $this->profitAndLoss->profitFor(
                ReportPeriod::fiscalYearToDate($organization, $comparisonAsOf),
            );

        $sections = [];
        $totals = [];

        foreach ([
            AccountGroup::Cash,
            AccountGroup::Receivable,
            AccountGroup::Inventory,
            AccountGroup::OtherCurrentAsset,
            AccountGroup::FixedAsset,
            AccountGroup::Payable,
            AccountGroup::OtherCurrentLiability,
            AccountGroup::LongTermLiability,
            AccountGroup::Equity,
        ] as $group) {
            [$section, $total, $priorTotal] = $this->section(
                $group,
                $current[$group->value] ?? [],
                $prior[$group->value] ?? [],
                $asOf,
                $comparisonAsOf,
                $earnings,
                $priorEarnings,
                $comparison,
            );

            $totals[$group->value] = ['current' => $total, 'prior' => $priorTotal];

            if ($section !== null) {
                $sections[] = $section;
            }
        }

        $assets = fn (string $which): BigDecimal => $this->sumGroups([
            AccountGroup::Cash,
            AccountGroup::Receivable,
            AccountGroup::Inventory,
            AccountGroup::OtherCurrentAsset,
            AccountGroup::FixedAsset,
        ], $totals, $which);

        $liabilities = fn (string $which): BigDecimal => $this->sumGroups([
            AccountGroup::Payable,
            AccountGroup::OtherCurrentLiability,
            AccountGroup::LongTermLiability,
        ], $totals, $which);

        $equity = fn (string $which): BigDecimal => $totals[AccountGroup::Equity->value][$which]
            ?? BigDecimal::zero();

        $difference = $assets('current')->minus($liabilities('current')->plus($equity('current')));

        $footer = [
            ReportRow::total('Total assets', $this->values($assets('current'), $assets('prior'), $comparison)),
            ReportRow::subtotal('Total liabilities', $this->values($liabilities('current'), $liabilities('prior'), $comparison)),
            ReportRow::subtotal('Total equity', $this->values($equity('current'), $equity('prior'), $comparison)),
            ReportRow::total(
                'Total liabilities and equity',
                $this->values(
                    $liabilities('current')->plus($equity('current')),
                    $liabilities('prior')->plus($equity('prior')),
                    $comparison,
                ),
            ),
        ];

        $balances = $difference->isZero();

        return new ReportTable(
            title: 'Balance sheet',
            currency: $organization->base_currency,
            period: $period,
            columns: $columns,
            sections: $sections,
            footer: $footer,
            comparison: $comparison,
            subtitle: $period->label,
            reconciles: $balances,
            reconciliation: $balances
                ? 'Assets equal liabilities plus equity, to the cent.'
                : sprintf(
                    'This balance sheet is out by %s. Something is wrong in the ledger rather '.
                    'than in this report: run `my-books:verify-ledger` before relying on any '.
                    'figure here.',
                    (string) $difference->toScale(4, RoundingMode::HalfUp),
                ),
            notes: [
                'earnings' => sprintf(
                    'Equity includes %s of profit for the financial year to date, which is the '.
                    'same figure the profit and loss reports. It moves to retained earnings at '.
                    'year-end close.',
                    (string) $earnings->toScale(4, RoundingMode::HalfUp),
                ),
            ],
        );
    }

    /**
     * @param  list<AccountGroup>  $groups
     * @param  array<string, array{current: BigDecimal, prior: BigDecimal}>  $totals
     */
    private function sumGroups(array $groups, array $totals, string $which): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($groups as $group) {
            $sum = $sum->plus($totals[$group->value][$which] ?? BigDecimal::zero());
        }

        return $sum;
    }

    /**
     * @param  list<AccountBalance>  $balances
     * @return array<string, list<AccountBalance>>
     */
    private function grouped(array $balances): array
    {
        $grouped = [];

        foreach ($balances as $balance) {
            $group = $balance->group();

            // Income and expense accounts are not on a balance sheet: what
            // they hold is this year's profit, which arrives as one line in
            // equity instead.
            if ($group->isProfitAndLoss()) {
                continue;
            }

            $grouped[$group->value][] = $balance;
        }

        return $grouped;
    }

    /**
     * @param  list<AccountBalance>  $current
     * @param  list<AccountBalance>  $prior
     * @return array{0: ?ReportSection, 1: BigDecimal, 2: BigDecimal}
     */
    private function section(
        AccountGroup $group,
        array $current,
        array $prior,
        Carbon $asOf,
        ?Carbon $comparisonAsOf,
        BigDecimal $earnings,
        BigDecimal $priorEarnings,
        ?ReportPeriod $comparison,
    ): array {
        $priorById = [];

        foreach ($prior as $balance) {
            $priorById[$balance->accountId] = $balance->forStatement();
        }

        $rows = [];
        $total = BigDecimal::zero();
        $priorTotal = BigDecimal::zero();

        foreach ($current as $balance) {
            $amount = $balance->forStatement();
            $was = $priorById[$balance->accountId] ?? BigDecimal::zero();

            $total = $total->plus($amount);
            $priorTotal = $priorTotal->plus($was);

            $rows[] = ReportRow::account(
                code: $balance->code,
                name: $balance->name,
                values: $this->values($amount, $was, $comparison),
                drill: [
                    'account_id' => $balance->accountId,
                    // No lower bound: a balance sheet figure is everything
                    // that ever happened on the account, so the drill-through
                    // has to be too.
                    'from' => null,
                    'to' => $asOf->toDateString(),
                ],
            );
        }

        /*
         * This year's earnings, inside equity and labelled as what it is.
         * A reader who cannot see it has no way to tell a balanced sheet from
         * one that happens to add up.
         */
        if ($group === AccountGroup::Equity) {
            $total = $total->plus($earnings);
            $priorTotal = $priorTotal->plus($priorEarnings);

            $rows[] = ReportRow::account(
                code: 'zzzz',
                name: 'Profit for the financial year to date',
                values: $this->values($earnings, $priorEarnings, $comparison),
            );
        }

        if ($rows === []) {
            return [null, $total, $priorTotal];
        }

        usort($rows, static fn (ReportRow $a, ReportRow $b): int => ($a->code ?? '') <=> ($b->code ?? ''));

        return [
            new ReportSection(
                label: $group->label(),
                rows: $rows,
                total: ReportRow::subtotal(
                    'Total '.mb_strtolower($group->label()),
                    $this->values($total, $priorTotal, $comparison),
                ),
            ),
            $total,
            $priorTotal,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function values(BigDecimal $amount, BigDecimal $prior, ?ReportPeriod $comparison): array
    {
        $values = ['amount' => (string) $amount->toScale(4, RoundingMode::HalfUp)];

        if ($comparison !== null) {
            $values['comparison'] = (string) $prior->toScale(4, RoundingMode::HalfUp);
            $values['change'] = (string) $amount->minus($prior)->toScale(4, RoundingMode::HalfUp);
        }

        return $values;
    }
}
