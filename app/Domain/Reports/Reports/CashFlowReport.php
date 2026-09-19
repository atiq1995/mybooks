<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Reports\Data\AccountBalance;
use App\Domain\Reports\Data\ReportColumn;
use App\Domain\Reports\Data\ReportPeriod;
use App\Domain\Reports\Data\ReportRow;
use App\Domain\Reports\Data\ReportSection;
use App\Domain\Reports\Data\ReportTable;
use App\Domain\Reports\Enums\AccountGroup;
use App\Domain\Reports\Enums\CashFlowSection;
use App\Domain\Reports\Services\LedgerBalances;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Cash flow, indirect method — and it reconciles by construction.
 *
 * The statement is not assembled from a list of rules about which
 * transactions are "cash". It falls out of the ledger identity, which is why
 * it cannot drift:
 *
 *     every movement nets to zero, so
 *     Δcash  =  profit  −  Σ(net movement of every other non-P&L account)
 *
 * So each adjustment line is simply the NEGATIVE of an account's net
 * movement. A receivable that grew is a debit, so it subtracts — profit
 * earned but not collected. A payable that grew is a credit, so it adds —
 * cost incurred but not yet paid. Depreciation credited to accumulated
 * depreciation adds back, because no cash left. None of that is a special
 * case in this class; it is the same subtraction applied to every account,
 * and the sections only decide where each line is PRINTED.
 *
 * The one presentational departure: accumulated depreciation is a contra
 * asset sitting in non-current assets, but its movement is a non-cash charge
 * rather than an investment, so it is shown as an operating adjustment where
 * a reader expects it. The arithmetic is untouched — the same figure, in the
 * section it belongs to.
 *
 * The check at the bottom is real: the net change the statement arrives at is
 * compared against the actual movement on the cash and bank accounts, and a
 * difference is reported rather than hidden.
 */
