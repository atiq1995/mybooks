<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Tax\Models\Tax;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| The sales screens, over HTTP
|---------------------------------------------------------------------------
|
| The domain tests already prove the arithmetic and the posting. These cover
| what only the HTTP layer can: that the four document types share one set of
| routes correctly, that a write is refused without its own permission, and
| that a URL naming another organisation's record produces a 404 rather than
| that record.
|
| Authorisation is asserted per ROLE, since roles are what people are given.
*/

beforeEach(function (): void {
    /*
     * The clock is frozen, because half of these screens are about time.
     * Overdue, ageing buckets and the receivables as-at date are all derived
     * from "today" rather than stored, so a test that used the real date
     * would assert something different every day it ran — and would pass or
     * fail depending on when in the month somebody happened to run it.
     */
    Carbon::setTestNow('2026-11-20');

    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->owner = actingAsMember($this->organization, Role::Owner->value);

    $this->gst = Tax::query()->where('code', 'GST18')->sole();
    $this->revenue = Account::query()->where('code', '4010')->sole();
    $this->bank = ledgerAccount('1020');

    $this->customer = Contact::query()->create([
        'kind' => ContactKind::Customer,
        'display_name' => 'Karachi Textiles',
        'payment_terms_days' => 30,
    ]);

    $this->inYear = Carbon::parse('2026-09-15');

    /** The payload a draft invoice is saved from. */
    $this->payload = fn (string $price = '100000.00', ?string $discount = '5'): array => [
        'contact_id' => $this->customer->id,
        'issue_date' => $this->inYear->toDateString(),
        'lines' => [
            [
                'description' => 'Cotton yarn, 40s count',
                'quantity' => '1',
                'unit_price' => $price,
                'discount_type' => $discount === null ? null : 'percentage',
                'discount_value' => $discount,
                'tax_id' => $this->gst->id,
                'revenue_account_id' => $this->revenue->id,
            ],
        ],
    ];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('the document screens', function (): void {
    it('serves a list for each of the four types', function (): void {
        foreach (['estimates', 'sales-orders', 'invoices', 'credit-notes'] as $segment) {
            $this->get("/sales/{$segment}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Sales/Documents/Index')
                    ->where('type.segment', $segment),
                );
        }
    });

    it('404s on a document type that does not exist', function (): void {
        // Not a validation error: /sales/widgets is a page that is not there.
        $this->get('/sales/widgets')->assertNotFound();
    });

    it('says which types post and which are commitments', function (): void {
        $this->get('/sales/invoices')
            ->assertInertia(fn (Assert $page) => $page->where('type.posts', true));

        $this->get('/sales/estimates')
            ->assertInertia(fn (Assert $page) => $page->where('type.posts', false));
    });

    it('saves a draft and computes its totals', function (): void {
        $this->post('/sales/invoices', ($this->payload)())
            ->assertRedirect('/sales/invoices/INV-000001');

        $invoice = SalesDocument::query()->sole();

        // The §4.1 figures, through the real tax tables.
        expect($invoice->status)->toBe(SalesDocumentStatus::Draft)
            ->and($invoice->total)->toBeDecimal('112100.0000')
            ->and($invoice->tax_total)->toBeDecimal('17100.0000');
    });

    it('refuses a line pointing at an expense account', function (): void {
        $expense = Account::query()->postable()->where('type', 'expense')->firstOrFail();

        $payload = ($this->payload)();
        $payload['lines'][0]['revenue_account_id'] = $expense->id;

        // It would balance perfectly and make the profit and loss meaningless.
        $this->post('/sales/invoices', $payload)
            ->assertSessionHasErrors('lines.0.revenue_account_id');
    });

    it('refuses a document with no lines', function (): void {
        $payload = ($this->payload)();
        $payload['lines'] = [];

        $this->post('/sales/invoices', $payload)->assertSessionHasErrors('lines');
    });

    it('issues an invoice, then refuses to edit it', function (): void {
        $this->post('/sales/invoices', ($this->payload)());

        $this->post('/sales/invoices/INV-000001/issue')->assertSessionHas('success');

        $invoice = SalesDocument::query()->sole();

        expect($invoice->status)->toBe(SalesDocumentStatus::Sent)
            ->and($invoice->journal_entry_id)->not->toBeNull();

        // The editor refuses to open, before any write is attempted.
        $this->get('/sales/invoices/INV-000001/edit')->assertForbidden();

        $this->patch('/sales/invoices/INV-000001', ($this->payload)())
            ->assertSessionHas('error');
    });

    it('shows an issued invoice with its journal entry and tax breakdown', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        $this->get('/sales/invoices/INV-000001')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->component('Sales/Documents/Show')
                    ->where('document.number', 'INV-000001')
                    ->where('document.balance_due', '112100.0000')
                    ->where('contact.display_name', 'Karachi Textiles')
                    // The entry is named, so the ledger is reachable from here.
                    ->where('document.journal_entry_no', 'JE-000001');

                $summary = $page->toArray()['props']['document']['tax_summary'];

                expect($summary)->toHaveCount(1);
                expect($summary[0]['amount'])->toBe('17100.0000');
            });
    });

    it('voids an issued invoice and refuses to delete it', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        // Deleting an issued document would leave a numbering gap.
        $this->delete('/sales/invoices/INV-000001')->assertSessionHas('error');

        $this->post('/sales/invoices/INV-000001/void', ['reason' => 'Wrong customer'])
            ->assertSessionHas('success');

        $invoice = SalesDocument::query()->sole();

        expect($invoice->status)->toBe(SalesDocumentStatus::Void)
            ->and($invoice->void_journal_entry_id)->not->toBeNull()
            // Both entries survive.
            ->and($invoice->journal_entry_id)->not->toBeNull();
    });

    it('deletes a draft outright', function (): void {
        $this->post('/sales/invoices', ($this->payload)());

        $this->delete('/sales/invoices/INV-000001')->assertRedirect('/sales/invoices');

        expect(SalesDocument::query()->count())->toBe(0);
    });

    it('converts an estimate to an invoice as a draft', function (): void {
        $this->post('/sales/estimates', ($this->payload)());
        $this->post('/sales/estimates/EST-000001/issue');

        $this->post('/sales/estimates/EST-000001/convert', ['to' => 'invoice'])
            ->assertRedirect('/sales/invoices/INV-000001');

        $invoice = SalesDocument::query()->ofType(SalesDocumentType::Invoice)->sole();

        // A draft: converting does not post revenue as a side effect.
        expect($invoice->status)->toBe(SalesDocumentStatus::Draft)
            ->and($invoice->total)->toBeDecimal('112100.0000');
    });

    it('filters by overdue without a stored status', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        // Issued 2026-09-15 on 30-day terms, so due 2026-10-15 — a month
        // before the frozen "today". Overdue is derived, never stored.
        $this->get('/sales/invoices?status=overdue')
            ->assertInertia(fn (Assert $page) => $page->where('documents.total', 1));

        $this->get('/sales/invoices?status=paid')
            ->assertInertia(fn (Assert $page) => $page->where('documents.total', 0));
    });

    it('summarises what is outstanding and overdue', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        $this->get('/sales/invoices')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.outstanding', '112100.0000')
                ->where('summary.overdue', '112100.0000')
                ->where('summary.draft', '0.0000'),
            );
    });

    it('404s on a document number from another organisation', function (): void {
        $other = Organization::factory()->create();

        app(TenantContext::class)->runAs($other, function () use ($other): void {
            withLedger($other);
        });

        // INV-000001 exists there but not here.
        $this->get('/sales/invoices/INV-000001')->assertNotFound();
    });
});

