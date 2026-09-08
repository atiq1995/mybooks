<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchases;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Exceptions\UnbalancedJournal;
use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Purchases\Actions\ApprovePurchaseDocument;
use App\Domain\Purchases\Actions\ConvertPurchaseDocument;
use App\Domain\Purchases\Actions\SavePurchaseDocument;
use App\Domain\Purchases\Actions\VoidPurchaseDocument;
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Exceptions\PurchaseDocumentRefused;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Purchases\Models\PurchaseDocumentLine;
use App\Domain\Tax\Models\Tax;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StorePurchaseDocumentRequest;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase orders, bills and vendor credits.
 *
 * One controller for three documents, matching the one model: the type
 * arrives from the route, so `/purchases/bills` and `/purchases/orders` are
 * the same code with a different label.
 *
 * Every write goes through an Action. The controller resolves input,
 * authorises, and turns a domain refusal into an error the user can act on.
 *
 * @see ACCOUNTING_RULES.md §4.6, §6
 */
final class PurchaseDocumentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SavePurchaseDocument $savePurchaseDocument,
        private readonly ApprovePurchaseDocument $approvePurchaseDocument,
        private readonly VoidPurchaseDocument $voidPurchaseDocument,
        private readonly ConvertPurchaseDocument $convertPurchaseDocument,
    ) {}

    public function index(Request $request, string $type): Response
    {
        $this->authorize(Permission::PurchasesView->value);

        $documentType = $this->resolveType($type);
        $organization = $this->tenant->organization();

        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        $documents = PurchaseDocument::query()
            ->ofType($documentType)
            ->with('contact:id,display_name')
            ->when(
                $search !== '',
                fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('number', 'ilike', "%{$search}%")
                        // The vendor's own number is searched too: it is the
                        // one people actually have in front of them when
                        // looking a bill up.
                        ->orWhere('vendor_reference', 'ilike', "%{$search}%")
                        ->orWhere('reference', 'ilike', "%{$search}%")
                        ->orWhereHas(
                            'contact',
                            fn ($contact) => $contact->where('display_name', 'ilike', "%{$search}%"),
                        );
                }),
            )
            ->when(
                $status !== '' && $status !== 'overdue',
                fn ($query) => $query->where('status', $status),
            )
            // Overdue is a predicate, not a stored status; see the model.
            ->when($status === 'overdue', fn ($query) => $query
                ->outstanding()
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', Carbon::now()->startOfDay()))
            ->when(
                ($contactId = $request->string('contact')->toString()) !== '',
                fn ($query) => $query->where('contact_id', $contactId),
            )
            ->orderByDesc('issue_date')
            ->orderByDesc('number')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Purchases/Documents/Index', [
            'type' => $this->typeProps($documentType),
            'documents' => [
                'data' => array_values(array_map(
                    fn (PurchaseDocument $document): array => $this->summarise($document),
                    $documents->items(),
                )),
                'links' => $documents->linkCollection()->toArray(),
                'total' => $documents->total(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
            ],
            'summary' => $this->summaryFor($documentType),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'contact' => $request->string('contact')->toString(),
            ],
            'statuses' => $this->statusOptions($documentType),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request, $documentType),
        ]);
    }

    /**
     * The line editor, for a new document or a draft.
     */
    public function edit(Request $request, string $type, ?string $number = null): Response
    {
        $this->authorize(Permission::PurchasesCreate->value);

        $documentType = $this->resolveType($type);
        $organization = $this->tenant->organization();

        $document = $number === null
            ? null
            : PurchaseDocument::query()
                ->ofType($documentType)
                ->where('number', $number)
                ->with('lines')
                ->firstOrFail();

        if ($document !== null && ! $document->status->isEditable()) {
            abort(403, "This {$documentType->label()} has been approved and can no longer be edited.");
        }

        return Inertia::render('Purchases/Documents/Edit', [
            'type' => $this->typeProps($documentType),
            'document' => $document === null ? null : [
                ...$this->summarise($document),
                'notes' => $document->notes,
                'terms' => $document->terms,
                'discount_type' => $document->discount_type,
                'discount_value' => $document->discount_value,
                'prices_include_tax' => $document->prices_include_tax,
                'expires_on' => $document->expires_on?->toDateString(),
                'lines' => array_values($document->lines
                    ->map(fn (PurchaseDocumentLine $line): array => [
                        'id' => $line->id,
                        'item_id' => $line->item_id,
                        'description' => $line->description,
                        'unit' => $line->unit,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'discount_type' => $line->discount_type,
                        'discount_value' => $line->discount_value,
                        'tax_id' => $line->tax_id,
                        'debit_account_id' => $line->debit_account_id,
                        'tax_is_claimable' => $line->tax_is_claimable,
                        'total' => $line->total,
                    ])
                    ->all()),
            ],
            'vendors' => $this->vendorOptions(),
            'items' => $this->itemOptions(),
            'taxes' => $this->taxOptions(),
            'accounts' => $this->costAccountOptions(),
            'baseCurrency' => $organization->base_currency,
            'today' => Carbon::now()->toDateString(),
            // Only offered for a vendor credit, which is the one document
            // that has to name what it credits.
            'creditableBills' => $documentType === PurchaseDocumentType::VendorCredit
                ? $this->creditableBills()
                : [],
        ]);
    }

    public function store(StorePurchaseDocumentRequest $request, string $type): RedirectResponse
    {
        $this->authorize(Permission::PurchasesCreate->value);

        $documentType = $this->resolveType($type);
        $validated = $request->validated();

        try {
            $document = $this->savePurchaseDocument->handle(
                type: $documentType,
                attributes: $validated,
                lines: $this->linesFrom($validated),
                actor: $request->user(),
            );
        } catch (PurchaseDocumentRefused|\InvalidArgumentException $exception) {
            /*
             * The duplicate-bill refusal is about the vendor reference field,
             * not the lines, and putting it on the right field is the
             * difference between a message the user can act on and one they
             * have to hunt for.
             */
            return back()
                ->withErrors([$this->fieldFor($exception) => $exception->getMessage()])
                ->withInput();
        }

        $this->attachCreditTarget($document, $validated);

        return redirect()
            ->route('purchases.documents.show', [$documentType->urlSegment(), $document->number])
            ->with('success', "{$documentType->label()} {$document->number} saved as a draft.");
    }

    public function update(
        StorePurchaseDocumentRequest $request,
        string $type,
        string $number,
    ): RedirectResponse {
        $this->authorize(Permission::PurchasesUpdate->value);

        $documentType = $this->resolveType($type);

        $document = PurchaseDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        /*
         * Only a draft is editable. The action refuses an approved document
         * independently, but with an exception whose message has nothing to
         * do with any field — so say it as a page-level error instead.
         */
        if ($document->status->isIssued()) {
            return back()->with('error', sprintf(
                '%s %s is %s and can no longer be edited. Raise a vendor credit against it, '.
                'or void it and start again — an approved document is a record, not a draft.',
                $documentType->label(),
                $number,
                $document->status->label(),
            ));
        }

        $validated = $request->validated();

        try {
            $document = $this->savePurchaseDocument->handle(
                type: $documentType,
                attributes: $validated,
                lines: $this->linesFrom($validated),
                document: $document,
                actor: $request->user(),
            );
        } catch (PurchaseDocumentRefused|\InvalidArgumentException $exception) {
            return back()
                ->withErrors([$this->fieldFor($exception) => $exception->getMessage()])
                ->withInput();
        }

        $this->attachCreditTarget($document, $validated);

        return redirect()
            ->route('purchases.documents.show', [$documentType->urlSegment(), $document->number])
            ->with('success', "{$documentType->label()} {$document->number} updated.");
    }

    public function show(Request $request, string $type, string $number): Response
    {
        $this->authorize(Permission::PurchasesView->value);

        $documentType = $this->resolveType($type);
        $organization = $this->tenant->organization();

        $document = PurchaseDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->with([
                'lines.taxes',
                'lines.item:id,name',
                'contact:id,display_name,email',
                'journalEntry:id,entry_no',
                'voidJournalEntry:id,entry_no',
                'convertedFrom:id,number,type',
                'creditsDocument:id,number,type',
                'allocations.payment:id,number,payment_date',
            ])
            ->firstOrFail();

        return Inertia::render('Purchases/Documents/Show', [
            'type' => $this->typeProps($documentType),
            'document' => [
                ...$this->summarise($document),
                'notes' => $document->notes,
                'terms' => $document->terms,
                'discount_type' => $document->discount_type,
                'discount_value' => $document->discount_value,
                'prices_include_tax' => $document->prices_include_tax,
                'expires_on' => $document->expires_on?->toDateString(),
                'billing_address' => $document->billing_address,
                'approved_at' => $document->approved_at?->toDayDateTimeString(),
                'voided_at' => $document->voided_at?->toDayDateTimeString(),
                'journal_entry_no' => $document->journalEntry?->entry_no,
                'void_journal_entry_no' => $document->voidJournalEntry?->entry_no,
                'converted_from' => $document->convertedFrom === null ? null : [
                    'number' => $document->convertedFrom->number,
                    'type' => $document->convertedFrom->type->value,
                    'url' => $document->convertedFrom->type->urlSegment(),
                ],
                'credits_document' => $document->creditsDocument === null ? null : [
                    'number' => $document->creditsDocument->number,
                    'url' => $document->creditsDocument->type->urlSegment(),
                ],
                'lines' => $this->lineDetail($document),
                // Summed per component, split by whether it can be reclaimed
                // — the shape the input half of the return is filed in.
                'tax_summary' => $this->taxSummary($document),
                'payments' => array_values($document->allocations
                    ->map(static fn ($allocation): array => [
                        'number' => $allocation->payment?->number,
                        'date' => $allocation->payment?->payment_date?->toDateString(),
                        'amount' => $allocation->amount,
                    ])
                    ->all()),
            ],
            'contact' => $this->contactProps($document),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request, $documentType, $document),
        ]);
    }

    /**
     * Approve a bill or a vendor credit, or send a purchase order.
     *
     * One permission, not two.
     *
     * The sales side needs `accounting.post` alongside `sales.send`, because
     * "send" is not a posting authority and a bookkeeper has it. Here the
     * permission IS the posting authority: `purchases.approve` exists for
     * exactly this decision, and it is what the Approver role is built
     * around. Requiring `accounting.post` as well would lock Approver — a
     * role whose entire purpose is authorising spend — out of the one action
     * it exists to perform.
     */
    public function approve(Request $request, string $type, string $number): RedirectResponse
    {
        $this->authorize(Permission::PurchasesApprove->value);

        $documentType = $this->resolveType($type);

        $document = PurchaseDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        try {
            $this->approvePurchaseDocument->handle(
                document: $document,
                actor: $request->user(),
                allowClosedPeriod: $request->boolean('post_to_closed_period')
                    && ($request->user()?->can(Permission::AccountingPostToClosedPeriod->value) ?? false),
            );
        } catch (PurchaseDocumentRefused|PostingRefused|UnbalancedJournal $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            '%s %s %s%s.',
            $documentType->label(),
            $number,
            $documentType === PurchaseDocumentType::PurchaseOrder ? 'sent' : 'approved',
            $documentType->posts() ? ' and posted' : '',
        ));
    }

    public function void(Request $request, string $type, string $number): RedirectResponse
    {
        $this->authorize(Permission::PurchasesDelete->value);

        $documentType = $this->resolveType($type);

        // Voiding an approved bill posts the reversing entry, so the ledger
        // write needs the ledger permission — the same argument as the sales
        // side, and unlike approval this one has no dedicated permission.
        if ($documentType->posts()) {
            $this->authorize(Permission::AccountingReverse->value);
        }

        $document = PurchaseDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reason = $request->string('reason')->toString();

        try {
            $this->voidPurchaseDocument->handle(
                document: $document,
                actor: $request->user(),
                reason: $reason === '' ? null : $reason,
            );
        } catch (PurchaseDocumentRefused|PostingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$documentType->label()} {$number} voided.");
    }

    public function convert(Request $request, string $type, string $number): RedirectResponse
    {
        $this->authorize(Permission::PurchasesCreate->value);

        $documentType = $this->resolveType($type);

        $document = PurchaseDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        $request->validate([
            'to' => ['required', 'string', 'in:'.implode(',', PurchaseDocumentType::values())],
        ]);

        // Read back through the typed accessor rather than the validated
        // array, which is `mixed` as far as static analysis is concerned.
        $target = PurchaseDocumentType::from($request->string('to')->toString());

        try {
            $converted = $this->convertPurchaseDocument->handle(
                source: $document,
                to: $target,
                actor: $request->user(),
            );
        } catch (PurchaseDocumentRefused|\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('purchases.documents.show', [$target->urlSegment(), $converted->number])
            ->with('success', sprintf(
                '%s %s created from %s. Check it against what the vendor charged, then approve it.',
                $target->label(),
                $converted->number,
                $number,
            ));
    }

    public function destroy(Request $request, string $type, string $number): RedirectResponse
    {
        $this->authorize(Permission::PurchasesDelete->value);

        $documentType = $this->resolveType($type);

        $document = PurchaseDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        // Only a draft. The model refuses an approved one independently, but
        // a readable message here beats an exception page.
        if ($document->status->isIssued()) {
            return back()->with('error', sprintf(
                '%s %s has been %s. Void it instead — that posts a reversing entry and '.
                'leaves both on the record, where deleting would leave a gap in the '.
                'numbering.',
                $documentType->label(),
                $number,
                $documentType === PurchaseDocumentType::PurchaseOrder ? 'sent' : 'approved',
            ));
        }

        $document->delete();

        return redirect()
            ->route('purchases.documents.index', $documentType->urlSegment())
            ->with('success', "Draft {$number} deleted.");
    }

    /**
     * Which field a refusal belongs to.
     *
     * Only the duplicate-bill guard is about a specific field; everything
     * else is about the document as a whole, and the line editor is where
     * that reads best.
     */
    private function fieldFor(\Throwable $exception): string
    {
        return str_contains($exception->getMessage(), 'reference')
            ? 'vendor_reference'
            : 'lines';
    }

    /**
     * Each line, with its tax breakdown.
     *
     * @return list<array<string, mixed>>
     */
    private function lineDetail(PurchaseDocument $document): array
    {
        $lines = [];

        foreach ($document->lines as $line) {
            $taxes = [];

            foreach ($line->taxes as $tax) {
                $taxes[] = [
                    'name' => $tax->component_name,
                    'rate' => $tax->rate,
                    'amount' => $tax->tax_amount,
                    'is_claimable' => $tax->is_claimable,
                ];
            }

            $lines[] = [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'item_name' => $line->item?->name,
                'description' => $line->description,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'document_discount_amount' => $line->document_discount_amount,
                'taxable' => $line->taxable,
                'tax_total' => $line->tax_total,
                'tax_is_claimable' => $line->tax_is_claimable,
                // What this line actually cost, tax included where the tax
                // could not be reclaimed. The figure that hit the ledger.
                'capitalised_cost' => $line->capitalisedCost(),
                'total' => $line->total,
                'taxes' => $taxes,
            ];
        }

        return $lines;
    }

    /**
     * The vendor, for the detail page's header.
     *
     * @return array<string, mixed>
     */
    private function contactProps(PurchaseDocument $document): array
    {
        $contact = Contact::query()->find($document->contact_id);

        if ($contact === null) {
            return [
                'id' => $document->contact_id,
                'display_name' => 'Unknown',
                'email' => null,
                'payable' => '0.0000',
            ];
        }

        return [
            'id' => $contact->id,
            'display_name' => $contact->display_name,
            'email' => $contact->email,
            'payable' => $contact->payableBalance(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(PurchaseDocument $document): array
    {
        return [
            'id' => $document->id,
            'number' => $document->number,
            'contact_id' => $document->contact_id,
            'contact_name' => $document->contact?->display_name,
            'issue_date' => $document->issue_date->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'vendor_reference' => $document->vendor_reference,
            'reference' => $document->reference,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'status_tone' => $document->status->tone(),
            'currency' => $document->currency,
            'exchange_rate' => $document->exchange_rate,
            'subtotal' => $document->subtotal,
            'discount_total' => $document->discount_total,
            'tax_total' => $document->tax_total,
            'tax_claimable_total' => $document->tax_claimable_total,
            'tax_capitalised' => $document->taxCapitalised(),
            'total' => $document->total,
            'total_base' => $document->total_base,
            'amount_paid' => $document->amount_paid,
            'amount_credited' => $document->amount_credited,
            'balance_due' => $document->balanceDue(),
            'is_overdue' => $document->isOverdue(),
            'days_overdue' => $document->daysOverdue(),
            'is_editable' => $document->status->isEditable(),
            'is_issued' => $document->status->isIssued(),
            'is_void' => $document->status->isVoid(),
            'is_foreign_currency' => $document->isForeignCurrency(),
        ];
    }

    /**
     * Totals across the list, so the header can say what is owed without the
     * user adding up a page of figures.
     *
     * @return array<string, string>
     */
    private function summaryFor(PurchaseDocumentType $type): array
    {
        /** @var object{outstanding: string|null, overdue: string|null, draft: string|null}|null $row */
        $row = PurchaseDocument::query()
            ->ofType($type)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status IN ('open','partially_paid','overdue') ".
                'THEN total - amount_paid - amount_credited ELSE 0 END), 0) AS outstanding',
            )
            // The date is bound from PHP rather than taken as CURRENT_DATE, so
            // the header and the filter below it read the same clock.
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status IN ('open','partially_paid','overdue') ".
                'AND due_date IS NOT NULL AND due_date < ? '.
                'THEN total - amount_paid - amount_credited ELSE 0 END), 0) AS overdue',
                [Carbon::now()->startOfDay()->toDateString()],
            )
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'draft' THEN total ELSE 0 END), 0) AS draft")
            ->first();

        return [
            'outstanding' => (string) BigDecimal::of((string) ($row->outstanding ?? '0'))->toScale(4),
            'overdue' => (string) BigDecimal::of((string) ($row->overdue ?? '0'))->toScale(4),
            'draft' => (string) BigDecimal::of((string) ($row->draft ?? '0'))->toScale(4),
        ];
    }

    /**
     * Tax per component, with what is recoverable stated separately.
     *
     * Both figures, because they answer different questions: the first is
     * what the vendor charged, the second is what we can get back. A single
     * total would leave the reader unable to tell a cheap purchase from an
     * expensive one whose tax happens to be blocked.
     *
     * @return list<array{name: string, rate: string, amount: string, claimable: string}>
     */
    private function taxSummary(PurchaseDocument $document): array
    {
        $totals = [];
        $claimable = [];

        foreach ($document->lines as $line) {
            foreach ($line->taxes as $tax) {
                $key = $tax->component_name.'|'.$tax->rate;
                $amount = BigDecimal::of($tax->tax_amount);

                $totals[$key] = isset($totals[$key]) ? $totals[$key]->plus($amount) : $amount;

                $claimable[$key] = ($claimable[$key] ?? BigDecimal::zero())
                    ->plus($tax->is_claimable ? $amount : BigDecimal::zero());
            }
        }

        $summary = [];

        foreach ($totals as $key => $amount) {
            [$name, $rate] = explode('|', (string) $key);

            $summary[] = [
                'name' => $name,
                'rate' => $rate,
                'amount' => (string) $amount->toScale(4),
                'claimable' => (string) ($claimable[$key] ?? BigDecimal::zero())->toScale(4),
            ];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private function linesFrom(array $validated): array
    {
        $lines = $validated['lines'] ?? [];

        if (! is_array($lines)) {
            return [];
        }

        $rows = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            /** @var array<string, mixed> $line */
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * Record which bill a vendor credit credits.
     *
     * Set after saving rather than through the Action, because it is a
     * relationship between two documents rather than part of one's own shape.
     *
     * @param  array<string, mixed>  $validated
     */
    private function attachCreditTarget(PurchaseDocument $document, array $validated): void
    {
        if ($document->type !== PurchaseDocumentType::VendorCredit) {
            return;
        }

        $target = $validated['credits_document_id'] ?? null;

        if (! is_string($target) || $target === '') {
            return;
        }

        // Through the model, so the organisation scope applies and an id from
        // another organisation simply is not found.
        $bill = PurchaseDocument::query()
            ->ofType(PurchaseDocumentType::Bill)
            ->find($target);

        if ($bill === null) {
            return;
        }

        $document->forceFill(['credits_document_id' => $bill->id])->save();
    }

    /**
     * Bills a vendor credit could be raised against.
     *
     * @return list<array{value: string, label: string}>
     */
    private function creditableBills(): array
    {
        return array_values(
            PurchaseDocument::query()
                ->ofType(PurchaseDocumentType::Bill)
                ->with('contact:id,display_name')
                ->where('status', '!=', PurchaseDocumentStatus::Draft->value)
                ->where('status', '!=', PurchaseDocumentStatus::Void->value)
                ->orderByDesc('issue_date')
                ->limit(200)
                ->get()
                ->map(static fn (PurchaseDocument $bill): array => [
                    'value' => $bill->id,
                    'label' => sprintf(
                        '%s — %s (%s %s outstanding)',
                        $bill->number,
                        $bill->contact === null ? '' : $bill->contact->display_name,
                        $bill->currency,
                        $bill->balanceDue(),
                    ),
                ])
                ->all(),
        );
    }

    /**
     * @return list<array{value: string, label: string, currency: string|null, terms: int}>
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
                    'terms' => $contact->payment_terms_days,
                ])
                ->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemOptions(): array
    {
        return array_values(
            Item::query()
                ->purchasable()
                ->orderBy('name')
                ->get()
                ->map(static fn (Item $item): array => [
                    'value' => $item->id,
                    'label' => $item->sku === null ? $item->name : "{$item->sku} — {$item->name}",
                    'description' => $item->description ?? $item->name,
                    'unit' => $item->unit,
                    'price' => $item->purchase_price,
                    'tax_id' => $item->purchase_tax_id,
                    'debit_account_id' => $item->purchase_account_id,
                ])
                ->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taxOptions(): array
    {
        return array_values(
            Tax::query()
                ->usable()
                ->forPurchase()
                ->with('components')
                ->orderBy('name')
                ->get()
                ->map(static fn (Tax $tax): array => [
                    'value' => $tax->id,
                    'label' => $tax->name,
                    'code' => $tax->code,
                    'inclusive_default' => $tax->is_inclusive_default,
                ])
                ->all(),
        );
    }

    /**
     * Accounts a purchase line may be charged to.
     *
     * Expense and asset accounts, and nothing else. Offered as a list because
     * unlike sales — where the revenue account is nearly always the item's —
     * choosing where a cost lands is a routine part of entering a bill.
     *
     * @return list<array{value: string, label: string, type: string}>
     */
    private function costAccountOptions(): array
    {
        return array_values(
            Account::query()
                ->postable()
                ->whereIn('type', ['expense', 'asset'])
                ->orderBy('code')
                ->get()
                ->map(static fn (Account $account): array => [
                    'value' => $account->id,
                    'label' => "{$account->code} — {$account->name}",
                    'type' => $account->type->value,
                ])
                ->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function typeProps(PurchaseDocumentType $type): array
    {
        return [
            'value' => $type->value,
            'label' => $type->label(),
            'plural' => $type->plural(),
            'segment' => $type->urlSegment(),
            'posts' => $type->posts(),
            'has_due_date' => $type->hasDueDate(),
            'issue_verb' => $type->issueVerb(),
            'convertible_to' => array_values(array_map(
                static fn (PurchaseDocumentType $target): array => [
                    'value' => $target->value,
                    'label' => $target->label(),
                ],
                match ($type) {
                    PurchaseDocumentType::PurchaseOrder => [PurchaseDocumentType::Bill],
                    default => [],
                },
            )),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(PurchaseDocumentType $type): array
    {
        $statuses = match ($type) {
            PurchaseDocumentType::PurchaseOrder => [
                PurchaseDocumentStatus::Draft,
                PurchaseDocumentStatus::Sent,
                PurchaseDocumentStatus::Closed,
                PurchaseDocumentStatus::Void,
            ],
            PurchaseDocumentType::Bill => [
                PurchaseDocumentStatus::Draft,
                PurchaseDocumentStatus::Open,
                PurchaseDocumentStatus::PartiallyPaid,
                PurchaseDocumentStatus::Paid,
                PurchaseDocumentStatus::Overdue,
                PurchaseDocumentStatus::Void,
            ],
            PurchaseDocumentType::VendorCredit => [
                PurchaseDocumentStatus::Draft,
                PurchaseDocumentStatus::Open,
                PurchaseDocumentStatus::Void,
            ],
        };

        return array_values(array_map(
            static fn (PurchaseDocumentStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            $statuses,
        ));
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(
        Request $request,
        PurchaseDocumentType $type,
        ?PurchaseDocument $document = null,
    ): array {
        $user = $request->user();

        // Voiding an approved document posts a reversal, so the button
        // follows the same second permission the route enforces.
        $mayReverse = ! $type->posts()
            || ($user?->can(Permission::AccountingReverse->value) ?? false);

        return [
            'create' => $user?->can(Permission::PurchasesCreate->value) ?? false,
            'update' => $user?->can(Permission::PurchasesUpdate->value) ?? false,
            'approve' => ($user?->can(Permission::PurchasesApprove->value) ?? false)
                && ($document === null || ! $document->status->isIssued()),
            'void' => ($user?->can(Permission::PurchasesDelete->value) ?? false)
                && $mayReverse
                && $document !== null
                && $document->status->isIssued()
                && ! $document->status->isVoid()
                && BigDecimal::of($document->amount_paid)->isZero(),
            'pay' => ($user?->can(Permission::PurchasesRecordPayment->value) ?? false)
                && $document !== null
                && $document->type === PurchaseDocumentType::Bill
                && $document->status->isOutstanding(),
            'post_to_closed_period' => $user?->can(Permission::AccountingPostToClosedPeriod->value) ?? false,
        ];
    }

    /**
     * Turn a URL segment into a type.
     *
     * A 404 rather than a validation error: `/purchases/widgets` is not a
     * malformed request, it is a page that does not exist.
     */
    private function resolveType(string $segment): PurchaseDocumentType
    {
        foreach (PurchaseDocumentType::cases() as $type) {
            if ($type->urlSegment() === $segment) {
                return $type;
            }
        }

        abort(404);
    }
}
