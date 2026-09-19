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

/**
 * Profit and loss for a span.
 *
 * Built as the statement reads, with the subtotals a reader actually looks
 * for rather than one flat list of accounts:
 *
 *     Revenue
 *     less returns and discounts      → Net revenue
 *     less cost of sales              → Gross profit
 *     less operating expenses         → Operating profit
 *     plus other income, less other   → Profit for the period
 *
 * Contra-revenue is shown as a DEDUCTION rather than netted into revenue
 * silently. Gross sales and what was given back are different facts, and a
 * business whose discounts are growing needs to see that happening.
 *
 * Every account row carries the filter that reproduces it, so the figure and
 * its drill-through can never be summed over different bounds.
 */
final readonly class ProfitAndLossReport
{
    public function __construct(
        private TenantContext $tenant,
        private LedgerBalances $balances,
    ) {}

    public function build(
        ReportPeriod $period,
        ?ReportPeriod $comparison = null,
        bool $includeZero = false,
    ): ReportTable {
        $organization = $this->tenant->organization();

        $current = $this->grouped($this->balances->movements($period, $includeZero));
        $prior = $comparison === null
            ? []
            : $this->grouped($this->balances->movements($comparison, $includeZero));

        $columns = [ReportColumn::text('account', 'Account'), ReportColumn::money('amount', $period->label)];

        if ($comparison !== null) {
            $columns[] = ReportColumn::money('comparison', $comparison->label);
            $columns[] = ReportColumn::money('change', 'Change');
        }

        $sections = [];
        $totals = [];

        foreach ([
            AccountGroup::Revenue,
            AccountGroup::ContraRevenue,
            AccountGroup::CostOfSales,
            AccountGroup::OperatingExpense,
            AccountGroup::OtherIncome,
            AccountGroup::OtherExpense,
        ] as $group) {
            [$section, $total, $priorTotal] = $this->section(
                $group,
                $current[$group->value] ?? [],
                $prior[$group->value] ?? [],
                $period,
                $comparison,
            );

            $totals[$group->value] = ['current' => $total, 'prior' => $priorTotal];

            if ($section !== null) {
                $sections[] = $section;
            }
        }

        $figure = fn (string $group, string $which): BigDecimal => $totals[$group][$which]
            ?? BigDecimal::zero();

        $netRevenue = fn (string $which): BigDecimal => $figure(AccountGroup::Revenue->value, $which)
            ->minus($figure(AccountGroup::ContraRevenue->value, $which));

        $grossProfit = fn (string $which): BigDecimal => $netRevenue($which)
            ->minus($figure(AccountGroup::CostOfSales->value, $which));

        $operatingProfit = fn (string $which): BigDecimal => $grossProfit($which)
            ->minus($figure(AccountGroup::OperatingExpense->value, $which));

        $netProfit = fn (string $which): BigDecimal => $operatingProfit($which)
            ->plus($figure(AccountGroup::OtherIncome->value, $which))
            ->minus($figure(AccountGroup::OtherExpense->value, $which));

        $footer = [
            ReportRow::subtotal('Net revenue', $this->values($netRevenue('current'), $netRevenue('prior'), $comparison)),
            ReportRow::total('Gross profit', $this->values($grossProfit('current'), $grossProfit('prior'), $comparison)),
            ReportRow::subtotal('Operating profit', $this->values($operatingProfit('current'), $operatingProfit('prior'), $comparison)),
            ReportRow::total('Profit for the period', $this->values($netProfit('current'), $netProfit('prior'), $comparison)),
        ];

        $margin = $netRevenue('current')->isZero()
            ? null
            : $netProfit('current')
                ->multipliedBy(100)
                ->dividedBy($netRevenue('current'), 1, RoundingMode::HalfUp);

        return new ReportTable(
            title: 'Profit and loss',
            currency: $organization->base_currency,
            period: $period,
            columns: $columns,
            sections: $sections,
            footer: $footer,
            comparison: $comparison,
            subtitle: $period->label,
            reconciles: true,
            reconciliation: sprintf(
                'Profit for the period is revenue less every cost recorded between %s and %s. '.
                'It is the same figure the balance sheet carries into equity.',
                $period->from->format('j M Y'),
                $period->to->format('j M Y'),
            ),
            notes: $margin === null ? [] : [
                'margin' => "Net margin {$margin}% of net revenue.",
            ],
        );
    }

    /**
     * The profit figure alone, for the balance sheet and the cash flow.
     *
     * The same arithmetic as the statement, called from one place, so the
     * three can never disagree about what the period earned.
     */
    public function profitFor(ReportPeriod $period): BigDecimal
    {
        $profit = BigDecimal::zero();

        foreach ($this->balances->movements($period) as $balance) {
            $group = $balance->group();

            if (! $group->isProfitAndLoss()) {
                continue;
            }

            $profit = match ($group) {
                AccountGroup::Revenue, AccountGroup::OtherIncome => $profit->plus($balance->signed()),
                default => $profit->minus($balance->signed()),
            };
        }

        return $profit;
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

            if (! $group->isProfitAndLoss()) {
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
        ReportPeriod $period,
        ?ReportPeriod $comparison,
    ): array {
        $priorById = [];

        foreach ($prior as $balance) {
            $priorById[$balance->accountId] = $balance->signed();
        }

        $rows = [];
        $total = BigDecimal::zero();
        $priorTotal = BigDecimal::zero();

        foreach ($current as $balance) {
            $amount = $balance->signed();
            $was = $priorById[$balance->accountId] ?? BigDecimal::zero();

            unset($priorById[$balance->accountId]);

            $total = $total->plus($amount);
            $priorTotal = $priorTotal->plus($was);

            $rows[] = ReportRow::account(
                code: $balance->code,
                name: $balance->name,
                values: $this->values($amount, $was, $comparison),
                drill: [
                    'account_id' => $balance->accountId,
                    'from' => $period->fromDate(),
                    'to' => $period->toDate(),
                ],
            );
        }

        /*
         * Accounts that had activity in the comparison period but none in
         * this one. Dropping them would hide exactly the change the
         * comparison exists to show — a cost that stopped is news.
         */
        foreach ($prior as $balance) {
            if (! array_key_exists($balance->accountId, $priorById)) {
                continue;
            }

            $was = $priorById[$balance->accountId];
            $priorTotal = $priorTotal->plus($was);

            $rows[] = ReportRow::account(
                code: $balance->code,
                name: $balance->name,
                values: $this->values(BigDecimal::zero(), $was, $comparison),
                drill: [
                    'account_id' => $balance->accountId,
                    'from' => $period->fromDate(),
                    'to' => $period->toDate(),
                ],
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
