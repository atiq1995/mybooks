<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Exceptions\UnbalancedJournal;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Actions\ConvertSalesDocument;
use App\Domain\Sales\Actions\IssueSalesDocument;
use App\Domain\Sales\Actions\SaveSalesDocument;
use App\Domain\Sales\Actions\VoidSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Sales\Models\SalesDocumentLine;
use App\Domain\Tax\Models\Tax;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesDocumentRequest;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estimates, sales orders, invoices and credit notes.
 *
 * One controller for four documents, matching the one model. The type arrives
 * from the route, so `/sales/invoices` and `/sales/estimates` are the same
 * code with a different label — which is why the line editor, the totals and
 * the conversion flow behave identically across all four.
 *
 * Every write goes through an Action. The controller resolves input,
 * authorises, and turns a domain refusal into an error the user can act on.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final class SalesDocumentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SaveSalesDocument $saveSalesDocument,
        private readonly IssueSalesDocument $issueSalesDocument,
        private readonly VoidSalesDocument $voidSalesDocument,
        private readonly ConvertSalesDocument $convertSalesDocument,
    ) {}

    public function index(Request $request, string $type): Response
    {
        $this->authorize(Permission::SalesView->value);

        $documentType = $this->resolveType($type);
        $organization = $this->tenant->organization();

        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        $documents = SalesDocument::query()
            ->ofType($documentType)
            ->with('contact:id,display_name')
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
            ->when($status !== '' && $status !== 'overdue', fn ($query) => $query->where('status', $status))
            /*
             * Overdue is not a stored status. It changes at midnight without
             * anything happening to the document, so a column would need a
             * nightly job to stay true — and a job that can fail is worse
             * than a predicate that cannot.
             */
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

        return Inertia::render('Sales/Documents/Index', [
            'type' => $this->typeProps($documentType),
            'documents' => [
                'data' => array_values(array_map(
                    fn (SalesDocument $document): array => $this->summarise($document),
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
        $this->authorize(Permission::SalesCreate->value);

        $documentType = $this->resolveType($type);
        $organization = $this->tenant->organization();

        $document = $number === null
            ? null
            : SalesDocument::query()
                ->ofType($documentType)
                ->where('number', $number)
                ->with('lines')
                ->firstOrFail();

        if ($document !== null && ! $document->status->isEditable()) {
            abort(403, "This {$documentType->label()} has been issued and can no longer be edited.");
        }

        return Inertia::render('Sales/Documents/Edit', [
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
                    ->map(fn (SalesDocumentLine $line): array => [
                        'id' => $line->id,
                        'item_id' => $line->item_id,
                        'description' => $line->description,
                        'unit' => $line->unit,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'discount_type' => $line->discount_type,
                        'discount_value' => $line->discount_value,
                        'tax_id' => $line->tax_id,
                        'revenue_account_id' => $line->revenue_account_id,
                        'total' => $line->total,
                    ])
                    ->all()),
            ],
            'customers' => $this->customerOptions(),
            'items' => $this->itemOptions(),
            'taxes' => $this->taxOptions(),

            'baseCurrency' => $organization->base_currency,
            'today' => Carbon::now()->toDateString(),
            // Only offered for a credit note, which is the one document that
            // has to name what it credits.
            'creditableInvoices' => $documentType === SalesDocumentType::CreditNote
                ? $this->creditableInvoices()
                : [],
        ]);
    }

    public function store(StoreSalesDocumentRequest $request, string $type): RedirectResponse
    {
        $this->authorize(Permission::SalesCreate->value);

        $documentType = $this->resolveType($type);
        $validated = $request->validated();

        try {
            $document = $this->saveSalesDocument->handle(
                type: $documentType,
                attributes: $validated,
                lines: $this->linesFrom($validated),
                actor: $request->user(),
            );
        } catch (SalesDocumentRefused|\InvalidArgumentException $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        $this->attachCreditTarget($document, $validated);

        return redirect()
            ->route('sales.documents.show', [$documentType->urlSegment(), $document->number])
            ->with('success', "{$documentType->label()} {$document->number} saved as a draft.");
    }

    public function update(
        StoreSalesDocumentRequest $request,
        string $type,
        string $number,
    ): RedirectResponse {
        $this->authorize(Permission::SalesUpdate->value);

        $documentType = $this->resolveType($type);

        $document = SalesDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        /*
         * Only a draft is editable. The action refuses an issued document
         * independently, but it does so with an exception whose message has
         * nothing to do with any field — flashing that into the `lines` error
         * bag would hang a status complaint off the line editor. Say it as a
         * page-level error instead, the way destroy() does.
         */
        if ($document->status->isIssued()) {
            return back()->with('error', sprintf(
                '%s %s is %s and can no longer be edited. Issue a credit note against it, '.
                'or void it and start again — an issued document is a record, not a draft.',
                $documentType->label(),
                $number,
                $document->status->label(),
            ));
        }

        $validated = $request->validated();

        try {
            $document = $this->saveSalesDocument->handle(
                type: $documentType,
                attributes: $validated,
                lines: $this->linesFrom($validated),
                document: $document,
                actor: $request->user(),
            );
        } catch (SalesDocumentRefused|\InvalidArgumentException $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        $this->attachCreditTarget($document, $validated);

        return redirect()
            ->route('sales.documents.show', [$documentType->urlSegment(), $document->number])
            ->with('success', "{$documentType->label()} {$document->number} updated.");
    }

    public function show(Request $request, string $type, string $number): Response
    {
        $this->authorize(Permission::SalesView->value);

        $documentType = $this->resolveType($type);
        $organization = $this->tenant->organization();

        $document = SalesDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->with([
                'contact',
                'lines.taxes',
                'lines.item:id,name',
                'journalEntry:id,entry_no',
                'voidJournalEntry:id,entry_no',
                'convertedFrom:id,number,type',
                'creditsDocument:id,number,type',
                'allocations.payment:id,number,payment_date',
            ])
            ->firstOrFail();

        return Inertia::render('Sales/Documents/Show', [
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
                'issued_at' => $document->issued_at?->toDayDateTimeString(),
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
                // Summed per component: the shape the tax return is filed in.
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

    public function issue(Request $request, string $type, string $number): RedirectResponse
    {
        $this->authorize(Permission::SalesSend->value);

        $documentType = $this->resolveType($type);

        /*
         * Issuing an invoice or a credit note writes to the ledger, so it
         * needs the permission to post as well as the permission to send.
         *
         * Without this, a Bookkeeper — whose whole definition is "prepares
         * documents, cannot post" — could post revenue by pressing Issue,
         * and the separation of duties the roles exist for would hold
         * everywhere except the one screen that matters most. Estimates and
         * sales orders are commitments rather than entries, so they need only
         * SalesSend.
         */
        if ($documentType->posts()) {
            $this->authorize(Permission::AccountingPost->value);
        }

        $document = SalesDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        try {
            $this->issueSalesDocument->handle(
                document: $document,
                actor: $request->user(),
                allowClosedPeriod: $request->boolean('post_to_closed_period')
                    && ($request->user()?->can(Permission::AccountingPostToClosedPeriod->value) ?? false),
            );
        } catch (SalesDocumentRefused|PostingRefused|UnbalancedJournal $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            '%s %s issued%s.',
            $documentType->label(),
            $number,
            $documentType->posts() ? ' and posted' : '',
        ));
    }

    public function void(Request $request, string $type, string $number): RedirectResponse
    {
        $this->authorize(Permission::SalesDelete->value);

        $documentType = $this->resolveType($type);

        // Voiding an issued invoice posts the reversing entry. Same argument
        // as issue(): the ledger write needs the ledger permission.
        if ($documentType->posts()) {
            $this->authorize(Permission::AccountingReverse->value);
        }

        $document = SalesDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reason = $request->string('reason')->toString();

        try {
            $this->voidSalesDocument->handle(
                document: $document,
                actor: $request->user(),
                reason: $reason === '' ? null : $reason,
            );
        } catch (SalesDocumentRefused|PostingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$documentType->label()} {$number} voided.");
    }

    public function convert(Request $request, string $type, string $number): RedirectResponse
    {
        $this->authorize(Permission::SalesCreate->value);

        $documentType = $this->resolveType($type);

        $document = SalesDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        $request->validate([
            'to' => ['required', 'in:sales_order,invoice'],
        ]);

        $target = SalesDocumentType::from($request->string('to')->toString());

        try {
            $created = $this->convertSalesDocument->handle(
                source: $document,
                to: $target,
                actor: $request->user(),
            );
        } catch (SalesDocumentRefused|\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('sales.documents.show', [$target->urlSegment(), $created->number])
            ->with('success', sprintf(
                '%s %s created from %s. It is a draft — check the dates before issuing it.',
                $target->label(),
                $created->number,
                $number,
            ));
    }

    public function destroy(Request $request, string $type, string $number): RedirectResponse
    {
        $this->authorize(Permission::SalesDelete->value);

        $documentType = $this->resolveType($type);

        $document = SalesDocument::query()
            ->ofType($documentType)
            ->where('number', $number)
            ->firstOrFail();

        // Only a draft. The model refuses an issued one independently, but a
        // readable message here is better than an exception page.
        if ($document->status->isIssued()) {
            return back()->with('error', sprintf(
                '%s %s has been issued. Void it instead — that posts a reversing entry and '.
                'leaves both on the record, where deleting would leave a gap in the numbering.',
                $documentType->label(),
                $number,
            ));
        }

        $document->delete();

        return redirect()
            ->route('sales.documents.index', $documentType->urlSegment())
            ->with('success', "Draft {$number} deleted.");
    }

    /**
     * Each line, with its tax breakdown.
     *
     * A named method rather than a nested map: the inner breakdown makes the
     * outer closure's return type unresolvable to static analysis, and the
     * nesting was already the hardest part of this file to read.
     *
     * @return list<array<string, mixed>>
     */
    private function lineDetail(SalesDocument $document): array
    {
        $lines = [];

        foreach ($document->lines as $line) {
            $taxes = [];

            foreach ($line->taxes as $tax) {
                $taxes[] = [
                    'name' => $tax->component_name,
                    'rate' => $tax->rate,
                    'amount' => $tax->tax_amount,
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
                'total' => $line->total,
                'taxes' => $taxes,
            ];
        }

        return $lines;
    }

    /**
     * The customer, for the detail page's header.
     *
     * Loaded rather than reached through the relation so the type is known.
     * A document without a contact means the row was written outside the
     * application, and the page says so rather than erroring.
     *
     * @return array<string, mixed>
     */
    private function contactProps(SalesDocument $document): array
    {
        $contact = Contact::query()->find($document->contact_id);

        if ($contact === null) {
            return [
                'id' => $document->contact_id,
                'display_name' => 'Unknown',
                'email' => null,
                'outstanding' => '0.0000',
            ];
        }

        return [
            'id' => $contact->id,
            'display_name' => $contact->display_name,
            'email' => $contact->email,
            'outstanding' => $contact->outstandingBalance(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(SalesDocument $document): array
    {
        return [
            'id' => $document->id,
            'number' => $document->number,
            'contact_id' => $document->contact_id,
            'contact_name' => $document->contact?->display_name,
            'issue_date' => $document->issue_date->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'reference' => $document->reference,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'status_tone' => $document->status->tone(),
            'currency' => $document->currency,
            'exchange_rate' => $document->exchange_rate,
            'subtotal' => $document->subtotal,
            'discount_total' => $document->discount_total,
            'tax_total' => $document->tax_total,
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
     * Totals across the list, so the header can say what is outstanding
     * without the user adding up a page of figures.
     *
     * @return array<string, string>
     */
    private function summaryFor(SalesDocumentType $type): array
    {
        /** @var object{outstanding: string|null, overdue: string|null, draft: string|null}|null $row */
        $row = SalesDocument::query()
            ->ofType($type)
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('sent','open','partially_paid','overdue') THEN total - amount_paid - amount_credited ELSE 0 END), 0) AS outstanding")
            /*
             * The date is bound from PHP, not taken as CURRENT_DATE.
             *
             * The list's overdue filter and the document's own isOverdue()
             * both ask PHP what day it is, and the application server's
             * timezone is the one the user's fiscal calendar is set in. Two
             * clocks in one screen means the header can say nothing is
             * overdue while the filter below it lists an overdue invoice.
             */
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status IN ('sent','open','partially_paid','overdue') ".
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
     * @return list<array{name: string, rate: string, amount: string}>
     */
    private function taxSummary(SalesDocument $document): array
    {
        $totals = [];

        foreach ($document->lines as $line) {
            foreach ($line->taxes as $tax) {
                $key = $tax->component_name.'|'.$tax->rate;

                $totals[$key] = isset($totals[$key])
                    ? $totals[$key]->plus(BigDecimal::of($tax->tax_amount))
                    : BigDecimal::of($tax->tax_amount);
            }
        }

        $summary = [];

        foreach ($totals as $key => $amount) {
            [$name, $rate] = explode('|', (string) $key);

            $summary[] = [
                'name' => $name,
                'rate' => $rate,
                'amount' => (string) $amount->toScale(4),
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
     * Record which invoice a credit note credits.
     *
     * Set after saving rather than through the Action, because it is not part
     * of a document's own shape — it is a relationship between two of them,
     * and only credit notes have it.
     *
     * @param  array<string, mixed>  $validated
     */
    private function attachCreditTarget(SalesDocument $document, array $validated): void
    {
        if ($document->type !== SalesDocumentType::CreditNote) {
            return;
        }

        $target = $validated['credits_document_id'] ?? null;

        if (! is_string($target) || $target === '') {
            return;
        }

        // Through the model, so the organisation scope applies and an id from
        // another organisation simply is not found.
        $invoice = SalesDocument::query()
            ->ofType(SalesDocumentType::Invoice)
            ->find($target);

        if ($invoice === null) {
            return;
        }

        $document->forceFill(['credits_document_id' => $invoice->id])->save();
    }

    /**
     * Invoices a credit note could be raised against.
     *
     * @return list<array{value: string, label: string}>
     */
    private function creditableInvoices(): array
    {
        return array_values(
            SalesDocument::query()
                ->ofType(SalesDocumentType::Invoice)
                ->with('contact:id,display_name')
                ->where('status', '!=', SalesDocumentStatus::Draft->value)
                ->where('status', '!=', SalesDocumentStatus::Void->value)
                ->orderByDesc('issue_date')
                ->limit(200)
                ->get()
                ->map(static fn (SalesDocument $invoice): array => [
                    'value' => $invoice->id,
                    'label' => sprintf(
                        '%s — %s (%s %s outstanding)',
                        $invoice->number,
                        $invoice->contact === null ? '' : $invoice->contact->display_name,
                        $invoice->currency,
                        $invoice->balanceDue(),
                    ),
                ])
                ->all(),
        );
    }

    /**
     * @return list<array{value: string, label: string, currency: string|null, terms: int}>
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
                ->sellable()
                ->orderBy('name')
                ->get()
                ->map(static fn (Item $item): array => [
                    'value' => $item->id,
                    'label' => $item->sku === null ? $item->name : "{$item->sku} — {$item->name}",
                    'description' => $item->description ?? $item->name,
                    'unit' => $item->unit,
                    'price' => $item->sale_price,
                    'tax_id' => $item->sales_tax_id,
                    'revenue_account_id' => $item->sales_account_id,
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
                ->forSales()
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
     * @return array<string, mixed>
     */
    private function typeProps(SalesDocumentType $type): array
    {
        return [
            'value' => $type->value,
            'label' => $type->label(),
            'plural' => $type->plural(),
            'segment' => $type->urlSegment(),
            'posts' => $type->posts(),
            'has_due_date' => $type->hasDueDate(),
            'convertible_to' => array_values(array_map(
                static fn (SalesDocumentType $target): array => [
                    'value' => $target->value,
                    'label' => $target->label(),
                ],
                match ($type) {
                    SalesDocumentType::Estimate => [
                        SalesDocumentType::SalesOrder,
                        SalesDocumentType::Invoice,
                    ],
                    SalesDocumentType::SalesOrder => [SalesDocumentType::Invoice],
                    default => [],
                },
            )),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(SalesDocumentType $type): array
    {
        $statuses = match ($type) {
            SalesDocumentType::Estimate => [
                SalesDocumentStatus::Draft,
                SalesDocumentStatus::Sent,
                SalesDocumentStatus::Accepted,
                SalesDocumentStatus::Declined,
                SalesDocumentStatus::Expired,
                SalesDocumentStatus::Void,
            ],
            SalesDocumentType::SalesOrder => [
                SalesDocumentStatus::Draft,
                SalesDocumentStatus::Sent,
                SalesDocumentStatus::Closed,
                SalesDocumentStatus::Void,
            ],
            SalesDocumentType::Invoice => [
                SalesDocumentStatus::Draft,
                SalesDocumentStatus::Sent,
                SalesDocumentStatus::PartiallyPaid,
                SalesDocumentStatus::Paid,
                SalesDocumentStatus::Overdue,
                SalesDocumentStatus::Void,
            ],
            SalesDocumentType::CreditNote => [
                SalesDocumentStatus::Draft,
                SalesDocumentStatus::Sent,
                SalesDocumentStatus::Void,
            ],
        };

        return array_values(array_map(
            static fn (SalesDocumentStatus $status): array => [
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
        SalesDocumentType $type,
        ?SalesDocument $document = null,
    ): array {
        $user = $request->user();

        /*
         * The same second permission issue() and void() enforce. It is
         * computed here too so the button is absent rather than present and
         * refused — the server decides either way, but a control that only
         * ever fails is a lie about what the user can do.
         */
        $mayPost = ! $type->posts() || ($user?->can(Permission::AccountingPost->value) ?? false);
        $mayReverse = ! $type->posts() || ($user?->can(Permission::AccountingReverse->value) ?? false);

        return [
            'create' => $user?->can(Permission::SalesCreate->value) ?? false,
            'update' => $user?->can(Permission::SalesUpdate->value) ?? false,
            'issue' => ($user?->can(Permission::SalesSend->value) ?? false)
                && $mayPost
                && ($document === null || ! $document->status->isIssued()),
            'void' => ($user?->can(Permission::SalesDelete->value) ?? false)
                && $mayReverse
                && $document !== null
                && $document->status->isIssued()
                && ! $document->status->isVoid()
                && BigDecimal::of($document->amount_paid)->isZero(),
            'record_payment' => ($user?->can(Permission::SalesRecordPayment->value) ?? false)
                && $document !== null
                && $document->type === SalesDocumentType::Invoice
                && $document->status->isOutstanding(),
            'post_to_closed_period' => $user?->can(Permission::AccountingPostToClosedPeriod->value) ?? false,
        ];
    }

    /**
     * Turn a URL segment into a type.
     *
     * A 404 rather than a validation error: `/sales/widgets` is not a
     * malformed request, it is a page that does not exist.
     */
    private function resolveType(string $segment): SalesDocumentType
    {
        foreach (SalesDocumentType::cases() as $type) {
            if ($type->urlSegment() === $segment) {
                return $type;
            }
        }

        abort(404);
    }
}
