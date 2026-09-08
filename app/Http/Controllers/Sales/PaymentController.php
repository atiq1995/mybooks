<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Actions\RecordCustomerPayment;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Tax\Models\Tax;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StorePaymentRequest;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money received from customers.
 *
 * The form is built around allocation rather than around one invoice,
 * because that is what actually arrives: a cheque covering three invoices, a
 * part payment, or an advance against nothing. A screen that asked "which
 * invoice is this for" would be wrong a third of the time.
 *
 * Withholding is entered on the payment, and the form says why: the invoice
 * is settled in full and the withheld amount becomes a receivable from the
 * tax authority.
 *
 * @see ACCOUNTING_RULES.md §4.2, §4.3, §4.4
 */
final class PaymentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RecordCustomerPayment $recordCustomerPayment,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::SalesView->value);

        $organization = $this->tenant->organization();
        $search = $request->string('search')->toString();

        $payments = Payment::query()
            ->received()
            ->with(['contact:id,display_name', 'bankAccount:id,code,name'])
            ->when(
                $search !== '',
                fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('number', 'ilike', "%{$search}%")
                        ->orWhere('reference', 'ilike', "%{$search}%")
                        ->orWhereHas(
                            'contact',
                            fn ($contact) => $contact->where('display_name', 'ilike', "%{$search}%"),
                        );
                }),
            )
            ->orderByDesc('payment_date')
            ->orderByDesc('number')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Sales/Payments/Index', [
            'payments' => [
                'data' => array_values(array_map(
                    fn (Payment $payment): array => [
                        'id' => $payment->id,
                        'number' => $payment->number,
                        'contact_name' => $payment->contact?->display_name,
                        'payment_date' => $payment->payment_date->toDateString(),
                        'bank_account' => $payment->bankAccount === null
                            ? null
                            : "{$payment->bankAccount->code} {$payment->bankAccount->name}",
                        'method' => $payment->method,
                        'reference' => $payment->reference,
                        'currency' => $payment->currency,
                        'amount' => $payment->amount,
                        'withholding_amount' => $payment->withholding_amount,
                        'amount_received' => $payment->amount_received,
                        'allocated_amount' => $payment->allocated_amount,
                        'unallocated' => $payment->unallocatedAmount(),
                        'status' => $payment->status,
                    ],
                    $payments->items(),
                )),
                'links' => $payments->linkCollection()->toArray(),
                'total' => $payments->total(),
                'from' => $payments->firstItem(),
                'to' => $payments->lastItem(),
            ],
            'filters' => ['search' => $search],
            'baseCurrency' => $organization->base_currency,
            'can' => [
                'record' => $request->user()?->can(Permission::SalesRecordPayment->value) ?? false,
            ],
        ]);
    }

    /**
     * The receipt form.
     *
     * Opened either cold or from an invoice, in which case that invoice is
     * pre-allocated for its full balance — which is what somebody clicking
     * "record payment" on an invoice means.
     */
    public function create(Request $request): Response
    {
        $this->authorize(Permission::SalesRecordPayment->value);

        $organization = $this->tenant->organization();

        $contactId = $request->string('contact')->toString();
        $invoiceNumber = $request->string('invoice')->toString();

        $invoice = $invoiceNumber === ''
            ? null
            : SalesDocument::query()
                ->ofType(SalesDocumentType::Invoice)
                ->where('number', $invoiceNumber)
                ->first();

        if ($invoice !== null) {
            $contactId = $invoice->contact_id;
        }

        return Inertia::render('Sales/Payments/Create', [
            'customers' => $this->customerOptions(),
            'bankAccounts' => $this->bankAccountOptions(),
            'withholdingTaxes' => $this->withholdingOptions(),
            'preselected' => [
                'contact_id' => $contactId === '' ? null : $contactId,
                'invoice_id' => $invoice?->id,
                'amount' => $invoice?->balanceDue(),
            ],
            'outstanding' => $contactId === '' ? [] : $this->outstandingFor($contactId),
            'baseCurrency' => $organization->base_currency,
            'today' => Carbon::now()->toDateString(),
        ]);
    }

    /**
     * What a customer still owes, for the allocation table.
     *
     * Its own endpoint so choosing a customer does not reload the form and
     * lose what has already been typed.
     */
    public function outstanding(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize(Permission::SalesRecordPayment->value);

        abort_unless($contact->organization_id === $this->tenant->organization()->id, 404);

        return response()->json(['outstanding' => $this->outstandingFor($contact->id)]);
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $this->authorize(Permission::SalesRecordPayment->value);

        $validated = $request->validated();

        $contact = Contact::query()->findOrFail($request->string('contact_id')->toString());

        /** @var array<string, string> $allocations */
        $allocations = [];

        foreach ((array) ($validated['allocations'] ?? []) as $allocation) {
            if (! is_array($allocation)) {
                continue;
            }

            $documentId = $allocation['document_id'] ?? null;
            $amount = $allocation['amount'] ?? null;

            if (is_string($documentId) && is_string($amount) && $amount !== '') {
                $allocations[$documentId] = $amount;
            }
        }

        try {
            $payment = $this->recordCustomerPayment->handle(
                contact: $contact,
                bankAccountId: $request->string('bank_account_id')->toString(),
                amount: $request->string('amount')->toString(),
                paymentDate: Carbon::parse($request->string('payment_date')->toString()),
                allocations: $allocations,
                withholdingAmount: self::orDefault($request->string('withholding_amount')->toString(), '0'),
                withholdingTaxId: self::orNull($request->string('withholding_tax_id')->toString()),
                currency: self::orNull($request->string('currency')->toString()),
                exchangeRate: self::orDefault($request->string('exchange_rate')->toString(), '1'),
                method: self::orDefault($request->string('method')->toString(), 'bank_transfer'),
                reference: self::orNull($request->string('reference')->toString()),
                notes: self::orNull($request->string('notes')->toString()),
                actor: $request->user(),
                allowClosedPeriod: $request->boolean('post_to_closed_period')
                    && ($request->user()?->can(Permission::AccountingPostToClosedPeriod->value) ?? false),
            );
        } catch (SalesDocumentRefused|PostingRefused|\InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        $unallocated = BigDecimal::of($payment->unallocatedAmount());

        return redirect()
            ->route('sales.payments.index')
            ->with('success', sprintf(
                '%s recorded: %s %s from %s.%s',
                $payment->number,
                $payment->currency,
                $payment->amount,
                $contact->display_name,
                $unallocated->isPositive()
                    ? " {$unallocated->toScale(2)} is held as an advance."
                    : '',
            ));
    }

    /**
     * A blank field is absent, not empty.
     *
     * A form posts an empty string for anything nobody filled in, and storing
     * that rather than null makes "has a reference" true for every payment.
     */
    private static function orNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function orDefault(string $value, string $default): string
    {
        return $value === '' ? $default : $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function outstandingFor(string $contactId): array
    {
        return array_values(
            SalesDocument::query()
                ->ofType(SalesDocumentType::Invoice)
                ->where('contact_id', $contactId)
                ->outstanding()
                ->orderBy('due_date')
                ->orderBy('number')
                ->get()
                ->filter(static fn (SalesDocument $invoice): bool => BigDecimal::of($invoice->balanceDue())->isPositive())
                ->map(static fn (SalesDocument $invoice): array => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'issue_date' => $invoice->issue_date->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'currency' => $invoice->currency,
                    'total' => $invoice->total,
                    'balance_due' => $invoice->balanceDue(),
                    'is_overdue' => $invoice->isOverdue(),
                    'days_overdue' => $invoice->daysOverdue(),
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * @return list<array{value: string, label: string, currency: string|null}>
     */
    private function customerOptions(): array
    {
        return array_values(
            Contact::query()
                ->usable()
                ->customers()
                ->orderBy('display_name')
                ->get()
                ->map(static fn (Contact $contact): array => [
                    'value' => $contact->id,
                    'label' => $contact->display_name,
                    'currency' => $contact->currency,
                ])
                ->all(),
        );
    }

    /**
     * Where the money can have landed.
     *
     * Cash and bank subtypes only. Offering the whole chart would invite
     * somebody to "receive" money into a revenue account, which balances and
     * is nonsense.
     *
     * @return list<array{value: string, label: string, currency: string|null}>
     */
    private function bankAccountOptions(): array
    {
        return array_values(
            Account::query()
                ->postable()
                ->whereIn('subtype', ['cash', 'bank'])
                ->orderBy('code')
                ->get()
                ->map(static fn (Account $account): array => [
                    'value' => $account->id,
                    'label' => "{$account->code} — {$account->name}",
                    'currency' => $account->currency,
                ])
                ->all(),
        );
    }

    /**
     * @return list<array{value: string, label: string, rate: string|null}>
     */
    private function withholdingOptions(): array
    {
        return array_values(
            Tax::query()
                ->usable()
                ->withholding()
                ->with('components')
                ->orderBy('name')
                ->get()
                ->map(static fn (Tax $tax): array => [
                    'value' => $tax->id,
                    'label' => $tax->name,
                    // The current rate, so the form can compute the deduction
                    // rather than making the user reach for a calculator.
                    'rate' => $tax->components->first()?->rate,
                ])
                ->all(),
        );
    }
}
