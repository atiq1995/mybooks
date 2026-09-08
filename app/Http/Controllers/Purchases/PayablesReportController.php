<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchases;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts payable ageing.
 *
 * What we owe, and for how long. The mirror of the receivables report, and it
 * answers a different question: not who to chase, but what to pay next and
 * what is already late.
 *
 * That difference shows in the ordering. Receivables leads with the oldest
 * money, because the oldest debt is the least likely to be collected.
 * Payables leads with what falls due SOONEST, because the reader is deciding
 * what to pay this week — and a list headed by a two-year-old disputed
 * invoice would bury the bill due on Friday.
 *
 * It states whether it reconciles to the AP control account, for the same
 * reason the receivables report does: a payables report that quietly
 * disagrees with the balance sheet leaves somebody paying from a figure
 * nobody can vouch for.
 *
 * @see ACCOUNTING_RULES.md §4.6, §6
 */
final class PayablesReportController extends Controller
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

        $bills = PurchaseDocument::query()
            ->ofType(PurchaseDocumentType::Bill)
            ->with('contact:id,display_name,email')
            ->outstanding()
            ->whereDate('issue_date', '<=', $asOf->toDateString())
            ->orderBy('due_date')
            ->get();

        [$rows, $totals] = $this->age($bills, $asOf);

        return Inertia::render('Purchases/Reports/Payables', [
            'rows' => $rows,
            'totals' => $totals,
            'reconciliation' => $this->reconciliation(
                $totals['total'],
                $this->payableControlBalance($asOf),
            ),
            'dueSoon' => $this->dueSoon($bills, $asOf),
            'filters' => ['as_of' => $asOf->toDateString()],
            'baseCurrency' => $organization->base_currency,
        ]);
    }

    /**
     * Bucket every outstanding bill by how overdue it is, and group by vendor.
     *
     * @param  Collection<int, PurchaseDocument>  $bills
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function age(Collection $bills, Carbon $asOf): array
    {
        /**
         * Three parallel maps rather than one nested array, for the same
         * reason as the receivables report: neither PHPStan nor a reader can
         * narrow a nested accumulator.
         *
         * @var array<string, array{contact_id: string, contact_name: string, contact_email: string|null, oldest_days: int, soonest_due: string|null, bills: list<array<string, mixed>>}> $meta
         */
        $meta = [];

        /** @var array<string, array<string, BigDecimal>> $buckets */
        $buckets = [];

        /** @var array<string, BigDecimal> $contactTotals */
        $contactTotals = [];

        /** @var array<string, BigDecimal> $totals */
        $totals = array_fill_keys(self::BUCKETS, BigDecimal::zero());
        $totals['total'] = BigDecimal::zero();

        foreach ($bills as $bill) {
            $due = BigDecimal::of($bill->balanceDue());

            if (! $due->isPositive()) {
                continue;
            }

            $contactId = $bill->contact_id;
            $contact = $bill->contact;

            if (! isset($meta[$contactId])) {
                $meta[$contactId] = [
                    'contact_id' => $contactId,
                    'contact_name' => $contact === null ? 'Unknown' : $contact->display_name,
                    'contact_email' => $contact?->email,
                    'oldest_days' => 0,
                    'soonest_due' => null,
                    'bills' => [],
                ];

                $buckets[$contactId] = array_fill_keys(self::BUCKETS, BigDecimal::zero());
                $contactTotals[$contactId] = BigDecimal::zero();
            }

            /*
             * Days past due, not days since the bill arrived. A bill on
             * 60-day terms received 45 days ago is not late, and a report
             * saying otherwise would have people paying early for no reason.
             */
            $days = $bill->due_date === null
                ? 0
                : (int) $bill->due_date->startOfDay()->diffInDays($asOf->startOfDay(), absolute: false);

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

            $dueDate = $bill->due_date?->toDateString();

            if ($dueDate !== null) {
                $current = $meta[$contactId]['soonest_due'];
                $meta[$contactId]['soonest_due'] = $current === null || $dueDate < $current
                    ? $dueDate
                    : $current;
            }

            $meta[$contactId]['bills'][] = [
                'id' => $bill->id,
                'number' => $bill->number,
                'vendor_reference' => $bill->vendor_reference,
                'issue_date' => $bill->issue_date->toDateString(),
                'due_date' => $dueDate,
                'currency' => $bill->currency,
                'total' => $bill->total,
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

        /*
         * Soonest due first — the opposite of the receivables report, and
         * deliberately so. The reader of this page is deciding what to pay
         * next, so the thing due on Friday belongs above the thing that has
         * been in dispute for a year. A vendor with nothing dated at all
         * sorts last rather than first.
         */
        usort($rows, static function (array $a, array $b): int {
            $left = $a['soonest_due'] ?? '9999-12-31';
            $right = $b['soonest_due'] ?? '9999-12-31';

            return strcmp((string) $left, (string) $right)
                ?: strcmp((string) $a['contact_name'], (string) $b['contact_name']);
        });

        return [
            $rows,
            array_map(
                static fn (BigDecimal $amount): string => (string) $amount->toScale(4),
                $totals,
            ),
        ];
    }

    /**
     * What falls due in the next seven days, and what is already late.
     *
     * The one figure the reader of a payables report actually acts on. Left
     * to the ageing buckets it would be invisible: "current" mixes a bill due
     * tomorrow with one due in two months.
     *
     * @param  Collection<int, PurchaseDocument>  $bills
     * @return array{within_7_days: string, overdue: string, count_within_7_days: int}
     */
    private function dueSoon(Collection $bills, Carbon $asOf): array
    {
        $horizon = $asOf->copy()->startOfDay()->addDays(7);
        $today = $asOf->copy()->startOfDay();

        $soon = BigDecimal::zero();
        $overdue = BigDecimal::zero();
        $count = 0;

        foreach ($bills as $bill) {
            $due = BigDecimal::of($bill->balanceDue());

            if (! $due->isPositive() || $bill->due_date === null) {
                continue;
            }

            $dueDate = $bill->due_date->copy()->startOfDay();

            if ($dueDate->isBefore($today)) {
                $overdue = $overdue->plus($due);

                continue;
            }

            if ($dueDate->lessThanOrEqualTo($horizon)) {
                $soon = $soon->plus($due);
                $count++;
            }
        }

        return [
            'within_7_days' => (string) $soon->toScale(4),
            'overdue' => (string) $overdue->toScale(4),
            'count_within_7_days' => $count,
        ];
    }

    /**
     * The AP control account balance, as at the same date.
     *
     * Not filtered by entry status: `reversed` means a reversing entry
     * exists, not that the original never happened, and excluding one while
     * counting the other is how a balance goes wrong by the value of
     * everything voided.
     *
     * Returned as a POSITIVE figure. A payable is a credit balance, so the
     * account's own signed balance is negative — and comparing that with a
     * positive aged total would report a difference of twice the balance on a
     * set of books that agrees perfectly.
     */
    private function payableControlBalance(Carbon $asOf): string
    {
        $account = Account::query()
            ->where('system_role', SystemAccount::AccountsPayable->value)
            ->first();

        if ($account === null) {
            return '0.0000';
        }

        return (string) BigDecimal::of($account->balance($asOf))->abs()->toScale(4);
    }

    /**
     * Whether the report agrees with the ledger.
     *
     * A difference is not necessarily a bug — an opening balance entered as a
     * journal rather than a bill would produce one — but it always means the
     * two figures cannot both be trusted, so the report says so rather than
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
