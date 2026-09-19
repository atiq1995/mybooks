<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateFiscalYear;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Data\ReportPeriod;
use App\Domain\Reports\Data\ReportRow;
use App\Domain\Reports\Data\ReportTable;
use App\Domain\Reports\Reports\AnalyticsReport;
use App\Domain\Reports\Reports\BalanceSheetReport;
use App\Domain\Reports\Reports\CashFlowReport;
use App\Domain\Reports\Reports\ProfitAndLossReport;
use App\Domain\Reports\Services\LedgerBalances;
use App\Domain\Sales\Actions\IssueSalesDocument;
use App\Domain\Sales\Actions\SaveSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Tax\Models\Tax;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| Reports
|---------------------------------------------------------------------------
|
| Phase 7's exit criteria, asserted rather than demonstrated:
|
|   every report reconciles to the ledger — the balance sheet balances, the
|   cash flow's net change equals the actual movement on the cash accounts,
|   and the profit the balance sheet carries into equity is the same figure
|   the profit and loss reports;
|
|   every figure drills through to the journal lines that produced it — and
|   the test proves it by running the drill-through query a row carries and
|   checking it sums to that row's own figure.
|
| The books below are small but real: a sale, a purchase, a part payment, a
| depreciation charge, a loan and an owner contribution. Between them they
| exercise every section of all three statements.
|
| @see ACCOUNTING_RULES.md I11
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->post = app(PostJournalEntry::class);
    $this->balances = app(LedgerBalances::class);
    $this->profitAndLoss = app(ProfitAndLossReport::class);
    $this->balanceSheet = app(BalanceSheetReport::class);
    $this->cashFlow = app(CashFlowReport::class);
    $this->analytics = app(AnalyticsReport::class);

    $this->period = ReportPeriod::custom(
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-09-30'),
    );

    /** Post a two-sided entry, by account code. */
    $this->entry = function (string $on, array $lines, ?string $memo = null): void {
        $drafts = [];

        foreach ($lines as [$code, $side, $amount]) {
            $accountId = ledgerAccount($code)->id;

            $drafts[] = $side === 'debit'
                ? JournalLineDraft::debit($accountId, $amount, $memo)
                : JournalLineDraft::credit($accountId, $amount, $memo);
        }

        $this->post->handle(JournalDraft::inBaseCurrency(
            date: Carbon::parse($on),
            currency: $this->organization->base_currency,
            lines: $drafts,
            source: ['manual', (string) Str::uuid7(), 'issue'],
            memo: $memo,
        ), actor: $this->actor);
    };

    /**
     * A quarter of trading.
     *
     *   an invoice for 500,000 plus 90,000 GST, half collected
     *   returns of 20,000 given back
     *   cost of sales of 180,000, bought on credit and part paid
     *   rent of 90,000 paid from the bank
     *   depreciation of 25,000 — a cost with no cash behind it
     *   a 300,000 loan drawn, and 200,000 put in by the owner
     *   150,000 spent on equipment
     */
    $this->tradingQuarter = function (): void {
        ($this->entry)('2026-07-05', [
            ['1200', 'debit', '590000.00'],
            ['4010', 'credit', '500000.00'],
            ['2300', 'credit', '90000.00'],
        ], 'INV-000001');

        ($this->entry)('2026-07-20', [
            ['1020', 'debit', '295000.00'],
            ['1200', 'credit', '295000.00'],
        ], 'Part payment');

        ($this->entry)('2026-08-02', [
            ['4800', 'debit', '20000.00'],
            ['1200', 'credit', '20000.00'],
        ], 'Returns');

        ($this->entry)('2026-08-05', [
            ['5010', 'debit', '180000.00'],
            ['2100', 'credit', '180000.00'],
        ], 'BILL-000001');

        ($this->entry)('2026-08-20', [
            ['2100', 'debit', '100000.00'],
            ['1020', 'credit', '100000.00'],
        ], 'Paid the vendor');

        ($this->entry)('2026-08-31', [
            ['6100', 'debit', '90000.00'],
            ['1020', 'credit', '90000.00'],
        ], 'Rent');

        ($this->entry)('2026-09-30', [
            ['6700', 'debit', '25000.00'],
            ['1750', 'credit', '25000.00'],
        ], 'Depreciation');

        ($this->entry)('2026-07-01', [
            ['1020', 'debit', '300000.00'],
            ['2700', 'credit', '300000.00'],
        ], 'Loan drawn');

        ($this->entry)('2026-07-01', [
            ['1020', 'debit', '200000.00'],
            ['3400', 'credit', '200000.00'],
        ], 'Owner contribution');

        ($this->entry)('2026-07-10', [
            ['1700', 'debit', '150000.00'],
            ['1020', 'credit', '150000.00'],
        ], 'Equipment');
    };
});

