<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Models\SalesDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreContactRequest;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers and vendors.
 *
 * The list carries each contact's outstanding balance, because "who owes us
 * money" is the question people open this screen with — and a list of names
 * with no figures makes them open forty detail pages to find out.
 *
 * Balances are summed in ONE query across the whole list rather than per row.
 * A hundred contacts is a hundred N+1 queries otherwise, which is the
 * difference between a screen and a timeout.
 */
final class ContactController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::ContactsView->value);

        $organization = $this->tenant->organization();

        $kind = $request->string('kind')->toString();
        $search = $request->string('search')->toString();

        $contacts = Contact::query()
            ->when($kind === 'customer', fn ($query) => $query->customers())
            ->when($kind === 'vendor', fn ($query) => $query->vendors())
            ->when(
                $search !== '',
                fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('display_name', 'ilike', "%{$search}%")
                        ->orWhere('legal_name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('tax_registration_number', 'ilike', "%{$search}%");
                }),
            )
            ->when(! $request->boolean('archived'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('display_name')
            ->paginate(50)
            ->withQueryString();

        $balances = $this->balancesFor(
            array_values(array_map(
                static fn (Contact $contact): string => $contact->id,
                $contacts->items(),
            )),
        );

        return Inertia::render('Sales/Contacts/Index', [
            'contacts' => [
                'data' => array_values(array_map(
                    fn (Contact $contact): array => [
                        'id' => $contact->id,
                        'display_name' => $contact->display_name,
                        'legal_name' => $contact->legal_name,
                        'kind' => $contact->kind->value,
                        'kind_label' => $contact->kind->label(),
                        'email' => $contact->email,
                        'phone' => $contact->phone,
                        'currency' => $contact->currency ?? $organization->base_currency,
                        'payment_terms_days' => $contact->payment_terms_days,
                        'is_tax_filer' => $contact->is_tax_filer,
                        'is_archived' => $contact->archived_at !== null,
                        'outstanding' => $balances[$contact->id] ?? '0.0000',
                        'credit_limit' => $contact->credit_limit,
                        // Advisory only: refusing to invoice a customer who
                        // has ordered is a commercial decision, not a
                        // database constraint.
                        'over_limit' => $contact->credit_limit !== null
                            && BigDecimal::of($balances[$contact->id] ?? '0')
                                ->isGreaterThan(BigDecimal::of($contact->credit_limit)),
                    ],
                    $contacts->items(),
                )),
                'links' => $contacts->linkCollection()->toArray(),
                'total' => $contacts->total(),
                'from' => $contacts->firstItem(),
                'to' => $contacts->lastItem(),
            ],
            'filters' => [
                'kind' => $kind,
                'search' => $search,
                'archived' => $request->boolean('archived'),
            ],
            'baseCurrency' => $organization->base_currency,
            'options' => [
                'kinds' => array_map(
                    static fn (ContactKind $kind): array => [
                        'value' => $kind->value,
                        'label' => $kind->label(),
                    ],
                    ContactKind::cases(),
                ),
            ],
            'can' => [
                'create' => $request->user()?->can(Permission::ContactsCreate->value) ?? false,
                'update' => $request->user()?->can(Permission::ContactsUpdate->value) ?? false,
            ],
        ]);
    }

    /**
     * A contact, with the statement that reconciles to the AR control account.
     */
    public function show(Request $request, Contact $contact): Response
    {
        $this->authorize(Permission::ContactsView->value);

        $this->guardBelongsToActiveOrganization($contact);

        $organization = $this->tenant->organization();

        $documents = SalesDocument::query()
            ->where('contact_id', $contact->id)
            ->whereIn('type', [
                SalesDocumentType::Invoice->value,
                SalesDocumentType::CreditNote->value,
            ])
            ->where('status', '!=', 'draft')
            ->orderBy('issue_date')
            ->orderBy('number')
            ->get();

        return Inertia::render('Sales/Contacts/Show', [
            'contact' => [
                'id' => $contact->id,
                'display_name' => $contact->display_name,
                'legal_name' => $contact->legal_name,
                'kind' => $contact->kind->value,
                'kind_label' => $contact->kind->label(),
                'email' => $contact->email,
                'phone' => $contact->phone,
                'website' => $contact->website,
                'tax_registration_number' => $contact->tax_registration_number,
                'sales_tax_registration_number' => $contact->sales_tax_registration_number,
                'is_tax_filer' => $contact->is_tax_filer,
                'currency' => $contact->currency ?? $organization->base_currency,
                'payment_terms_days' => $contact->payment_terms_days,
                'credit_limit' => $contact->credit_limit,
                'billing_address' => $contact->billing_address,
                'shipping_address' => $contact->shipping_address,
                'notes' => $contact->notes,
                'is_archived' => $contact->archived_at !== null,
                'outstanding' => $contact->outstandingBalance(),
            ],
            'statement' => $this->statement($documents),
            'aging' => $this->aging($documents),
            'baseCurrency' => $organization->base_currency,
            'can' => [
                'update' => $request->user()?->can(Permission::ContactsUpdate->value) ?? false,
                'invoice' => $request->user()?->can(Permission::SalesCreate->value) ?? false,
            ],
        ]);
    }

    public function store(StoreContactRequest $request): RedirectResponse
    {
        $this->authorize(Permission::ContactsCreate->value);

        $validated = $request->validated();

        $contact = DB::transaction(function () use ($validated, $request): Contact {
            $contact = Contact::query()->create($validated);

            $this->audit->record(
                action: 'contacts.created',
                subject: $contact,
                description: "Created {$contact->kind->label()} {$contact->display_name}",
                new: [
                    'display_name' => $contact->display_name,
                    'kind' => $contact->kind->value,
                    'email' => $contact->email,
                ],
                actor: $request->user(),
            );

            return $contact;
        });

        return back()->with('success', "{$contact->display_name} added.");
    }

    public function update(StoreContactRequest $request, Contact $contact): RedirectResponse
    {
        $this->authorize(Permission::ContactsUpdate->value);

        $this->guardBelongsToActiveOrganization($contact);

        DB::transaction(function () use ($contact, $request): void {
            $contact->fill($request->validated())->save();

            if ($contact->wasChanged()) {
                $this->audit->recordChange(
                    action: 'contacts.updated',
                    subject: $contact,
                    description: "Updated {$contact->display_name}",
                    actor: $request->user(),
                );
            }
        });

        return back()->with('success', "{$contact->display_name} updated.");
    }

    /**
     * Archive rather than delete.
     *
     * A contact with documents is referenced by history that has to stay
     * readable. Archiving removes them from pickers and leaves every past
     * invoice intact.
     */
    public function archive(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize(Permission::ContactsUpdate->value);

        $this->guardBelongsToActiveOrganization($contact);

        $outstanding = BigDecimal::of($contact->outstandingBalance());

        if ($outstanding->isPositive()) {
            return back()->with('error', sprintf(
                '%s still owes %s. Settle or write off the balance before archiving them — '.
                'otherwise the receivable stays on the balance sheet with nobody attached to it.',
                $contact->display_name,
                $outstanding->toScale(2),
            ));
        }

        DB::transaction(function () use ($contact, $request): void {
            $contact->forceFill(['archived_at' => now(), 'is_active' => false])->save();

            $this->audit->record(
                action: 'contacts.archived',
                subject: $contact,
                description: "Archived {$contact->display_name}",
                actor: $request->user(),
            );
        });

        return back()->with('success', "{$contact->display_name} archived.");
    }

    public function restore(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize(Permission::ContactsUpdate->value);

        $this->guardBelongsToActiveOrganization($contact);

        $contact->forceFill(['archived_at' => null, 'is_active' => true])->save();

        $this->audit->record(
            action: 'contacts.restored',
            subject: $contact,
            description: "Restored {$contact->display_name}",
            actor: $request->user(),
        );

        return back()->with('success', "{$contact->display_name} restored.");
    }

    /**
     * Outstanding balances for a page of contacts, in one query.
     *
     * @param  list<string>  $contactIds
     * @return array<string, string>
     */
    private function balancesFor(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $rows = DB::table('sales_documents')
            ->whereIn('contact_id', $contactIds)
            ->where('type', SalesDocumentType::Invoice->value)
            ->whereIn('status', ['sent', 'open', 'partially_paid', 'overdue'])
            ->groupBy('contact_id')
            ->selectRaw('contact_id')
            ->selectRaw('COALESCE(SUM(total - amount_paid - amount_credited), 0) AS owed')
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            /** @var object{contact_id: string, owed: string} $row */
            $balances[$row->contact_id] = (string) BigDecimal::of((string) $row->owed)->toScale(4);
        }

        return $balances;
    }

    /**
     * A running statement.
     *
     * Every issued invoice and credit note in date order with a running
     * balance — which is what a customer disputing a figure actually asks
     * for, and what has to reconcile to the AR control account.
     *
     * @param  Collection<int, SalesDocument>  $documents
     * @return array{rows: list<array<string, mixed>>, closing: string}
     */
    private function statement(Collection $documents): array
    {
        $running = BigDecimal::zero();
        $rows = [];

        foreach ($documents as $document) {
            // An invoice increases what is owed; a credit note reduces it.
            $movement = $document->type === SalesDocumentType::CreditNote
                ? BigDecimal::of($document->total)->negated()
                : BigDecimal::of($document->total);

            // A voided document moved nothing, but it stays on the statement
            // so the numbering has no unexplained gap.
            if ($document->status->isVoid()) {
                $movement = BigDecimal::zero();
            }

            $running = $running
                ->plus($movement)
                ->minus(BigDecimal::of($document->amount_paid));

            $rows[] = [
                'id' => $document->id,
                'number' => $document->number,
                'type' => $document->type->value,
                'type_label' => $document->type->label(),
                'issue_date' => $document->issue_date->toDateString(),
                'due_date' => $document->due_date?->toDateString(),
                'status' => $document->status->value,
                'status_label' => $document->status->label(),
                'status_tone' => $document->status->tone(),
                'total' => $document->total,
                'paid' => $document->amount_paid,
                'credited' => $document->amount_credited,
                'balance_due' => $document->balanceDue(),
                'running_balance' => (string) $running->toScale(4),
                'is_overdue' => $document->isOverdue(),
                'days_overdue' => $document->daysOverdue(),
            ];
        }

        return ['rows' => $rows, 'closing' => (string) $running->toScale(4)];
    }

    /**
     * How old the outstanding balance is.
     *
     * The buckets everybody uses, because an aging report that invents its
     * own is a report nobody can compare to their last one.
     *
     * @param  Collection<int, SalesDocument>  $documents
     * @return array<string, string>
     */
    private function aging(Collection $documents): array
    {
        $buckets = ['current' => BigDecimal::zero(), '1_30' => BigDecimal::zero(),
            '31_60' => BigDecimal::zero(), '61_90' => BigDecimal::zero(),
            'over_90' => BigDecimal::zero()];

        $today = Carbon::now()->startOfDay();

        foreach ($documents as $document) {
            if ($document->type !== SalesDocumentType::Invoice || ! $document->status->isOutstanding()) {
                continue;
            }

            $due = BigDecimal::of($document->balanceDue());

            if ($due->isZero()) {
                continue;
            }

            $days = $document->due_date === null
                ? 0
                : (int) $document->due_date->startOfDay()->diffInDays($today, absolute: false);

            $key = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => 'over_90',
            };

            $buckets[$key] = $buckets[$key]->plus($due);
        }

        return array_map(
            static fn (BigDecimal $amount): string => (string) $amount->toScale(4),
            $buckets,
        );
    }

    private function guardBelongsToActiveOrganization(Contact $contact): void
    {
        abort_unless($contact->organization_id === $this->tenant->organization()->id, 404);
    }
}
