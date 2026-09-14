<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Actions\GenerateRecurringInvoices;
use App\Domain\Sales\Actions\SaveRecurringInvoice;
use App\Domain\Sales\Enums\RecurrenceFrequency;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Domain\Sales\Models\RecurringInvoiceLine;
use App\Domain\Sales\Models\RecurringInvoiceRun;
use App\Domain\Tax\Models\Tax;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreRecurringInvoiceRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recurring invoices — templates, not invoices.
 *
 * The screens keep that distinction visible, because it is the thing people
 * get wrong: a template has no number and no balance, and the invoices it has
 * produced are separate documents that live on the invoice list with
 * everything else.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final class RecurringInvoiceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SaveRecurringInvoice $saveRecurringInvoice,
        private readonly GenerateRecurringInvoices $generateRecurringInvoices,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::SalesView->value);

        $organization = $this->tenant->organization();
        $status = $request->string('status')->toString();

        $templates = RecurringInvoice::query()
            ->with('contact:id,display_name')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'paused' THEN 1 ELSE 2 END")
            ->orderBy('next_run_on')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Sales/Recurring/Index', [
            'templates' => [
                'data' => array_values(array_map(
                    fn (RecurringInvoice $template): array => $this->summarise($template),
                    $templates->items(),
                )),
                'links' => $templates->linkCollection()->toArray(),
                'total' => $templates->total(),
                'from' => $templates->firstItem(),
                'to' => $templates->lastItem(),
            ],
            'filters' => ['status' => $status],
            'baseCurrency' => $organization->base_currency,
            'today' => Carbon::now()->toDateString(),
            'can' => $this->abilities($request),
        ]);
    }

    public function edit(Request $request, ?string $template = null): Response
    {
        $this->authorize(Permission::SalesCreate->value);

        $organization = $this->tenant->organization();

        $model = $template === null
            ? null
            : RecurringInvoice::query()->with('lines')->findOrFail($template);

        return Inertia::render('Sales/Recurring/Edit', [
            'template' => $model === null ? null : [
                ...$this->summarise($model),
                'lines' => array_values($model->lines
                    ->map(static fn (RecurringInvoiceLine $line): array => [
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
                    ])
                    ->all()),
            ],
            'customers' => $this->customerOptions(),
            'items' => $this->itemOptions(),
            'taxes' => $this->taxOptions(),
            'frequencies' => array_values(array_map(
                static fn (RecurrenceFrequency $frequency): array => [
                    'value' => $frequency->value,
                    'label' => $frequency->label(),
                ],
                RecurrenceFrequency::cases(),
            )),
            'baseCurrency' => $organization->base_currency,
            'today' => Carbon::now()->toDateString(),
        ]);
    }

    public function store(StoreRecurringInvoiceRequest $request): RedirectResponse
    {
        $this->authorize(Permission::SalesCreate->value);

        $validated = $request->validated();

        try {
            $template = $this->saveRecurringInvoice->handle(
                attributes: $validated,
                lines: $this->linesFrom($validated),
                actor: $request->user(),
            );
        } catch (SalesDocumentRefused|\InvalidArgumentException $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('sales.recurring.index')
            ->with('success', sprintf(
                '"%s" saved. %s, next on %s.',
                $template->name,
                $template->describeSchedule(),
                $template->next_run_on?->toDateString() ?? 'never',
            ));
    }

    public function update(StoreRecurringInvoiceRequest $request, string $template): RedirectResponse
    {
        $this->authorize(Permission::SalesUpdate->value);

        $model = RecurringInvoice::query()->findOrFail($template);

        $validated = $request->validated();

        try {
            $model = $this->saveRecurringInvoice->handle(
                attributes: $validated,
                lines: $this->linesFrom($validated),
                template: $model,
                actor: $request->user(),
            );
        } catch (SalesDocumentRefused|\InvalidArgumentException $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('sales.recurring.index')
            ->with('success', "\"{$model->name}\" updated.");
    }

    public function show(Request $request, string $template): Response
    {
        $this->authorize(Permission::SalesView->value);

        $organization = $this->tenant->organization();

        $model = RecurringInvoice::query()
            ->with(['lines.tax:id,name', 'contact:id,display_name', 'runs.document:id,number,type,total,status'])
            ->findOrFail($template);

        return Inertia::render('Sales/Recurring/Show', [
            'template' => [
                ...$this->summarise($model),
                'notes' => $model->notes,
                'terms' => $model->terms,
                'lines' => array_values($model->lines
                    ->map(static fn (RecurringInvoiceLine $line): array => [
                        'id' => $line->id,
                        'description' => $line->description,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'tax_name' => $line->tax?->name,
                    ])
                    ->all()),
                /*
                 * The history, including the failures. A run that could not
                 * produce an invoice is the thing somebody needs to see —
                 * hiding it would leave a customer unbilled with no sign of
                 * why.
                 */
                'runs' => array_values($model->runs
                    ->map(static fn (RecurringInvoiceRun $run): array => [
                        'id' => $run->id,
                        'scheduled_for' => $run->scheduled_for->toDateString(),
                        'ran_at' => $run->ran_at->toDayDateTimeString(),
                        'outcome' => $run->outcome,
                        'failure_reason' => $run->failure_reason,
                        'number' => $run->document?->number,
                        'total' => $run->document?->total,
                        'status' => $run->document?->status->value,
                    ])
                    ->all()),
            ],
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request, $model),
        ]);
    }

    /**
     * Pause, resume, or end a schedule.
     */
    public function status(Request $request, string $template): RedirectResponse
    {
        $this->authorize(Permission::SalesUpdate->value);

        $model = RecurringInvoice::query()->findOrFail($template);

        $request->validate([
            'status' => ['required', 'in:active,paused,ended'],
        ]);

        try {
            $model = $this->saveRecurringInvoice->setStatus(
                template: $model,
                status: $request->string('status')->toString(),
                actor: $request->user(),
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            '"%s" is now %s.%s',
            $model->name,
            $model->status,
            $model->status === 'active' && $model->next_run_on?->isPast() === true
                ? ' It has periods to catch up on, which the next run will bill.'
                : '',
        ));
    }

    /**
     * Run one template now, rather than waiting for the scheduler.
     *
     * Safe to press twice: the unique index on (template, scheduled date)
     * means an occurrence generates once, whatever races it.
     */
    public function generate(Request $request, string $template): RedirectResponse
    {
        // Generating bills a customer, so it needs the permission to issue a
        // document rather than merely to edit a template.
        $this->authorize(Permission::SalesSend->value);

        $model = RecurringInvoice::query()->findOrFail($template);

        /*
         * An auto-issuing template posts revenue, so running it needs the
         * permission to post — exactly as pressing Issue on a single invoice
         * does.
         *
         * Without this, the separation of duties would hold on the invoice
         * screen and have a way round it here: a Bookkeeper, whose whole
         * definition is "prepares documents, cannot post", has `sales.send`
         * and would be able to post a year of revenue by pressing Run now.
         * A template that only produces drafts needs no such permission,
         * because it recognises nothing.
         */
        if ($model->auto_issue) {
            $this->authorize(Permission::AccountingPost->value);
        }

        $result = $this->generateRecurringInvoices->runTemplate(
            template: $model,
            actor: $request->user(),
        );

        if ($result['generated'] === 0 && $result['failed'] === 0) {
            return back()->with('success', sprintf(
                'Nothing due yet. The next invoice is dated %s.',
                $model->refresh()->next_run_on?->toDateString() ?? 'never',
            ));
        }

        if ($result['failed'] > 0) {
            return back()->with('error',
                'That occurrence could not be generated. The reason is recorded against '.
                'the schedule below — fix it and run again, and it picks up where it '.
                'stopped.'
            );
        }

        return back()->with('success', sprintf(
            '%d invoice%s generated.',
            $result['generated'],
            $result['generated'] === 1 ? '' : 's',
        ));
    }

    public function destroy(Request $request, string $template): RedirectResponse
    {
        $this->authorize(Permission::SalesDelete->value);

        $model = RecurringInvoice::query()->findOrFail($template);

        /*
         * The template goes; the invoices it produced stay.
         *
         * They are ordinary documents with their own numbers and their own
         * journal entries — deleting a standing instruction cannot unpost
         * what it already billed. The run rows go with it, which is why the
         * link from an invoice back to its template is not relied on for
         * anything accounting-related.
         */
        $name = $model->name;
        $model->delete();

        return redirect()
            ->route('sales.recurring.index')
            ->with('success', sprintf(
                '"%s" deleted. The invoices it already generated are unaffected.',
                $name,
            ));
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
            if (is_array($line)) {
                /** @var array<string, mixed> $line */
                $rows[] = $line;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(RecurringInvoice $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'contact_id' => $template->contact_id,
            'contact_name' => $template->contact?->display_name,
            'frequency' => $template->frequency->value,
            'interval' => $template->interval,
            'schedule' => $template->describeSchedule(),
            'starts_on' => $template->starts_on->toDateString(),
            'ends_on' => $template->ends_on?->toDateString(),
            'max_occurrences' => $template->max_occurrences,
            'next_run_on' => $template->next_run_on?->toDateString(),
            'last_run_on' => $template->last_run_on?->toDateString(),
            'occurrences_generated' => $template->occurrences_generated,
            'status' => $template->status,
            'auto_issue' => $template->auto_issue,
            'payment_terms_days' => $template->payment_terms_days,
            'currency' => $template->currency,
            'prices_include_tax' => $template->prices_include_tax,
            'discount_type' => $template->discount_type,
            'discount_value' => $template->discount_value,
            'reference' => $template->reference,
            // Whether it owes periods it has not billed — the thing somebody
            // scanning the list actually wants to spot.
            'is_overdue_to_run' => $template->isActive()
                && $template->next_run_on !== null
                && $template->next_run_on->isPast(),
        ];
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
     * @return list<array{value: string, label: string, code: string}>
     */
    private function taxOptions(): array
    {
        return array_values(
            Tax::query()
                ->usable()
                ->forSales()
                ->orderBy('name')
                ->get()
                ->map(static fn (Tax $tax): array => [
                    'value' => $tax->id,
                    'label' => $tax->name,
                    'code' => $tax->code,
                ])
                ->all(),
        );
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request, ?RecurringInvoice $template = null): array
    {
        $user = $request->user();

        $maySend = $user?->can(Permission::SalesSend->value) ?? false;
        $mayPost = $user?->can(Permission::AccountingPost->value) ?? false;

        return [
            'create' => $user?->can(Permission::SalesCreate->value) ?? false,
            'update' => $user?->can(Permission::SalesUpdate->value) ?? false,
            'delete' => $user?->can(Permission::SalesDelete->value) ?? false,
            /*
             * Generating bills a customer, so it takes the permission to
             * send — and, where the template issues automatically, the
             * permission to post as well. The button follows the same rule
             * the route enforces, so a control that would only ever be
             * refused is absent rather than present.
             */
            'generate' => $maySend
                && ($template === null || ! $template->auto_issue || $mayPost)
                && ($template === null || $template->isActive()),
        ];
    }
}
