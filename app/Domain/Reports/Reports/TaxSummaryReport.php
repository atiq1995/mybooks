<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Data\ReportColumn;
use App\Domain\Reports\Data\ReportPeriod;
use App\Domain\Reports\Data\ReportRow;
use App\Domain\Reports\Data\ReportSection;
use App\Domain\Reports\Data\ReportTable;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * The tax return, in the shape a tax return is filed.
 *
 * This is the one report that does NOT read the ledger, and the reason is
 * §5: a return is filed per tax COMPONENT, with the taxable amount it was
 * charged on, and the ledger has only the money. Two components posting to
 * the same GST control account are indistinguishable there and must never be
 * merged here, so the per-line tax tables are the source.
 *
 * What counts, and what does not:
 *
 *   Output tax comes from invoices and credit notes that have POSTED. An
 *   estimate is not a tax point; a draft invoice is not a tax point.
 *   A credit note subtracts, because it gave the tax back.
 *
 *   Input tax comes from bills, vendor credits and approved expenses — and
 *   only the CLAIMABLE part. §4.6: tax that cannot be reclaimed was
 *   capitalised into the cost, and showing it here would ask the authority
 *   to refund money that was never a receivable.
 *
 *   Withholding is reported both ways round, because they are different
 *   obligations: what customers withheld from us is ours to reclaim, what we
 *   withheld from vendors is ours to remit.
 *
 * The net figure is what is payable, and the report says so plainly rather
 * than leaving a reader to subtract two subtotals.
 *
 * @see ACCOUNTING_RULES.md §5, §4.6, §4.7
 */