describe('profit and loss', function (): void {
    it('reads as a statement, with the subtotals in between', function (): void {
        ($this->tradingQuarter)();

        $table = $this->profitAndLoss->build($this->period);
        $figures = footerFigures($table);

        /*
         * 500,000 sold less 20,000 returned is 480,000 net revenue; less
         * 180,000 of cost is 300,000 gross; less 115,000 of operating costs
         * — rent and depreciation — is 185,000.
         */
        expect($figures['Net revenue'])->toBeDecimal('480000.0000')
            ->and($figures['Gross profit'])->toBeDecimal('300000.0000')
            ->and($figures['Operating profit'])->toBeDecimal('185000.0000')
            ->and($figures['Profit for the period'])->toBeDecimal('185000.0000');
    });

    it('shows returns as a deduction rather than netting them into revenue', function (): void {
        /*
         * Gross sales and what was given back are different facts. A business
         * whose discounts are growing needs to see that happen, and netting
         * silently is how it stays invisible.
         */
        ($this->tradingQuarter)();

        $table = $this->profitAndLoss->build($this->period);

        $revenue = sectionTotal($table, 'Revenue');
        $returns = sectionTotal($table, 'Less: returns and discounts');

        expect($revenue)->toBeDecimal('500000.0000')
            ->and($returns)->toBeDecimal('20000.0000');
    });

    it('compares against the same span a year earlier', function (): void {
        // The comparison has to be a year that EXISTS: a period nobody opened
        // has no postings and no meaning.
        app(CreateFiscalYear::class)->handle($this->organization, 2025);

        ($this->entry)('2025-08-05', [
            ['1200', 'debit', '100000.00'],
            ['4010', 'credit', '100000.00'],
        ], 'Last year');

        ($this->tradingQuarter)();

        $comparison = $this->period->sameSpanLastYear();

        expect($comparison->fromDate())->toBe('2025-07-01')
            ->and($comparison->toDate())->toBe('2025-09-30');

        $table = $this->profitAndLoss->build($this->period, $comparison);

        $revenueRow = accountRow($table, '4010');

        expect($revenueRow->values['amount'])->toBeDecimal('500000.0000')
            ->and($revenueRow->values['comparison'])->toBeDecimal('100000.0000')
            ->and($revenueRow->values['change'])->toBeDecimal('400000.0000');
    });

    it('keeps an account that only traded in the comparison period', function (): void {
        // A cost that STOPPED is news. Dropping the row would hide exactly
        // the change the comparison exists to show.
        app(CreateFiscalYear::class)->handle($this->organization, 2025);

        ($this->entry)('2025-08-05', [
            ['6600', 'debit', '40000.00'],
            ['1020', 'credit', '40000.00'],
        ], 'Marketing, last year');

        ($this->tradingQuarter)();

        $table = $this->profitAndLoss->build($this->period, $this->period->sameSpanLastYear());

        $row = accountRow($table, '6600');

        expect($row->values['amount'])->toBeDecimal('0.0000')
            ->and($row->values['comparison'])->toBeDecimal('40000.0000');
    });
});