describe('customers', function (): void {
    it('lists them with their outstanding balance', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        $this->get('/sales/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Contacts/Index')
                ->where('contacts.data.0.display_name', 'Karachi Textiles')
                ->where('contacts.data.0.outstanding', '112100.0000'),
            );
    });

    it('shows a statement that reconciles, with ageing', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        $this->get("/sales/customers/{$this->customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Contacts/Show')
                ->where('statement.closing', '112100.0000')
                ->where('statement.rows.0.number', 'INV-000001')
                // Past due, so it is not in the current bucket.
                ->where('aging.current', '0.0000')
                ->where('contact.outstanding', '112100.0000'),
            );
    });

    it('creates and archives a contact', function (): void {
        $this->post('/sales/customers', [
            'kind' => 'both',
            'display_name' => 'Lahore Printers',
            'payment_terms_days' => 15,
        ])->assertSessionHas('success');

        $contact = Contact::query()->where('display_name', 'Lahore Printers')->sole();

        expect($contact->kind)->toBe(ContactKind::Both);

        $this->delete("/sales/customers/{$contact->id}")->assertSessionHas('success');

        expect($contact->fresh()?->archived_at)->not->toBeNull();
    });

    it('refuses to archive a customer who still owes money', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        // The receivable would stay on the balance sheet with nobody attached.
        $this->delete("/sales/customers/{$this->customer->id}")
            ->assertSessionHas('error');

        expect($this->customer->fresh()?->archived_at)->toBeNull();
    });

    it('404s on a contact from another organisation', function (): void {
        $other = Organization::factory()->create();

        $theirs = app(TenantContext::class)->runAs($other, fn (): Contact => Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Theirs',
        ]));

        actingAsMember($this->organization, Role::Owner->value);

        $this->get("/sales/customers/{$theirs->id}")->assertNotFound();
        $this->patch("/sales/customers/{$theirs->id}", [
            'kind' => 'customer',
            'display_name' => 'Reaching across',
            'payment_terms_days' => 30,
        ])->assertNotFound();
    });
});

