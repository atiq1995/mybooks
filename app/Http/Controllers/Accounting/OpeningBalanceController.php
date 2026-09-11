<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Actions\EnterOpeningBalances;
use App\Domain\Accounting\Actions\EnterOpeningDocument;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Sales\Models\SalesDocument;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bringing balances forward from a previous system.
 *
 * The screen is built around one number: what is left in opening balance
 * equity. That account is a plug on purpose — it holds the difference while a
 * migration is half done, so every intermediate state balances — and when the
 * migration is complete it is exactly what the business was worth on the day
 * it moved. Anything else means something was missed, and by how much.
 *
 * Three sections in the order they have to happen: the account balances, then
 * the unpaid invoices, then the unpaid bills. Receivables and payables come
 * across as documents rather than as a figure in the control account, because
 * a lump cannot be aged, chased, or reconciled to anybody — and the first
 * thing a migrated business wants is a chasing list matching the one they had
 * last week.
 *
 * @see ACCOUNTING_RULES.md §4.13
 */
final class OpeningBalanceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EnterOpeningBalances $enterOpeningBalances,
        private readonly EnterOpeningDocument $enterOpeningDocument,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::AccountingOpeningBalances->value);

        $organization = $this->tenant->organization();

        $equity = Account::query()
            ->where('system_role', SystemAccount::OpeningBalanceEquity->value)
            ->first();

        $invoices = SalesDocument::query()
            ->with('contact:id,display_name')
            ->where('is_opening_balance', true)
            ->orderBy('issue_date')
            ->get();

        $bills = PurchaseDocument::query()
            ->with('contact:id,display_name')
            ->where('is_opening_balance', true)
            ->orderBy('issue_date')
            ->get();

        return Inertia::render('Accounting/OpeningBalances', [
            /*
             * The whole point of the screen, first.
             *
             * Reported rather than hidden: a non-zero figure once everything
             * is across is the single most useful signal that an account, an
             * invoice or a bill was missed.
             */
            'equity' => [
                'balance' => $equity === null ? '0.0000' : $equity->balance(),
                'account' => $equity === null ? null : "{$equity->code} — {$equity->name}",
                'is_settled' => $equity !== null
                    && BigDecimal::of($equity->balance())->isZero(),
            ],
            'accounts' => $this->accountOptions(),
            'customers' => $this->contactOptions(customers: true),
            'vendors' => $this->contactOptions(customers: false),
            'invoices' => array_values($invoices
                ->map(static fn (SalesDocument $invoice): array => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'contact_name' => $invoice->contact?->display_name,
                    'issue_date' => $invoice->issue_date->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'total' => $invoice->total,
                    'balance_due' => $invoice->balanceDue(),
                    'is_overdue' => $invoice->isOverdue(),
                    'days_overdue' => $invoice->daysOverdue(),
                ])
                ->all()),
            'bills' => array_values($bills
                ->map(static fn (PurchaseDocument $bill): array => [
                    'id' => $bill->id,
                    'number' => $bill->number,
                    'contact_name' => $bill->contact?->display_name,
                    'vendor_reference' => $bill->vendor_reference,
                    'issue_date' => $bill->issue_date->toDateString(),
                    'due_date' => $bill->due_date?->toDateString(),
                    'total' => $bill->total,
                    'balance_due' => $bill->balanceDue(),
                    'is_overdue' => $bill->isOverdue(),
                    'days_overdue' => $bill->daysOverdue(),
                ])
                ->all()),
            'entered' => [
                'accounts' => $this->hasAccountBalances(),
                'invoices' => $invoices->count(),
                'bills' => $bills->count(),
            ],
            'openingDate' => $this->suggestedOpeningDate()->toDateString(),
            'baseCurrency' => $organization->base_currency,
            'can' => [
                'enter' => $request->user()?->can(Permission::AccountingOpeningBalances->value) ?? false,
            ],
        ]);
    }

    public function storeBalances(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingOpeningBalances->value);

        $request->validate([
            'as_of' => ['required', 'date'],
            'balances' => ['required', 'array', 'min:1', 'max:500'],
            'balances.*.account_id' => ['required', 'uuid'],
            // Strings, because a JSON number is a double.
            'balances.*.debit' => ['nullable', 'string', 'decimal:0,4'],
            'balances.*.credit' => ['nullable', 'string', 'decimal:0,4'],
        ]);

        /** @var list<array{account_id: string, debit?: string|null, credit?: string|null}> $balances */
        $balances = [];

        foreach ($request->collect('balances')->all() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $accountId = $row['account_id'] ?? null;

            if (! is_string($accountId)) {
                continue;
            }

            // Checked through the model, so the organisation scope applies
            // and an id from elsewhere simply is not found.
            if (Account::query()->postable()->whereKey($accountId)->first() === null) {
                return back()->withErrors([
                    'balances' => 'One of those accounts does not accept postings.',
                ])->withInput();
            }

            $balances[] = [
                'account_id' => $accountId,
                'debit' => is_string($row['debit'] ?? null) ? $row['debit'] : null,
                'credit' => is_string($row['credit'] ?? null) ? $row['credit'] : null,
            ];
        }

        try {
            $this->enterOpeningBalances->handle(
                balances: $balances,
                asOf: Carbon::parse($request->string('as_of')->toString()),
                actor: $request->user(),
                allowClosedPeriod: $request->boolean('post_to_closed_period')
                    && ($request->user()?->can(Permission::AccountingPostToClosedPeriod->value) ?? false),
            );
        } catch (PostingRefused|\InvalidArgumentException $exception) {
            return back()->withErrors(['balances' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', sprintf(
            'Opening balances posted. %s remains in opening balance equity — it should be '.
            'what the business was worth on that date once the invoices and bills are '.
            'across too.',
            $this->enterOpeningBalances->equityRemaining(),
        ));
    }

    public function storeInvoice(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingOpeningBalances->value);

        $validated = $this->validateDocument($request, 'customer');

        $customer = Contact::query()
            ->usable()
            ->customers()
            ->whereKey($validated['contact_id'])
            ->first();

        if ($customer === null) {
            return back()->withErrors(['contact_id' => 'Choose a customer who is active.']);
        }

        try {
            $invoice = $this->enterOpeningDocument->invoice(
                customer: $customer,
                amount: $validated['amount'],
                issuedOn: Carbon::parse($validated['issue_date']),
                dueOn: $validated['due_date'] === null
                    ? null
                    : Carbon::parse($validated['due_date']),
                reference: $validated['reference'],
                actor: $request->user(),
                allowClosedPeriod: $request->boolean('post_to_closed_period')
                    && ($request->user()?->can(Permission::AccountingPostToClosedPeriod->value) ?? false),
                openingDate: Carbon::parse($validated['opening_date']),
            );
        } catch (PostingRefused|\InvalidArgumentException|\RuntimeException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', sprintf(
            '%s brought across for %s. It ages from %s, like any other invoice.',
            $invoice->number,
            $customer->display_name,
            $invoice->due_date?->toDateString() ?? $invoice->issue_date->toDateString(),
        ));
    }

    public function storeBill(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingOpeningBalances->value);

        $validated = $this->validateDocument($request, 'vendor');

        $vendor = Contact::query()
            ->usable()
            ->vendors()
            ->whereKey($validated['contact_id'])
            ->first();

        if ($vendor === null) {
            return back()->withErrors(['contact_id' => 'Choose a vendor who is active.']);
        }

        try {
            $bill = $this->enterOpeningDocument->bill(
                vendor: $vendor,
                amount: $validated['amount'],
                issuedOn: Carbon::parse($validated['issue_date']),
                dueOn: $validated['due_date'] === null
                    ? null
                    : Carbon::parse($validated['due_date']),
                vendorReference: $validated['reference'],
                actor: $request->user(),
                allowClosedPeriod: $request->boolean('post_to_closed_period')
                    && ($request->user()?->can(Permission::AccountingPostToClosedPeriod->value) ?? false),
                openingDate: Carbon::parse($validated['opening_date']),
            );
        } catch (PostingRefused|\InvalidArgumentException|\RuntimeException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', sprintf(
            '%s brought across for %s.',
            $bill->number,
            $vendor->display_name,
        ));
    }

    /**
     * @return array{contact_id: string, amount: string, issue_date: string, due_date: string|null, reference: string|null, opening_date: string}
     */
    private function validateDocument(Request $request, string $kind): array
    {
        $request->validate([
            'contact_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'decimal:0,4'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'reference' => ['nullable', 'string', 'max:80'],
            /*
             * The date the ENTRY lands on, which is not the document's date.
             * An invoice issued in June comes across on the migration day —
             * the document keeps June so its ageing is right, and the entry
             * lands in a period this system actually keeps.
             */
            'opening_date' => ['required', 'date'],
        ], [
            'contact_id.required' => "Choose a {$kind}.",
            'amount.required' => 'Enter the amount still outstanding.',
        ]);

        $dueDate = $request->string('due_date')->toString();
        $reference = $request->string('reference')->toString();

        return [
            'contact_id' => $request->string('contact_id')->toString(),
            'amount' => $request->string('amount')->toString(),
            'issue_date' => $request->string('issue_date')->toString(),
            'due_date' => $dueDate === '' ? null : $dueDate,
            'reference' => $reference === '' ? null : $reference,
            'opening_date' => $request->string('opening_date')->toString(),
        ];
    }

    /**
     * Whether an opening-balance entry has already been posted.
     *
     * By source type, not by looking for the equity account having movement:
     * a manual journal touching equity is a different thing, and conflating
     * the two would tell somebody their migration was done when it was not.
     */
    private function hasAccountBalances(): bool
    {
        return JournalEntry::query()
            ->where('source_type', 'opening_balance')
            ->exists();
    }

    /**
     * Where an opening position belongs by default.
     *
     * The first day of the earliest fiscal year this system keeps: the year
     * then opens with these figures, which is what a migration means. Falls
     * back to today where no year exists yet, so the screen still renders and
     * says what it needs.
     */
    private function suggestedOpeningDate(): Carbon
    {
        $year = FiscalYear::query()->orderBy('starts_on')->first();

        return $year === null
            ? Carbon::now()
            : Carbon::parse($year->starts_on->toDateString());
    }

    /**
     * Accounts an opening balance can be entered against.
     *
     * The two control accounts are excluded rather than offered and refused:
     * receivables and payables come across as documents, and a picker that
     * lists them invites the mistake the action then has to explain.
     *
     * @return list<array{value: string, label: string, type: string, normal_balance: string}>
     */
    private function accountOptions(): array
    {
        $controls = [
            SystemAccount::AccountsReceivable->value,
            SystemAccount::AccountsPayable->value,
        ];

        return array_values(
            Account::query()
                ->postable()
                ->where(function ($query) use ($controls): void {
                    $query->whereNull('system_role')
                        ->orWhereNotIn('system_role', $controls);
                })
                ->orderBy('code')
                ->get()
                ->map(static fn (Account $account): array => [
                    'value' => $account->id,
                    'label' => "{$account->code} — {$account->name}",
                    'type' => $account->type->value,
                    // So the form can put the figure in the column the
                    // account normally sits in, which is right far more
                    // often than not.
                    'normal_balance' => $account->normal_balance->value,
                ])
                ->all(),
        );
    }

    /**
     * @return list<array{value: string, label: string, terms: int}>
     */
    private function contactOptions(bool $customers): array
    {
        $query = Contact::query()->usable();

        $customers ? $query->customers() : $query->vendors();

        return array_values(
            $query
                ->orderBy('display_name')
                ->get()
                ->map(static fn (Contact $contact): array => [
                    'value' => $contact->id,
                    'label' => $contact->display_name,
                    'terms' => $contact->payment_terms_days,
                ])
                ->all(),
        );
    }
}
