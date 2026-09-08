<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Models\SalesDocument;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts receivable ageing.
 *
 * Who owes what, and for how long. The report exists to answer one question —
 * which of these is worth chasing today — so it leads with the oldest money
 * rather than with an alphabetical list.
 *
 * It also states whether it RECONCILES to the AR control account, prominently,
 * because an ageing report that quietly disagrees with the balance sheet is
 * worse than no report: somebody chases the wrong customer, and the real
 * discrepancy stays hidden. That reconciliation is Phase 3's exit criterion,
 * and this is where it is visible rather than only in a test.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final class ReceivablesReportController extends Controller
{
    /** The buckets everybody uses. A report with its own is incomparable. */
    private const array BUCKETS = ['current', '1_30', '31_60', '61_90', 'over_90'];

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::ReportsView->value);

        $organization = $this->tenant->organization();

        $asOf = $this->date($request->query('as_of')) ?? Carbon::now();

        $invoices = SalesDocument::query()
            ->ofType(SalesDocumentType::Invoice)
            ->with('contact:id,display_name,email')
            ->outstanding()
            ->whereDate('issue_date', '<=', $asOf->toDateString())
            ->orderBy('due_date')
            ->get();

        [$rows, $totals] = $this->age($invoices, $asOf);

        $controlBalance = $this->receivableControlBalance($asOf);

        return Inertia::render('Sales/Reports/Receivables', [
            'rows' => $rows,
            'totals' => $totals,
            'reconciliation' => $this->reconciliation($totals['total'], $controlBalance),
            'filters' => ['as_of' => $asOf->toDateString()],
            'baseCurrency' => $organization->base_currency,
        ]);
    }

    /**
     * Bucket every outstanding invoice by how overdue it is, and group by
     * customer.
     *
     * @param  Collection<int, SalesDocument>  $invoices
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function age(Collection $invoices, Carbon $asOf): array
    {
        /*
         * Three parallel maps rather than one nested array. PHPStan cannot
         * narrow a nested accumulator, and — more to the point — neither can
         * a reader: `$byContact[$id]['buckets'][$bucket]` says nothing about
         * what any level holds.
         *
         * @var array<string, array{contact_id: string, contact_name: string, contact_email: string|null, oldest_days: int, invoices: list<array<string, mixed>>}> $meta
         */
        $meta = [];

        /** @var array<string, array<string, BigDecimal>> $buckets */
        $buckets = [];

        /** @var array<string, BigDecimal> $contactTotals */
        $contactTotals = [];

        /** @var array<string, BigDecimal> $totals */
        $totals = array_fill_keys(self::BUCKETS, BigDecimal::zero());
        $totals['total'] = BigDecimal::zero();

        foreach ($invoices as $invoice) {
            $due = BigDecimal::of($invoice->balanceDue());

            if (! $due->isPositive()) {
                continue;
            }

            $contactId = $invoice->contact_id;
            $contact = $invoice->contact;

            if (! isset($meta[$contactId])) {
                $meta[$contactId] = [
                    'contact_id' => $contactId,
                    // A missing contact means the row was written outside the
                    // application; naming it rather than hiding it is the
                    // only useful thing to do with it.
                    'contact_name' => $contact === null ? 'Unknown' : $contact->display_name,
                    'contact_email' => $contact?->email,
                    'oldest_days' => 0,
                    'invoices' => [],
                ];

                $buckets[$contactId] = array_fill_keys(self::BUCKETS, BigDecimal::zero());
                $contactTotals[$contactId] = BigDecimal::zero();
            }

            /*
             * Days past due, not days since issue. An invoice on 60-day terms
             * issued 45 days ago is not overdue, and an ageing report that
             * said otherwise would have people chasing customers who are
             * paying to agreement.
             */
            $days = $invoice->due_date === null
                ? 0
                : (int) $invoice->due_date->startOfDay()->diffInDays($asOf->startOfDay(), absolute: false);

            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => 'over_90',
            };

            $buckets[$contactId][$bucket] = $buckets[$contactId][$bucket]->plus($due);
            $contactTotals[$contactId] = $contactTotals[$contactId]->plus($due);
            $meta[$contactId]['oldest_days'] = max($meta[$contactId]['oldest_days'], $days);

            $meta[$contactId]['invoices'][] = [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'currency' => $invoice->currency,
                'total' => $invoice->total,
                'balance_due' => (string) $due->toScale(4),
                'days_overdue' => max(0, $days),
                'bucket' => $bucket,
            ];

            $totals[$bucket] = $totals[$bucket]->plus($due);
            $totals['total'] = $totals['total']->plus($due);
        }

        $rows = [];

        foreach ($meta as $contactId => $row) {
            $rows[] = [
                ...$row,
                'buckets' => array_map(
                    static fn (BigDecimal $amount): string => (string) $amount->toScale(4),
                    $buckets[$contactId],
                ),
                'total' => (string) $contactTotals[$contactId]->toScale(4),
            ];
        }

        // Oldest money first: the report exists to say who to chase today.
        usort(
            $rows,
            static fn (array $a, array $b): int => $b['oldest_days'] <=> $a['oldest_days']
                ?: strcmp((string) $a['contact_name'], (string) $b['contact_name']),
        );

        return [
            $rows,
            array_map(
                static fn (BigDecimal $amount): string => (string) $amount->toScale(4),
                $totals,
            ),
        ];
    }

    /**
     * The AR control account balance, as at the same date.
     *
     * Not filtered by entry status: `reversed` means a reversing entry
     * exists, not that the original never happened, and excluding one while
     * counting the other is how a balance goes wrong by the value of
     * everything voided.
     */
    private function receivableControlBalance(Carbon $asOf): string
    {
        $account = Account::query()
            ->where('system_role', SystemAccount::AccountsReceivable->value)
            ->first();

        if ($account === null) {
            return '0.0000';
        }

        return $account->balance($asOf);
    }

    /**
     * Whether the report agrees with the ledger.
     *
     * A difference is not necessarily a bug — an opening balance entered as a
     * journal rather than an invoice would produce one, and so would a
     * manual entry to the control account. But it always means the two
     * numbers cannot both be trusted, so the report says so rather than
     * letting the reader assume.
     *
     * @return array{aged: string, control: string, difference: string, reconciles: bool}
     */
    private function reconciliation(string $aged, string $control): array
    {
        $difference = BigDecimal::of($control)->minus(BigDecimal::of($aged));

        return [
            'aged' => $aged,
            'control' => $control,
            'difference' => (string) $difference->toScale(4),
            'reconciles' => $difference->isZero(),
        ];
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