describe('items', function (): void {
    it('creates a service and refuses to track it', function (): void {
        $this->post('/sales/items', [
            'kind' => 'service',
            'name' => 'Consultancy',
            'sale_price' => '15000.00',
            'is_sold' => true,
            'is_tracked' => true,
        ])->assertSessionHasErrors('is_tracked');

        $this->post('/sales/items', [
            'kind' => 'service',
            'name' => 'Consultancy',
            'sale_price' => '15000.00',
            'is_sold' => true,
        ])->assertSessionHas('success');

        $this->get('/sales/items')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Items/Index')
                ->where('items.data.0.name', 'Consultancy'),
            );
    });

    it('refuses an item that is neither sold nor purchased', function (): void {
        $this->post('/sales/items', [
            'kind' => 'service',
            'name' => 'Unusable',
            'is_sold' => false,
            'is_purchased' => false,
        ])->assertSessionHasErrors('is_sold');
    });

    it('refuses a duplicate SKU within the organisation', function (): void {
        $payload = [
            'kind' => 'goods',
            'sku' => 'YARN-40',
            'name' => 'Cotton yarn',
            'is_sold' => true,
        ];

        $this->post('/sales/items', $payload)->assertSessionHas('success');
        $this->post('/sales/items', $payload)->assertSessionHasErrors('sku');
    });
});

describe('payments', function (): void {
    it('records a receipt against an invoice', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        $invoice = SalesDocument::query()->sole();

        $this->post('/sales/payments', [
            'contact_id' => $this->customer->id,
            'bank_account_id' => $this->bank->id,
            'payment_date' => '2026-10-01',
            'amount' => '112100.00',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '112100.00'],
            ],
        ])->assertRedirect('/sales/payments');

        expect(Payment::query()->sole()->number)->toBe('RCPT-000001')
            ->and($invoice->fresh()?->status)->toBe(SalesDocumentStatus::Paid);
    });

    it('refuses to allocate more than was received', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        $invoice = SalesDocument::query()->sole();

        $this->post('/sales/payments', [
            'contact_id' => $this->customer->id,
            'bank_account_id' => $this->bank->id,
            'payment_date' => '2026-10-01',
            'amount' => '1000.00',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '5000.00'],
            ],
        ])->assertSessionHasErrors('allocations');

        expect(Payment::query()->count())->toBe(0);
    });

    it('refuses money received into a revenue account', function (): void {
        $this->post('/sales/payments', [
            'contact_id' => $this->customer->id,
            'bank_account_id' => $this->revenue->id,
            'payment_date' => '2026-10-01',
            'amount' => '1000.00',
        ])->assertSessionHasErrors('bank_account_id');
    });

    it('offers a customer their outstanding invoices', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        $this->getJson("/sales/payments/outstanding/{$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('outstanding.0.number', 'INV-000001')
            ->assertJsonPath('outstanding.0.balance_due', '112100.0000');
    });
});

describe('the receivables report', function (): void {
    it('ages the balance and says whether it reconciles', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        $this->get('/sales/receivables')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Reports/Receivables')
                ->where('totals.total', '112100.0000')
                // Phase 3's exit criterion, visible on the screen rather than
                // only in a test.
                ->where('reconciliation.reconciles', true)
                ->where('reconciliation.difference', '0.0000'),
            );
    });

    it('notices when the report and the ledger disagree', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        /*
         * A manual journal straight to the receivables control account. That
         * is legitimate — an opening balance arrives that way — but it means
         * the ageing report no longer explains the whole balance, and the
         * report has to say so rather than let the reader assume.
         */
        $ar = ledgerAccount(SystemAccount::AccountsReceivable);

        app(PostJournalEntry::class)->handle(
            JournalDraft::inBaseCurrency(
                date: $this->inYear,
                currency: 'PKR',
                lines: [
                    JournalLineDraft::debit($ar->id, '5000.0000'),
                    JournalLineDraft::credit($this->revenue->id, '5000.0000'),
                ],
                source: ['manual', null, 'issue'],
            ),
            $this->owner,
        );

        $this->get('/sales/receivables')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reconciliation.reconciles', false)
                ->where('reconciliation.difference', '5000.0000'),
            );
    });
});

