<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchases;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Purchases\Actions\RecordVendorPayment;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Exceptions\PurchaseDocumentRefused;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tax\Models\Tax;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StoreVendorPaymentRequest;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money paid to vendors.
 *
 * Built around allocation rather than around one bill, because that is what
 * actually happens: one transfer settling four bills, a part payment on
 * account, or a deposit against nothing yet.
 *
 * Withholding is entered on the payment, and the form says why: the bill is
 * settled in full and the withheld amount becomes a liability to the tax
 * authority until it is remitted. §4.7.
 *
 * @see ACCOUNTING_RULES.md §4.7
 */
final class VendorPaymentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RecordVendorPayment $recordVendorPayment,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::PurchasesView->value);

        $organization = $this->tenant->organization();
        $search = $request->string('search')->toString();

        $payments = Payment::query()
            ->made()
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

        return Inertia::render('Purchases/Payments/Index', [
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
                        // What actually left the bank. The column is named
                        // for the sales side; on this side it means paid out.
                        'amount_paid_out' => $payment->amount_received,
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
                'record' => $request->user()?->can(Permission::PurchasesRecordPayment->value) ?? false,
            ],
        ]);
    }

    /**
     * The payment form.
     *
     * Opened either cold or from a bill, in which case that bill is
     * pre-allocated for its full balance.
     */
    public function create(Request $request): Response
    {
        $this->authorize(Permission::PurchasesRecordPayment->value);

        $organization = $this->tenant->organization();

        $contactId = $request->string('contact')->toString();
        $billNumber = $request->string('bill')->toString();

        $bill = $billNumber === ''
            ? null
            : PurchaseDocument::query()
                ->ofType(PurchaseDocumentType::Bill)
                ->where('number', $billNumber)
                ->first();

        if ($bill !== null) {
            $contactId = $bill->contact_id;
        }

        return Inertia::render('Purchases/Payments/Create', [
            'vendors' => $this->vendorOptions(),
            'bankAccounts' => $this->bankAccountOptions(),
            'withholdingTaxes' => $this->withholdingOptions(),
            'preselected' => [
                'contact_id' => $contactId === '' ? null : $contactId,
                'bill_id' => $bill?->id,
                'amount' => $bill?->balanceDue(),
            ],
            'outstanding' => $contactId === '' ? [] : $this->outstandingFor($contactId),
            'baseCurrency' => $organization->base_currency,
            'today' => Carbon::now()->toDateString(),
        ]);
    }

    /**
     * What we still owe a vendor, for the allocation table.
     *
     * Its own endpoint so choosing a vendor does not reload the form and lose
     * what has already been typed.
     */
    public function outstanding(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize(Permission::PurchasesRecordPayment->value);

        abort_unless($contact->organization_id === $this->tenant->organization()->id, 404);

        return response()->json(['outstanding' => $this->outstandingFor($contact->id)]);
    }

    public function store(StoreVendorPaymentRequest $request): RedirectResponse
    {
        $this->authorize(Permission::PurchasesRecordPayment->value);

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
            $payment = $this->recordVendorPayment->handle(
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
        } catch (PurchaseDocumentRefused|PostingRefused|\InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        $unallocated = BigDecimal::of($payment->unallocatedAmount());

        return redirect()
            ->route('purchases.payments.index')
            ->with('success', sprintf(
                '%s recorded: %s %s to %s.%s',
                $payment->number,
                $payment->currency,
                $payment->amount,
                $contact->display_name,
                $unallocated->isPositive()
                    ? " {$unallocated->toScale(2)} is held as an advance with them."
                    : '',
            ));
    }

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
            PurchaseDocument::query()
                ->ofType(PurchaseDocumentType::Bill)
                ->where('contact_id', $contactId)
                ->outstanding()
                ->orderBy('due_date')
                ->orderBy('number')
                ->get()
                ->filter(static fn (PurchaseDocument $bill): bool => BigDecimal::of($bill->balanceDue())->isPositive())
                ->map(static fn (PurchaseDocument $bill): array => [
                    'id' => $bill->id,
                    'number' => $bill->number,
                    'vendor_reference' => $bill->vendor_reference,
                    'issue_date' => $bill->issue_date->toDateString(),
                    'due_date' => $bill->due_date?->toDateString(),
                    'currency' => $bill->currency,
                    'total' => $bill->total,
                    'balance_due' => $bill->balanceDue(),
                    'is_overdue' => $bill->isOverdue(),
                    'days_overdue' => $bill->daysOverdue(),
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * @return list<array{value: string, label: string, currency: string|null}>
     */
    private function vendorOptions(): array
    {
        return array_values(
            Contact::query()
                ->usable()
                ->vendors()
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
     * Where the money can have left from.
     *
     * Cash and bank subtypes only. Offering the whole chart would invite
     * somebody to pay a vendor "out of" an expense account, which balances
     * and is nonsense.
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
                    // The current rate, so the form computes the deduction
                    // rather than making the user reach for a calculator.
                    'rate' => $tax->components->first()?->rate,
                ])
                ->all(),
        );
    }
}