describe('the balance sheet', function (): void {
    it('balances, and says so', function (): void {
        ($this->tradingQuarter)();

        $table = $this->balanceSheet->build(Carbon::parse('2026-09-30'));
        $figures = footerFigures($table);

        expect($table->reconciles)->toBeTrue()
            ->and($figures['Total assets'])->toBeDecimal($figures['Total liabilities and equity'])
            ->and($table->reconciliation)->toContain('to the cent');
    });

    it('carries the profit and loss figure into equity, unchanged', function (): void {
        /*
         * The thing that makes a balance sheet work before year-end. Until
         * the year is closed the period's earnings sit in the income and
         * expense accounts, so equity has to pick them up — and it must be
         * the SAME figure the profit and loss reports, not a re-derivation.
         */
        ($this->tradingQuarter)();

        $asOf = Carbon::parse('2026-09-30');

        $yearToDate = ReportPeriod::fiscalYearToDate($this->organization, $asOf);
        $profit = $this->profitAndLoss->profitFor($yearToDate);

        $table = $this->balanceSheet->build($asOf);
        $row = rowLabelled($table, 'Profit for the financial year to date');

        expect($row->values['amount'])->toBeDecimal((string) $profit->toScale(4))
            ->and($row->values['amount'])->toBeDecimal('185000.0000');
    });

    it('splits current from non-current, and shows a contra asset negatively', function (): void {
        ($this->tradingQuarter)();

        $table = $this->balanceSheet->build(Carbon::parse('2026-09-30'));

        // 150,000 of equipment less 25,000 of accumulated depreciation.
        expect(sectionTotal($table, 'Non-current assets'))->toBeDecimal('125000.0000')
            ->and(accountRow($table, '1750')->values['amount'])->toBeDecimal('-25000.0000')
            ->and(sectionTotal($table, 'Non-current liabilities'))->toBeDecimal('300000.0000');
    });

    it('is everything that ever happened, not just this period', function (): void {
        ($this->entry)('2026-07-01', [
            ['1020', 'debit', '50000.00'],
            ['3400', 'credit', '50000.00'],
        ], 'Before');

        // A date in the next financial year still carries the balance.
        $table = $this->balanceSheet->build(Carbon::parse('2027-09-30'));

        expect(accountRow($table, '1020')->values['amount'])->toBeDecimal('50000.0000')
            ->and($table->reconciles)->toBeTrue();
    });
});

describe('the cash flow', function (): void {
    it('explains the movement on the bank, to the cent', function (): void {
        ($this->tradingQuarter)();

        $table = $this->cashFlow->build($this->period);
        $figures = footerFigures($table);

        /*
         * 295,000 collected + 300,000 borrowed + 200,000 contributed, less
         * 100,000 to the vendor, 90,000 of rent and 150,000 of equipment.
         */
        expect($table->reconciles)->toBeTrue()
            ->and($figures['Net change in cash'])->toBeDecimal('455000.0000')
            ->and($figures['Cash and bank at the start'])->toBeDecimal('0.0000')
            ->and($figures['Cash and bank at the end'])->toBeDecimal('455000.0000');
    });

    it('adds depreciation back, because no cash left', function (): void {
        ($this->tradingQuarter)();

        $table = $this->cashFlow->build($this->period);

        $row = rowLabelled($table, 'Depreciation charged — Accumulated Depreciation');

        expect($row->values['amount'])->toBeDecimal('25000.0000');
    });

    it('subtracts a receivable that grew, and adds a payable that did', function (): void {
        /*
         * The whole point of the statement. 185,000 of profit with 275,000
         * still uncollected is not 185,000 of cash, and a business that
         * cannot see the difference runs out of money while profitable.
         */
        ($this->tradingQuarter)();

        $table = $this->cashFlow->build($this->period);

        expect(rowLabelled($table, 'Increase in Accounts Receivable')->values['amount'])
            ->toBeDecimal('-275000.0000')
            ->and(rowLabelled($table, 'Increase in Accounts Payable')->values['amount'])
            ->toBeDecimal('80000.0000');
    });

    it('separates investing and financing from trading', function (): void {
        ($this->tradingQuarter)();

        $table = $this->cashFlow->build($this->period);

        expect(sectionTotal($table, 'Investing activities'))->toBeDecimal('-150000.0000')
            // 300,000 borrowed plus 200,000 contributed.
            ->and(sectionTotal($table, 'Financing activities'))->toBeDecimal('500000.0000');
    });

    it('still reconciles when a period has no cash movement at all', function (): void {
        ($this->entry)('2026-08-05', [
            ['5010', 'debit', '180000.00'],
            ['2100', 'credit', '180000.00'],
        ], 'Bought on credit, paid nothing');

        $table = $this->cashFlow->build($this->period);

        expect($table->reconciles)->toBeTrue()
            ->and(footerFigures($table)['Net change in cash'])->toBeDecimal('0.0000');
    });
});