describe('tax rates', function (): void {
    it('lists the jurisdiction defaults with their effective rate', function (): void {
        $this->get('/settings/taxes')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $taxes = collect($page->toArray()['props']['taxes']);

                $gst = $taxes->firstWhere('code', 'GST18');

                expect($gst['effective_rate'])->toBe('18');

                // The compound one reads as what an invoice will charge:
                // 18% then 3% on the total, not 21%.
                expect($taxes->firstWhere('code', 'GST18F')['effective_rate'])->toBe('21.54');

                // Exempt has no components at all — outside the tax entirely.
                expect($taxes->firstWhere('code', 'EXEMPT')['components'])->toBe([]);
            });
    });

    it('creates a tax from a percentage', function (): void {
        $this->post('/settings/taxes', [
            'name' => 'Sindh services 15%',
            'code' => 'SST15',
            'applies_to' => 'sales',
            'components' => [
                [
                    'name' => 'Sindh sales tax',
                    'percentage' => '15',
                    'effective_from' => '2026-07-01',
                ],
            ],
        ])->assertSessionHas('success');

        $tax = Tax::query()->where('code', 'SST15')->sole();

        // A percentage in, a fraction out.
        expect($tax->components()->sole()->rate)->toBe('0.150000');
    });

    it('refuses a duplicate code', function (): void {
        $this->post('/settings/taxes', [
            'name' => 'Another GST',
            'code' => 'GST18',
            'applies_to' => 'sales',
        ])->assertSessionHasErrors('code');
    });

    it('archives a tax without touching what it priced', function (): void {
        $this->post('/sales/invoices', ($this->payload)());
        $this->post('/sales/invoices/INV-000001/issue');

        $this->delete("/settings/taxes/{$this->gst->id}")->assertSessionHas('success');

        expect($this->gst->fresh()?->archived_at)->not->toBeNull();

        // The document keeps its breakdown, so the return stays reproducible.
        expect(SalesDocument::query()->sole()->lineTaxes()->sole()->tax_amount)
            ->toBeDecimal('17100.0000');
    });
});

describe('authorisation, by role', function (): void {
    it('lets a viewer read the sales screens and change nothing', function (): void {
        $this->post('/sales/invoices', ($this->payload)());

        actingAsMember($this->organization, Role::Viewer->value);

        $this->get('/sales/invoices')->assertOk();
        $this->get('/sales/customers')->assertOk();
        $this->get('/sales/payments')->assertOk();

        $this->get('/sales/invoices/new')->assertForbidden();
        $this->post('/sales/invoices', ($this->payload)())->assertForbidden();
        $this->post('/sales/invoices/INV-000001/issue')->assertForbidden();
        $this->post('/sales/invoices/INV-000001/void')->assertForbidden();
        $this->get('/sales/payments/new')->assertForbidden();

        $this->post('/sales/customers', [
            'kind' => 'customer',
            'display_name' => 'Should not exist',
            'payment_terms_days' => 30,
        ])->assertForbidden();

        expect(SalesDocument::query()->count())->toBe(1)
            ->and(Contact::query()->where('display_name', 'Should not exist')->exists())->toBeFalse();
    });

    it('lets a bookkeeper prepare an invoice but not issue it', function (): void {
        actingAsMember($this->organization, Role::Bookkeeper->value);

        // The separation of duties the roles exist for.
        $this->post('/sales/invoices', ($this->payload)())->assertSessionHasNoErrors();

        $this->post('/sales/invoices/INV-000001/issue')->assertForbidden();

        expect(SalesDocument::query()->sole()->status)->toBe(SalesDocumentStatus::Draft);
    });

    it('needs the accounting settings permission to change a tax', function (): void {
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->get('/settings/taxes')->assertForbidden();

        $this->post('/settings/taxes', [
            'name' => 'Sneaky',
            'code' => 'SNK',
            'applies_to' => 'sales',
        ])->assertForbidden();
    });
});
