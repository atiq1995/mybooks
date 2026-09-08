<?php

declare(strict_types=1);

namespace App\Http\Controllers\Expenses;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Exceptions\UnbalancedJournal;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Expenses\Actions\ApproveExpense;
use App\Domain\Expenses\Actions\RebillExpenses;
use App\Domain\Expenses\Actions\RejectExpense;
use App\Domain\Expenses\Actions\SaveExpense;
use App\Domain\Expenses\Actions\SubmitExpense;
use App\Domain\Expenses\Actions\VoidExpense;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Exceptions\ExpenseRefused;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseLine;
use App\Domain\Expenses\Models\MileageRate;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Tax\Models\Tax;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseRequest;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Expenses, and the approval they go through.
 *
 * Every write goes through an Action. The controller resolves input,
 * authorises, and turns a domain refusal into an error the user can act on.
 *
 * @see ACCOUNTING_RULES.md §4.8, §6
 */
final class ExpenseController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SaveExpense $saveExpense,
        private readonly SubmitExpense $submitExpense,
        private readonly ApproveExpense $approveExpense,
        private readonly RejectExpense $rejectExpense,
        private readonly VoidExpense $voidExpense,
        private readonly RebillExpenses $rebillExpenses,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::ExpensesView->value);

        $organization = $this->tenant->organization();

        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();
        $view = $request->string('view')->toString();

        $expenses = Expense::query()
            ->with(['contact:id,display_name', 'billableContact:id,display_name'])
            ->when(
                $search !== '',
                fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('number', 'ilike', "%{$search}%")
                        ->orWhere('merchant', 'ilike', "%{$search}%")
                        ->orWhere('reference', 'ilike', "%{$search}%");
                }),
            )
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            // Two saved views, because they are the two questions people
            // arrive with: what needs approving, and what needs billing on.
            ->when($view === 'approvals', fn ($query) => $query->awaitingApproval())
            ->when($view === 'to_bill', fn ($query) => $query->awaitingRebill())
            ->orderByDesc('expense_date')
            ->orderByDesc('number')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Expenses/Index', [
            'expenses' => [
                'data' => array_values(array_map(
                    fn (Expense $expense): array => $this->summarise($expense),
                    $expenses->items(),
                )),
                'links' => $expenses->linkCollection()->toArray(),
                'total' => $expenses->total(),
                'from' => $expenses->firstItem(),
                'to' => $expenses->lastItem(),
            ],
            'summary' => $this->summary(),
            'filters' => ['search' => $search, 'status' => $status, 'view' => $view],
            'statuses' => array_values(array_map(
                static fn (ExpenseStatus $s): array => [
                    'value' => $s->value,
                    'label' => $s->label(),
                ],
                ExpenseStatus::cases(),
            )),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    public function edit(Request $request, ?string $number = null): Response
    {
        $this->authorize(Permission::ExpensesCreate->value);

        $organization = $this->tenant->organization();

        $expense = $number === null
            ? null
            : Expense::query()->where('number', $number)->with('lines')->firstOrFail();

        if ($expense !== null && ! $expense->status->isEditable()) {
            abort(403, 'This expense has been approved and can no longer be edited.');
        }

        return Inertia::render('Expenses/Edit', [
            'expense' => $expense === null ? null : [
                ...$this->summarise($expense),
                'notes' => $expense->notes,
                'lines' => array_values($expense->lines
                    ->map(fn (ExpenseLine $line): array => [
                        'id' => $line->id,
                        'kind' => $line->kind,
                        'description' => $line->description,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'unit' => $line->unit,
                        'tax_id' => $line->tax_id,
                        'debit_account_id' => $line->debit_account_id,
                        'tax_is_claimable' => $line->tax_is_claimable,
                        'total' => $line->total,
                    ])
                    ->all()),
            ],
            'contacts' => $this->contactOptions(),
            'customers' => $this->customerOptions(),
            'people' => $this->peopleOptions(),
            'accounts' => $this->costAccountOptions(),
            'bankAccounts' => $this->bankAccountOptions(),
            'taxes' => $this->taxOptions(),
            'mileageRates' => $this->mileageRateOptions(),
            'baseCurrency' => $organization->base_currency,
            'today' => Carbon::now()->toDateString(),
        ]);
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $this->authorize(Permission::ExpensesCreate->value);

        $validated = $request->validated();

        try {
            $expense = $this->saveExpense->handle(
                attributes: $validated,
                lines: $this->linesFrom($validated),
                actor: $request->user(),
            );
        } catch (ExpenseRefused|\InvalidArgumentException $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('expenses.show', $expense->number)
            ->with('success', sprintf(
                'Expense %s saved. Attach the receipt, then submit it for approval.',
                $expense->number,
            ));
    }

    public function update(StoreExpenseRequest $request, string $number): RedirectResponse
    {
        $this->authorize(Permission::ExpensesUpdate->value);

        $expense = Expense::query()->where('number', $number)->firstOrFail();

        if (! $expense->status->isEditable()) {
            return back()->with('error', ExpenseRefused::notEditable(
                $expense->number,
                $expense->status->label(),
            )->getMessage());
        }

        $validated = $request->validated();

        try {
            $expense = $this->saveExpense->handle(
                attributes: $validated,
                lines: $this->linesFrom($validated),
                expense: $expense,
                actor: $request->user(),
            );
        } catch (ExpenseRefused|\InvalidArgumentException $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('expenses.show', $expense->number)
            ->with('success', "Expense {$expense->number} updated.");
    }

    public function show(Request $request, string $number): Response
    {
        $this->authorize(Permission::ExpensesView->value);

        $organization = $this->tenant->organization();

        $expense = Expense::query()
            ->where('number', $number)
            ->with([
                'lines.taxes',
                'lines.debitAccount:id,code,name',
                'contact:id,display_name',
                'billableContact:id,display_name',
                'billedDocument:id,number,type',
                'paidThroughAccount:id,code,name',
                'reimburseUser:id,name,email',
                'submitter:id,name',
                'approver:id,name',
                'journalEntry:id,entry_no',
                'voidJournalEntry:id,entry_no',
                'attachments',
            ])
            ->firstOrFail();

        return Inertia::render('Expenses/Show', [
            'expense' => [
                ...$this->summarise($expense),
                'notes' => $expense->notes,
                'approved_at' => $expense->approved_at?->toDayDateTimeString(),
                'submitted_at' => $expense->submitted_at?->toDayDateTimeString(),
                'rejected_at' => $expense->rejected_at?->toDayDateTimeString(),
                'rejection_reason' => $expense->rejection_reason,
                'voided_at' => $expense->voided_at?->toDayDateTimeString(),
                'submitted_by_name' => $expense->submitter?->name,
                'approved_by_name' => $expense->approver?->name,
                'reimburse_user_name' => $expense->reimburseUser?->name,
                'paid_through' => $expense->paidThroughAccount === null ? null : sprintf(
                    '%s — %s',
                    $expense->paidThroughAccount->code,
                    $expense->paidThroughAccount->name,
                ),
                'journal_entry_no' => $expense->journalEntry?->entry_no,
                'void_journal_entry_no' => $expense->voidJournalEntry?->entry_no,
                'billed_invoice' => $expense->billedDocument === null ? null : [
                    'number' => $expense->billedDocument->number,
                    'url' => $expense->billedDocument->type->urlSegment(),
                ],
                'lines' => $this->lineDetail($expense),
                'tax_summary' => $this->taxSummary($expense),
                'receipts' => array_values($expense->attachments
                    ->map(static fn ($attachment): array => [
                        'id' => $attachment->id,
                        'name' => $attachment->original_name,
                        'size' => $attachment->humanSize(),
                        'is_image' => $attachment->isImage(),
                        'is_pdf' => $attachment->isPdf(),
                        'uploaded_at' => $attachment->created_at->toDayDateTimeString(),
                    ])
                    ->all()),
            ],
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request, $expense),
        ]);
    }

    public function submit(Request $request, string $number): RedirectResponse
    {
        // Submitting is not a write to the books, so it needs only the
        // permission to record an expense — which the claimant has.
        $this->authorize(Permission::ExpensesCreate->value);

        $expense = Expense::query()->where('number', $number)->firstOrFail();

        try {
            $this->submitExpense->handle(
                expense: $expense,
                actor: $request->user(),
            );
        } catch (ExpenseRefused|\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Expense %s submitted. Somebody with approval rights has to review it.',
            $number,
        ));
    }

    /**
     * Approve, which posts.
     *
     * `expenses.approve` is the posting authority on this side, the same way
     * `purchases.approve` is on that one — so it stands alone rather than
     * needing `accounting.post` as well. The Approver role, whose whole
     * purpose is authorising spend, has the former and not the latter.
     */
    public function approve(Request $request, string $number): RedirectResponse
    {
        $this->authorize(Permission::ExpensesApprove->value);

        $expense = Expense::query()->where('number', $number)->firstOrFail();

        try {
            $this->approveExpense->handle(
                expense: $expense,
                actor: $request->user(),
                allowClosedPeriod: $request->boolean('post_to_closed_period')
                    && ($request->user()?->can(Permission::AccountingPostToClosedPeriod->value) ?? false),
                /*
                 * Self-approval is permitted only for an organisation with
                 * nobody else to ask. Refusing outright would make the module
                 * unusable for a sole trader; permitting it silently would
                 * make the approval step meaningless everywhere else. So the
                 * organisation's own membership count decides, and the audit
                 * row records that it happened.
                 */
                allowSelfApproval: $this->isSolePractitioner(),
            );
        } catch (ExpenseRefused|PostingRefused|UnbalancedJournal $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Expense {$number} approved and posted.");
    }

    public function reject(Request $request, string $number): RedirectResponse
    {
        $this->authorize(Permission::ExpensesApprove->value);

        $expense = Expense::query()->where('number', $number)->firstOrFail();

        $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->rejectExpense->handle(
                expense: $expense,
                reason: $request->string('reason')->toString(),
                actor: $request->user(),
            );
        } catch (ExpenseRefused|\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Expense %s sent back. Whoever claimed it can edit and resubmit.',
            $number,
        ));
    }

    public function void(Request $request, string $number): RedirectResponse
    {
        $this->authorize(Permission::ExpensesDelete->value);
        // Voiding posts a reversing entry, so the ledger write needs the
        // ledger permission. Unlike approval, it has no dedicated one.
        $this->authorize(Permission::AccountingReverse->value);

        $expense = Expense::query()->where('number', $number)->firstOrFail();

        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reason = $request->string('reason')->toString();

        try {
            $this->voidExpense->handle(
                expense: $expense,
                actor: $request->user(),
                reason: $reason === '' ? null : $reason,
            );
        } catch (ExpenseRefused|PostingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Expense {$number} voided.");
    }

    public function destroy(Request $request, string $number): RedirectResponse
    {
        $this->authorize(Permission::ExpensesDelete->value);

        $expense = Expense::query()->where('number', $number)->firstOrFail();

        if (! $expense->status->isDeletable()) {
            return back()->with('error', sprintf(
                'Expense %s has been approved and posted. Void it instead — that posts a '.
                'reversing entry and leaves both on the record.',
                $number,
            ));
        }

        $expense->delete();

        return redirect()
            ->route('expenses.index')
            ->with('success', "Expense {$number} deleted.");
    }

    /**
     * Put chosen expenses onto a draft invoice.
     */
    public function rebill(Request $request): RedirectResponse
    {
        $this->authorize(Permission::ExpensesView->value);
        // Creating the invoice is a sales write, so it needs the sales
        // permission — not merely the right to see the expense.
        $this->authorize(Permission::SalesCreate->value);

        $request->validate([
            'expenses' => ['required', 'array', 'min:1', 'max:100'],
            'expenses.*' => ['required', 'uuid'],
        ]);

        // Read back through the typed accessor: the validated array is
        // `mixed` as far as static analysis is concerned.
        $ids = array_values(array_filter(
            $request->collect('expenses')->all(),
            static fn (mixed $id): bool => is_string($id),
        ));

        $expenses = array_values(
            Expense::query()->whereKey($ids)->with('lines')->get()->all(),
        );

        if ($expenses === []) {
            return back()->with('error', 'None of those expenses could be found.');
        }

        try {
            $invoice = $this->rebillExpenses->handle(
                expenses: $expenses,
                actor: $request->user(),
            );
        } catch (ExpenseRefused|\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('sales.documents.show', ['invoices', $invoice->number])
            ->with('success', sprintf(
                'Draft invoice %s created from %d %s, at cost. Check the amounts before '.
                'issuing it.',
                $invoice->number,
                count($expenses),
                count($expenses) === 1 ? 'expense' : 'expenses',
            ));
    }

    /**
     * Whether there is anybody else who could approve.
     *
     * An organisation with one member has nobody to ask, and refusing to let
     * them approve their own expense would leave the module unusable. Counted
     * rather than configured, so it becomes false the moment a second person
     * is invited — which is exactly when the control starts to mean
     * something.
     */
    private function isSolePractitioner(): bool
    {
        return OrganizationMembership::query()
            ->where('organization_id', $this->tenant->organization()->getKey())
            ->count() <= 1;
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
     * @return list<array<string, mixed>>
     */
    private function lineDetail(Expense $expense): array
    {
        $lines = [];

        foreach ($expense->lines as $line) {
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
                'kind' => $line->kind,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'unit' => $line->unit,
                'account' => $line->debitAccount === null ? null : sprintf(
                    '%s — %s',
                    $line->debitAccount->code,
                    $line->debitAccount->name,
                ),
                'taxable' => $line->taxable,
                'tax_total' => $line->tax_total,
                'tax_is_claimable' => $line->tax_is_claimable,
                'capitalised_cost' => $line->capitalisedCost(),
                'total' => $line->total,
                'taxes' => $taxes,
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'number' => $expense->number,
            'contact_id' => $expense->contact_id,
            'merchant' => $expense->merchant,
            'expense_date' => $expense->expense_date->toDateString(),
            'payment_mode' => $expense->payment_mode->value,
            'payment_mode_label' => $expense->payment_mode->label(),
            'paid_through_account_id' => $expense->paid_through_account_id,
            'reimburse_user_id' => $expense->reimburse_user_id,
            'reference' => $expense->reference,
            'status' => $expense->status->value,
            'status_label' => $expense->status->label(),
            'status_tone' => $expense->status->tone(),
            'currency' => $expense->currency,
            'exchange_rate' => $expense->exchange_rate,
            'prices_include_tax' => $expense->prices_include_tax,
            'subtotal' => $expense->subtotal,
            'tax_total' => $expense->tax_total,
            'tax_claimable_total' => $expense->tax_claimable_total,
            'tax_capitalised' => $expense->taxCapitalised(),
            'total' => $expense->total,
            'total_base' => $expense->total_base,
            'is_billable' => $expense->is_billable,
            'billable_contact_id' => $expense->billable_contact_id,
            'billable_contact_name' => $expense->billableContact?->display_name,
            'is_billed' => $expense->billed_document_id !== null,
            'awaiting_rebill' => $expense->isAwaitingRebill(),
            'has_receipt' => $expense->attachments()->exists(),
            'is_editable' => $expense->status->isEditable(),
            'is_posted' => $expense->status->isPosted(),
            'is_void' => $expense->status->isVoid(),
            'is_foreign_currency' => $expense->isForeignCurrency(),
        ];
    }

    /**
     * The three figures the screen exists to show.
     *
     * @return array<string, string|int>
     */
    private function summary(): array
    {
        /** @var object{awaiting: string|null, awaiting_count: int|null, approved: string|null}|null $row */
        $row = Expense::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'submitted' THEN total ELSE 0 END), 0) AS awaiting")
            ->selectRaw("COUNT(CASE WHEN status = 'submitted' THEN 1 END) AS awaiting_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'approved' AND payment_mode = 'reimbursable' THEN total ELSE 0 END), 0) AS approved")
            ->first();

        /** @var object{to_bill: string|null}|null $billable */
        $billable = Expense::query()
            ->awaitingRebill()
            ->selectRaw('COALESCE(SUM(total), 0) AS to_bill')
            ->first();

        return [
            'awaiting_approval' => (string) BigDecimal::of((string) ($row->awaiting ?? '0'))->toScale(4),
            'awaiting_count' => (int) ($row->awaiting_count ?? 0),
            // What we owe our own people, which is the figure a payroll run
            // needs and nothing else on this screen shows.
            'owed_to_people' => (string) BigDecimal::of((string) ($row->approved ?? '0'))->toScale(4),
            'to_rebill' => (string) BigDecimal::of((string) ($billable->to_bill ?? '0'))->toScale(4),
        ];
    }

    /**
     * @return list<array{name: string, rate: string, amount: string, claimable: string}>
     */
    private function taxSummary(Expense $expense): array
    {
        $totals = [];
        $claimable = [];

        foreach ($expense->lines as $line) {
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
     * @return list<array{value: string, label: string}>
     */
    private function contactOptions(): array
    {
        return array_values(
            Contact::query()
                ->usable()
                ->orderBy('display_name')
                ->get()
                ->map(static fn (Contact $contact): array => [
                    'value' => $contact->id,
                    'label' => $contact->display_name,
                ])
                ->all(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
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
                ])
                ->all(),
        );
    }

    /**
     * Who can be reimbursed: the people in this organisation.
     *
     * @return list<array{value: string, label: string}>
     */
    private function peopleOptions(): array
    {
        $userIds = OrganizationMembership::query()
            ->where('organization_id', $this->tenant->organization()->getKey())
            ->pluck('user_id');

        return array_values(
            User::query()
                ->whereKey($userIds)
                ->orderBy('name')
                ->get()
                ->map(static fn (User $user): array => [
                    'value' => $user->id,
                    'label' => $user->name,
                ])
                ->all(),
        );
    }

    /**
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
     * @return list<array{value: string, label: string}>
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
                ->forPurchase()
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
     * @return list<array{value: string, label: string, unit: string, rate: string}>
     */
    private function mileageRateOptions(): array
    {
        return array_values(
            MileageRate::query()
                ->current()
                ->orderBy('unit')
                ->get()
                ->map(static fn (MileageRate $rate): array => [
                    'value' => $rate->id,
                    'label' => "{$rate->name} ({$rate->rate} per {$rate->unit})",
                    'unit' => $rate->unit,
                    'rate' => $rate->rate,
                ])
                ->all(),
        );
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request, ?Expense $expense = null): array
    {
        $user = $request->user();

        $mayApprove = $user?->can(Permission::ExpensesApprove->value) ?? false;

        /*
         * The approve button is hidden from whoever submitted it, not merely
         * refused. A control that appears and then always fails teaches
         * people that the software is broken rather than that the rule
         * exists.
         */
        $isOwnClaim = $expense !== null
            && $expense->submitted_by !== null
            && $user !== null
            && $expense->submitted_by === $user->getKey();

        return [
            'create' => $user?->can(Permission::ExpensesCreate->value) ?? false,
            'update' => ($user?->can(Permission::ExpensesUpdate->value) ?? false)
                && ($expense === null || $expense->status->isEditable()),
            'submit' => ($user?->can(Permission::ExpensesCreate->value) ?? false)
                && $expense !== null
                && $expense->status->isEditable(),
            'approve' => $mayApprove
                && $expense !== null
                && $expense->status->isSubmitted()
                && (! $isOwnClaim || $this->isSolePractitioner()),
            'reject' => $mayApprove && $expense !== null && $expense->status->isSubmitted(),
            // Two permissions, because voiding posts a reversing entry and
            // has no dedicated permission of its own.
            'void' => $user !== null
                && $user->can(Permission::ExpensesDelete->value)
                && $user->can(Permission::AccountingReverse->value)
                && $expense !== null
                && $expense->status->isPosted()
                && $expense->billed_document_id === null,
            'delete' => ($user?->can(Permission::ExpensesDelete->value) ?? false)
                && $expense !== null
                && $expense->status->isDeletable(),
            'attach' => ($user?->can(Permission::ExpensesUpdate->value) ?? false)
                && ($expense === null || $expense->status->isEditable()),
            'rebill' => $user?->can(Permission::SalesCreate->value) ?? false,
            'post_to_closed_period' => $user?->can(Permission::AccountingPostToClosedPeriod->value) ?? false,
            'self_approval_allowed' => $this->isSolePractitioner(),
        ];
    }
}
