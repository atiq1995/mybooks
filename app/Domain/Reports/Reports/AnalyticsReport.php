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
 * Who the money came from, and where it went.
 *
 * Four views of the same period, each answering a question a business
 * actually asks: which customers, which products, which costs, which
 * suppliers.
 *
 * Amounts are NET of tax throughout. Tax is collected on somebody else's
 * behalf — ranking customers by a figure that includes it would rank them by
 * how much GST they were charged, which is not a fact about the customer.
 *
 * Only posted documents count, and credit notes subtract. A customer who
 * bought 500,000 and sent half of it back did not buy 500,000.
 */
final readonly class AnalyticsReport
{
    public const VIEWS = ['customers', 'items', 'categories', 'vendors'];

    public function __construct(
        private TenantContext $tenant,
    ) {}

    public function build(string $view, ReportPeriod $period, ?ReportPeriod $comparison = null): ReportTable
    {
        $organization = $this->tenant->organization();

        [$title, $firstColumn, $rows] = match ($view) {
            'items' => ['Sales by item', 'Item', $this->salesByItem($period)],
            'categories' => ['Spend by category', 'Account', $this->spendByCategory($period)],
            'vendors' => ['Spend by vendor', 'Vendor', $this->spendByVendor($period)],
            default => ['Sales by customer', 'Customer', $this->salesByCustomer($period)],
        };

        $priorRows = $comparison === null ? [] : match ($view) {
            'items' => $this->salesByItem($comparison),
            'categories' => $this->spendByCategory($comparison),
            'vendors' => $this->spendByVendor($comparison),
            default => $this->salesByCustomer($comparison),
        };

        $prior = [];

        foreach ($priorRows as $row) {
            $prior[$row['key']] = $row['amount'];
        }

        $columns = [
            ReportColumn::text('name', $firstColumn),
            ReportColumn::money('amount', $period->label),
        ];

        if ($comparison !== null) {
            $columns[] = ReportColumn::money('comparison', $comparison->label);
            $columns[] = ReportColumn::money('change', 'Change');
        }

        $columns[] = ReportColumn::percent('share', 'Share');

        $total = BigDecimal::zero();

        foreach ($rows as $row) {
            $total = $total->plus($row['amount']);
        }

        $reportRows = [];

        foreach ($rows as $row) {
            $was = $prior[$row['key']] ?? BigDecimal::zero();
            unset($prior[$row['key']]);

            $values = ['amount' => (string) $row['amount']->toScale(4, RoundingMode::HalfUp)];

            if ($comparison !== null) {
                $values['comparison'] = (string) $was->toScale(4, RoundingMode::HalfUp);
                $values['change'] = (string) $row['amount']->minus($was)->toScale(4, RoundingMode::HalfUp);
            }

            $values['share'] = $total->isZero()
                ? '0.0'
                : (string) $row['amount']
                    ->multipliedBy(100)
                    ->dividedBy($total, 1, RoundingMode::HalfUp);

            $reportRows[] = new ReportRow(label: $row['name'], values: $values, depth: 1);
        }

        if ($reportRows === []) {
            $reportRows[] = new ReportRow(
                label: 'Nothing in this period',
                values: ['amount' => '0.0000', 'share' => '0.0'],
                depth: 1,
            );
        }

        $footerValues = ['amount' => (string) $total->toScale(4, RoundingMode::HalfUp), 'share' => '100.0'];

        if ($comparison !== null) {
            $priorTotal = BigDecimal::zero();

            foreach ($priorRows as $row) {
                $priorTotal = $priorTotal->plus($row['amount']);
            }

            $footerValues['comparison'] = (string) $priorTotal->toScale(4, RoundingMode::HalfUp);
            $footerValues['change'] = (string) $total->minus($priorTotal)->toScale(4, RoundingMode::HalfUp);
        }

        return new ReportTable(
            title: $title,
            currency: $organization->base_currency,
            period: $period,
            columns: $columns,
            sections: [new ReportSection(label: null, rows: $reportRows)],
            footer: [ReportRow::total('Total', $footerValues)],
            comparison: $comparison,
            subtitle: $period->label,
            reconciles: true,
            reconciliation: in_array($view, ['customers', 'items'], true)
                ? 'Net of tax, from invoices and credit notes that posted in this period. The '.
                  'total is the revenue side of the profit and loss before other income.'
                : 'Net of tax, from bills and expenses that posted in this period.',
            notes: [
                'ranking' => 'Largest first. A share is of the total shown, not of all revenue.',
            ],
        );
    }

    /**
     * @return list<array{key: string, name: string, amount: BigDecimal}>
     */
    private function salesByCustomer(ReportPeriod $period): array
    {
        $rows = DB::table('sales_document_lines as l')
            ->join('sales_documents as d', 'd.id', '=', 'l.sales_document_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'd.contact_id')
            ->where('d.organization_id', $this->tenant->organization()->getKey())
            ->whereNotNull('d.journal_entry_id')
            ->whereIn('d.type', ['invoice', 'credit_note'])
            ->whereBetween('d.issue_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('d.contact_id', 'c.display_name')
            ->select('d.contact_id', 'c.display_name')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN d.type = 'credit_note' THEN -l.net ELSE l.net END), 0) AS amount",
            )
            ->get()
            ->all();

        return $this->normalise($rows, 'display_name', 'contact_id', 'No customer');
    }

    /**
     * @return list<array{key: string, name: string, amount: BigDecimal}>
     */
    private function salesByItem(ReportPeriod $period): array
    {
        $rows = DB::table('sales_document_lines as l')
            ->join('sales_documents as d', 'd.id', '=', 'l.sales_document_id')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->where('d.organization_id', $this->tenant->organization()->getKey())
            ->whereNotNull('d.journal_entry_id')
            ->whereIn('d.type', ['invoice', 'credit_note'])
            ->whereBetween('d.issue_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('l.item_id', 'i.name')
            ->select('l.item_id', 'i.name as display_name')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN d.type = 'credit_note' THEN -l.net ELSE l.net END), 0) AS amount",
            )
            ->get()
            ->all();

        return $this->normalise($rows, 'display_name', 'item_id', 'Not an item');
    }

    /**
     * Where cost landed, from both sides that produce it.
     *
     * Bills and expenses together, because "what did we spend on rent" is one
     * question and a business that answers it from bills alone is wrong by
     * every receipt somebody paid out of pocket.
     *
     * @return list<array{key: string, name: string, amount: BigDecimal}>
     */
    private function spendByCategory(ReportPeriod $period): array
    {
        $bills = DB::table('purchase_document_lines as l')
            ->join('purchase_documents as d', 'd.id', '=', 'l.purchase_document_id')
            ->join('accounts as a', 'a.id', '=', 'l.debit_account_id')
            ->where('d.organization_id', $this->tenant->organization()->getKey())
            ->whereNotNull('d.journal_entry_id')
            ->whereIn('d.type', ['bill', 'vendor_credit'])
            ->whereBetween('d.issue_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('a.id', 'a.code', 'a.name')
            ->select('a.id as key_id', DB::raw("a.code || ' · ' || a.name AS display_name"))
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN d.type = 'vendor_credit' THEN -l.net ELSE l.net END), 0) AS amount",
            )
            ->get()
            ->all();

        $expenses = DB::table('expense_lines as l')
            ->join('expenses as e', 'e.id', '=', 'l.expense_id')
            ->join('accounts as a', 'a.id', '=', 'l.debit_account_id')
            ->where('e.organization_id', $this->tenant->organization()->getKey())
            ->whereNotNull('e.journal_entry_id')
            ->whereBetween('e.expense_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('a.id', 'a.code', 'a.name')
            ->select('a.id as key_id', DB::raw("a.code || ' · ' || a.name AS display_name"))
            ->selectRaw('COALESCE(SUM(l.net), 0) AS amount')
            ->get()
            ->all();

        /** @var array<string, array{key: string, name: string, amount: BigDecimal}> $merged */
        $merged = [];

        foreach ([$bills, $expenses] as $set) {
            foreach ($this->normalise($set, 'display_name', 'key_id', 'Uncategorised') as $row) {
                $existing = $merged[$row['key']] ?? null;

                $merged[$row['key']] = $existing === null ? $row : [
                    'key' => $row['key'],
                    'name' => $row['name'],
                    'amount' => $existing['amount']->plus($row['amount']),
                ];
            }
        }

        $rows = array_values($merged);

        usort(
            $rows,
            static fn (array $a, array $b): int => $b['amount']->compareTo($a['amount']),
        );

        return $rows;
    }

    /**
     * @return list<array{key: string, name: string, amount: BigDecimal}>
     */
    private function spendByVendor(ReportPeriod $period): array
    {
        $rows = DB::table('purchase_document_lines as l')
            ->join('purchase_documents as d', 'd.id', '=', 'l.purchase_document_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'd.contact_id')
            ->where('d.organization_id', $this->tenant->organization()->getKey())
            ->whereNotNull('d.journal_entry_id')
            ->whereIn('d.type', ['bill', 'vendor_credit'])
            ->whereBetween('d.issue_date', [$period->fromDate(), $period->toDate()])
            ->groupBy('d.contact_id', 'c.display_name')
            ->select('d.contact_id', 'c.display_name')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN d.type = 'vendor_credit' THEN -l.net ELSE l.net END), 0) AS amount",
            )
            ->get()
            ->all();

        return $this->normalise($rows, 'display_name', 'contact_id', 'No vendor');
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @return list<array{key: string, name: string, amount: BigDecimal}>
     */
    private function normalise(
        array $rows,
        string $nameColumn,
        string $keyColumn,
        string $fallback,
    ): array {
        $out = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $fields */
            $fields = (array) $row;

            $name = $fields[$nameColumn] ?? null;
            $key = $fields[$keyColumn] ?? null;

            $raw = $fields['amount'] ?? 0;
            $amount = BigDecimal::of(is_numeric($raw) ? (string) $raw : '0');

            if ($amount->isZero()) {
                continue;
            }

            $out[] = [
                'key' => is_string($key) ? $key : $fallback,
                'name' => is_string($name) && $name !== '' ? $name : $fallback,
                'amount' => $amount,
            ];
        }

        usort(
            $out,
            static fn (array $a, array $b): int => $b['amount']->compareTo($a['amount']),
        );

        return $out;
    }
}