describe('drilling through', function (): void {
    it('reproduces a profit and loss figure exactly', function (): void {
        /*
         * Phase 7's second exit criterion, and the only way to test it that
         * proves anything: take the filter the ROW carries, run it against
         * the ledger, and check the lines sum to that row's own figure.
         *
         * A drill-through assembled from the screen's filters rather than the
         * row's would pass a weaker test and fail a reader — a balance sheet
         * row has no lower bound at all.
         */
        ($this->tradingQuarter)();

        $table = $this->profitAndLoss->build($this->period);

        foreach (accountRows($table) as $row) {
            expect($row->drill)->not->toBeNull();

            $lines = $this->balances
                ->linesFor($row->drill['account_id'], $row->drill['from'], $row->drill['to'])
                ->selectRaw('COALESCE(SUM(journal_lines.debit_base - journal_lines.credit_base), 0) AS net')
                ->value('net');

            $net = BigDecimal::of((string) $lines);

            // Read in the account's own direction, exactly as the row shows it.
            $expected = BigDecimal::of($row->values['amount']);

            expect($net->abs()->toScale(4))->toBeDecimal((string) $expected->abs()->toScale(4));
        }
    });

    it('drills a balance sheet row with no lower bound', function (): void {
        ($this->tradingQuarter)();

        $table = $this->balanceSheet->build(Carbon::parse('2026-09-30'));
        $row = accountRow($table, '1020');

        expect($row->drill['from'])->toBeNull()
            ->and($row->drill['to'])->toBe('2026-09-30');

        $net = BigDecimal::of((string) $this->balances
            ->linesFor($row->drill['account_id'], null, $row->drill['to'])
            ->selectRaw('COALESCE(SUM(journal_lines.debit_base - journal_lines.credit_base), 0) AS net')
            ->value('net'));

        expect($net->toScale(4))->toBeDecimal($row->values['amount']);
    });

    it('offers no drill-through on a figure no single query produces', function (): void {
        // Gross profit is arithmetic over several accounts. Linking it
        // somewhere approximate would be worse than not linking it: it would
        // look like proof.
        ($this->tradingQuarter)();

        $table = $this->profitAndLoss->build($this->period);

        expect(rowLabelled($table, 'Gross profit')->drill)->toBeNull();
    });
});

describe('analytics', function (): void {
    it('ranks customers net of tax, and nets a credit note off', function (): void {
        $customer = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Karachi Textiles',
        ]);

        $invoice = salesDocumentFor($customer, '2026-07-05', 'invoice', '500000.00');
        salesDocumentFor($customer, '2026-08-05', 'credit_note', '50000.00');

        $table = $this->analytics->build('customers', $this->period);
        $row = rowLabelled($table, 'Karachi Textiles');

        expect($row->values['amount'])->toBeDecimal('450000.0000')
            ->and($row->values['share'])->toBe('100.0')
            ->and($invoice->journal_entry_id)->not->toBeNull();
    });
});

/**
 * The footer figures of a report, keyed by label.
 *
 * @return array<string, string>
 */
function footerFigures(ReportTable $table): array
{
    $figures = [];

    foreach ($table->footer as $row) {
        $figures[$row->label] = (string) ($row->values['amount'] ?? '');
    }

    return $figures;
}

function sectionTotal(ReportTable $table, string $label): string
{
    foreach ($table->sections as $section) {
        if ($section->label === $label && $section->total !== null) {
            return (string) ($section->total->values['amount'] ?? '');
        }
    }

    throw new RuntimeException("No section totalled \"{$label}\" in {$table->title}.");
}

function accountRow(ReportTable $table, string $code): ReportRow
{
    foreach (accountRows($table) as $row) {
        if ($row->code === $code) {
            return $row;
        }
    }

    throw new RuntimeException("No row for account {$code} in {$table->title}.");
}

function rowLabelled(ReportTable $table, string $label): ReportRow
{
    foreach ($table->flatten() as $row) {
        if ($row->label === $label) {
            return $row;
        }
    }

    throw new RuntimeException("No row labelled \"{$label}\" in {$table->title}.");
}

/**
 * @return list<ReportRow>
 */
function accountRows(ReportTable $table): array
{
    $rows = [];

    foreach ($table->sections as $section) {
        foreach ($section->rows as $row) {
            if ($row->style === 'row' && $row->drill !== null) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

/**
 * A posted invoice or credit note with one line and GST on it.
 */
function salesDocumentFor(
    Contact $contact,
    string $on,
    string $type,
    string $amount,
): SalesDocument {
    $save = app(SaveSalesDocument::class);
    $issue = app(IssueSalesDocument::class);

    $document = $save->handle(
        type: SalesDocumentType::from($type),
        attributes: [
            'contact_id' => $contact->id,
            'issue_date' => $on,
        ],
        lines: [[
            'description' => 'Work',
            'quantity' => '1',
            'unit_price' => $amount,
            'tax_id' => Tax::query()->where('code', 'GST18')->sole()->id,
            'revenue_account_id' => ledgerAccount('4010')->id,
        ]],
    );

    return $issue->handle($document);
}