final readonly class CashFlowReport
{
    public function __construct(
        private TenantContext $tenant,
        private LedgerBalances $balances,
        private ProfitAndLossReport $profitAndLoss,
    ) {}

    public function build(ReportPeriod $period, bool $includeZero = false): ReportTable
    {
        $organization = $this->tenant->organization();

        $movements = $this->balances->movements($period, $includeZero);
        $profit = $this->profitAndLoss->profitFor($period);

        $columns = [
            ReportColumn::text('account', ''),
            ReportColumn::money('amount', $period->label),
        ];

        /** @var array<string, list<ReportRow>> $rows */
        $rows = [
            CashFlowSection::Operating->value => [],
            CashFlowSection::Investing->value => [],
            CashFlowSection::Financing->value => [],
        ];

        /** @var array<string, BigDecimal> $sectionTotals */
        $sectionTotals = [
            CashFlowSection::Operating->value => $profit,
            CashFlowSection::Investing->value => BigDecimal::zero(),
            CashFlowSection::Financing->value => BigDecimal::zero(),
        ];

        $cashMovement = BigDecimal::zero();

        foreach ($movements as $balance) {
            $group = $balance->group();

            if ($group->isProfitAndLoss()) {
                // Already inside the profit figure the statement starts from.
                continue;
            }

            if ($group === AccountGroup::Cash) {
                $cashMovement = $cashMovement->plus($balance->net());

                continue;
            }

            /*
             * The whole of the arithmetic: the negative of the net movement.
             * An asset that grew consumed cash; a liability that grew
             * provided it.
             */
            $effect = $balance->net()->negated();

            if ($effect->isZero()) {
                continue;
            }

            $section = $this->sectionFor($balance);

            $sectionTotals[$section->value] = $sectionTotals[$section->value]->plus($effect);

            $rows[$section->value][] = ReportRow::account(
                code: $balance->code,
                name: $this->describe($balance, $effect),
                values: ['amount' => (string) $effect->toScale(4, RoundingMode::HalfUp)],
                drill: [
                    'account_id' => $balance->accountId,
                    'from' => $period->fromDate(),
                    'to' => $period->toDate(),
                ],
            );
        }

        // The profit the statement starts from, shown rather than assumed.
        array_unshift(
            $rows[CashFlowSection::Operating->value],
            ReportRow::account(
                code: '0000',
                name: 'Profit for the period',
                values: ['amount' => (string) $profit->toScale(4, RoundingMode::HalfUp)],
            ),
        );

        $sections = [];

        foreach ([CashFlowSection::Operating, CashFlowSection::Investing, CashFlowSection::Financing] as $section) {
            $sectionRows = $rows[$section->value];

            if ($sectionRows === []) {
                continue;
            }

            usort(
                $sectionRows,
                static fn (ReportRow $a, ReportRow $b): int => ($a->code ?? '') <=> ($b->code ?? ''),
            );

            $sections[] = new ReportSection(
                label: $section->label(),
                rows: $sectionRows,
                total: ReportRow::subtotal(
                    'Net cash from '.mb_strtolower($section->label()),
                    ['amount' => (string) $sectionTotals[$section->value]->toScale(4, RoundingMode::HalfUp)],
                ),
            );
        }

        $netChange = BigDecimal::zero();

        foreach ($sectionTotals as $total) {
            $netChange = $netChange->plus($total);
        }

        $opening = $this->cashAt($period->from->copy()->subDay()->toDateString());
        $closing = $this->cashAt($period->toDate());

        $difference = $netChange->minus($cashMovement);
        $reconciles = $difference->isZero();

        $footer = [
            ReportRow::total('Net change in cash', [
                'amount' => (string) $netChange->toScale(4, RoundingMode::HalfUp),
            ]),
            ReportRow::subtotal('Cash and bank at the start', [
                'amount' => (string) $opening->toScale(4, RoundingMode::HalfUp),
            ]),
            ReportRow::total('Cash and bank at the end', [
                'amount' => (string) $closing->toScale(4, RoundingMode::HalfUp),
            ]),
        ];

        return new ReportTable(
            title: 'Cash flow',
            currency: $organization->base_currency,
            period: $period,
            columns: $columns,
            sections: $sections,
            footer: $footer,
            subtitle: $period->label,
            reconciles: $reconciles,
            reconciliation: $reconciles
                ? 'The net change above equals the movement on the cash and bank accounts, to the cent.'
                : sprintf(
                    'This statement is out by %s against the movement on the cash and bank '.
                    'accounts. That is a ledger problem rather than a reporting one: run '.
                    '`my-books:verify-ledger`.',
                    (string) $difference->toScale(4, RoundingMode::HalfUp),
                ),
            notes: [
                'method' => 'Indirect method: profit, adjusted for everything that changed '.
                    'without cash moving, and for cash that moved without touching profit.',
            ],
        );
    }

    /**
     * Where a line is printed.
     *
     * Accumulated depreciation is the one account whose group and section
     * differ. It is a contra asset inside non-current assets, but its
     * movement is a non-cash charge against profit rather than an investment
     * decision, and printing it under investing would tell a reader the
     * business had spent money it did not spend.
     */
    private function sectionFor(AccountBalance $balance): CashFlowSection
    {
        $group = $balance->group();

        if ($group === AccountGroup::FixedAsset && $balance->normalBalance === NormalBalance::Credit) {
            return CashFlowSection::Operating;
        }

        $section = $group->cashFlowSection();

        // Nothing should reach here classified as profit or cash — both are
        // handled before this is called — so anything that does is operating,
        // which keeps it visible rather than dropping it.
        return in_array($section, [CashFlowSection::Profit, CashFlowSection::Cash], true)
            ? CashFlowSection::Operating
            : $section;
    }

    /**
     * A line named for what actually happened to it.
     *
     * "Receivables" tells the reader nothing; "Increase in receivables" with
     * a negative figure beside it tells them that a profitable month has not
     * been collected yet, which is the question a cash flow exists to answer.
     */
    private function describe(AccountBalance $balance, BigDecimal $effect): string
    {
        $group = $balance->group();

        if ($group === AccountGroup::FixedAsset && $balance->normalBalance === NormalBalance::Credit) {
            return 'Depreciation charged — '.$balance->name;
        }

        $grew = $balance->net()->isPositive();

        return match ($group) {
            AccountGroup::Receivable,
            AccountGroup::Inventory,
            AccountGroup::OtherCurrentAsset => ($grew ? 'Increase in ' : 'Decrease in ').$balance->name,
            AccountGroup::Payable,
            AccountGroup::OtherCurrentLiability,
            AccountGroup::LongTermLiability => ($effect->isPositive() ? 'Increase in ' : 'Decrease in ').$balance->name,
            AccountGroup::FixedAsset => ($grew ? 'Bought ' : 'Disposed of ').$balance->name,
            AccountGroup::Equity => $balance->name,
            default => $balance->name,
        };
    }

    private function cashAt(string $date): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->balances->asAt(Carbon::parse($date)) as $balance) {
            if ($balance->group() === AccountGroup::Cash) {
                $total = $total->plus($balance->net());
            }
        }

        return $total;
    }
}