final readonly class TaxSummaryReport
{
    public function __construct(
        private TenantContext $tenant,
    ) {}

    public function build(ReportPeriod $period): ReportTable
    {
        $organization = $this->tenant->organization();

        $columns = [
            ReportColumn::text('component', 'Component'),
            ReportColumn::money('taxable', 'Taxable amount'),
            ReportColumn::money('tax', 'Tax'),
        ];

        $output = $this->outputTax($period);
        $input = $this->inputTax($period);

        $outputSection = $this->section('Output tax — on sales', $output);
        $inputSection = $this->section('Input tax — reclaimable on purchases and expenses', $input);

        $sections = [$outputSection['section'], $inputSection['section']];

        $withholding = $this->withholding($period);

        if ($withholding !== []) {
            $sections[] = new ReportSection(
                label: 'Withholding tax',
                rows: $withholding,
            );
        }

        $net = $outputSection['tax']->minus($inputSection['tax']);

        $footer = [
            ReportRow::subtotal('Total output tax', [
                'component' => null,
                'taxable' => (string) $outputSection['taxable']->toScale(4, RoundingMode::HalfUp),
                'tax' => (string) $outputSection['tax']->toScale(4, RoundingMode::HalfUp),
            ]),
            ReportRow::subtotal('Total input tax', [
                'component' => null,
                'taxable' => (string) $inputSection['taxable']->toScale(4, RoundingMode::HalfUp),
                'tax' => (string) $inputSection['tax']->toScale(4, RoundingMode::HalfUp),
            ]),
            ReportRow::total(
                $net->isNegative() ? 'Reclaimable from the authority' : 'Payable to the authority',
                [
                    'component' => null,
                    'taxable' => null,
                    'tax' => (string) $net->abs()->toScale(4, RoundingMode::HalfUp),
                ],
            ),
        ];

        return new ReportTable(
            title: 'Tax summary',
            currency: $organization->base_currency,
            period: $period,
            columns: $columns,
            sections: $sections,
            footer: $footer,
            subtitle: $period->label,
            reconciles: true,
            reconciliation: 'Output tax less reclaimable input tax, per component, on documents '.
                'that posted in this period. Non-claimable input tax is excluded: it was '.
                'capitalised into the cost when the bill was approved.',
            notes: [
                'basis' => 'Invoice basis — a document is counted on the date it posted, not '.
                    'the date it was paid.',
            ],
        );
    }

    /**
     * A section and its two totals, returned together.
     *
     * @param  list<array{component: string, taxable: BigDecimal, tax: BigDecimal}>  $rows
     * @return array{section: ReportSection, taxable: BigDecimal, tax: BigDecimal}
     */
    private function section(string $label, array $rows): array
    {
        $taxable = BigDecimal::zero();
        $tax = BigDecimal::zero();

        $reportRows = [];

        foreach ($rows as $row) {
            $taxable = $taxable->plus($row['taxable']);
            $tax = $tax->plus($row['tax']);

            $reportRows[] = new ReportRow(
                label: $row['component'],
                values: [
                    'taxable' => (string) $row['taxable']->toScale(4, RoundingMode::HalfUp),
                    'tax' => (string) $row['tax']->toScale(4, RoundingMode::HalfUp),
                ],
                depth: 1,
            );
        }

        if ($reportRows === []) {
            $reportRows[] = new ReportRow(
                label: 'Nothing in this period',
                values: ['taxable' => '0.0000', 'tax' => '0.0000'],
                depth: 1,
            );
        }

        return [
            'section' => new ReportSection(label: $label, rows: $reportRows),
            'taxable' => $taxable,
            'tax' => $tax,
        ];
    }

    /**
     * Invoices add, credit notes subtract.
     *
     * @return list<array{component: string, taxable: BigDecimal, tax: BigDecimal}>
     */
    private function outputTax(ReportPeriod $period): array
    {
        $rows = DB::table('sales_document_line_taxes as t')
            ->join('sales_documents as d', 'd.id', '=', 't.sales_document_id')
            ->where('d.organization_id', $this->tenant->organization()->getKey())
            ->whereNotNull('d.journal_entry_id')
            ->whereIn('d.type', ['invoice', 'credit_note'])
            ->whereBetween('d.issue_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('t.component_name')
            ->orderBy('t.component_name')
            ->select('t.component_name')
            ->selectRaw(
                "SUM(CASE WHEN d.type = 'credit_note' THEN -t.taxable_amount ELSE t.taxable_amount END) AS taxable",
            )
            ->selectRaw(
                "SUM(CASE WHEN d.type = 'credit_note' THEN -t.tax_amount_base ELSE t.tax_amount_base END) AS tax",
            )
            ->get()
            ->all();

        return $this->rows($rows);
    }

    /**
     * Bills and approved expenses, claimable portion only.
     *
     * @return list<array{component: string, taxable: BigDecimal, tax: BigDecimal}>
     */
    private function inputTax(ReportPeriod $period): array
    {
        $purchases = DB::table('purchase_document_line_taxes as t')
            ->join('purchase_documents as d', 'd.id', '=', 't.purchase_document_id')
            ->where('d.organization_id', $this->tenant->organization()->getKey())
            ->whereNotNull('d.journal_entry_id')
            ->whereIn('d.type', ['bill', 'vendor_credit'])
            ->where('t.is_claimable', true)
            ->whereBetween('d.issue_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('t.component_name')
            ->select('t.component_name')
            ->selectRaw(
                "SUM(CASE WHEN d.type = 'vendor_credit' THEN -t.taxable_amount ELSE t.taxable_amount END) AS taxable",
            )
            ->selectRaw(
                "SUM(CASE WHEN d.type = 'vendor_credit' THEN -t.tax_amount_base ELSE t.tax_amount_base END) AS tax",
            )
            ->get()
            ->all();

        $expenses = DB::table('expense_line_taxes as t')
            ->join('expenses as e', 'e.id', '=', 't.expense_id')
            ->where('e.organization_id', $this->tenant->organization()->getKey())
            ->whereNotNull('e.journal_entry_id')
            ->where('t.is_claimable', true)
            ->whereBetween('e.expense_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('t.component_name')
            ->select('t.component_name')
            ->selectRaw('SUM(t.taxable_amount) AS taxable')
            ->selectRaw('SUM(t.tax_amount_base) AS tax')
            ->get()
            ->all();

        /** @var array<string, array{component: string, taxable: BigDecimal, tax: BigDecimal}> $merged */
        $merged = [];

        foreach ([$purchases, $expenses] as $set) {
            foreach ($this->rows($set) as $row) {
                $existing = $merged[$row['component']] ?? null;

                $merged[$row['component']] = $existing === null ? $row : [
                    'component' => $row['component'],
                    'taxable' => $existing['taxable']->plus($row['taxable']),
                    'tax' => $existing['tax']->plus($row['tax']),
                ];
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * Withheld from us, and withheld by us. Different obligations, so two rows.
     *
     * @return list<ReportRow>
     */
    private function withholding(ReportPeriod $period): array
    {
        /** @var object{received: ?string, made: ?string} $totals */
        $totals = DB::table('payments')
            ->where('organization_id', $this->tenant->organization()->getKey())
            ->whereBetween('payment_date', [$period->fromDate(), $period->toDate()])
            ->where('withholding_amount', '<>', 0)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN direction = 'received' THEN withholding_amount_base ELSE 0 END), 0) AS received",
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN direction = 'made' THEN withholding_amount_base ELSE 0 END), 0) AS made",
            )
            ->first();

        $received = BigDecimal::of((string) ($totals->received ?? '0'));
        $made = BigDecimal::of((string) ($totals->made ?? '0'));

        if ($received->isZero() && $made->isZero()) {
            return [];
        }

        return [
            new ReportRow(
                label: 'Withheld by customers from payments to us — reclaimable',
                values: ['taxable' => null, 'tax' => (string) $received->toScale(4, RoundingMode::HalfUp)],
                depth: 1,
            ),
            new ReportRow(
                label: 'Withheld by us from payments to vendors — to remit',
                values: ['taxable' => null, 'tax' => (string) $made->toScale(4, RoundingMode::HalfUp)],
                depth: 1,
            ),
        ];
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @return list<array{component: string, taxable: BigDecimal, tax: BigDecimal}>
     */
    private function rows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            /** @var object{component_name: string, taxable: string, tax: string} $row */
            $out[] = [
                'component' => $row->component_name,
                'taxable' => BigDecimal::of((string) $row->taxable),
                'tax' => BigDecimal::of((string) $row->tax),
            ];
        }

        return $out;
    }
}
