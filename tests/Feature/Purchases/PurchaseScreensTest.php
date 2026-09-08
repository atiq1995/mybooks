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
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tax\Models\Tax;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| The purchase screens, over HTTP
|---------------------------------------------------------------------------
|
| The domain tests already prove the arithmetic and the posting. These cover
| what only the HTTP layer can: that the three document types share one set of
| routes correctly, that a write is refused without its own permission, and
| that a URL naming another organisation's record produces a 404 rather than
| that record.
|
| Authorisation is asserted per ROLE, since roles are what people are given —
| and on this side the roles carry the whole point of the module: a Bookkeeper
| may prepare a bill and not approve it, and an Approver may approve one and
| not create it. Neither can do both, by construction.
*/

beforeEach(function (): void {
    /*
     * The clock is frozen, because half of these screens are about time.
     * Overdue, the ageing buckets, the due-within-seven-days figure and the
     * payables as-at date are all derived rather than stored.
     */
    Carbon::setTestNow('2026-11-20');

    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->owner = actingAsMember($this->organization, Role::Owner->value);

    $this->gst = Tax::query()->where('code', 'GST18')->sole();
    $this->fees = Account::query()->where('code', '6400')->sole();
    $this->bank = ledgerAccount('1020');

    $this->vendor = Contact::query()->create([
        'kind' => ContactKind::Vendor,
        'display_name' => 'Sindh Yarn Traders',
        'payment_terms_days' => 30,
    ]);

    $this->inYear = Carbon::parse('2026-09-15');

    /** The payload a draft bill is saved from. */
    $this->payload = fn (
        string $price = '50000.00',
        bool $claimable = true,
        ?string $vendorRef = 'ACME-1001',
    ): array => [
        'contact_id' => $this->vendor->id,
        'issue_date' => $this->inYear->toDateString(),
        'vendor_reference' => $vendorRef,
        'lines' => [
            [
                'description' => 'Spinning subcontract, September',
                'quantity' => '1',
                'unit_price' => $price,
                'tax_id' => $this->gst->id,
                'debit_account_id' => $this->fees->id,
                'tax_is_claimable' => $claimable,
            ],
        ],
    ];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('the document screens', function (): void {
    it('serves a list for each of the three types', function (): void {
        foreach (['orders', 'bills', 'vendor-credits'] as $segment) {
            $this->get("/purchases/{$segment}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Purchases/Documents/Index')
                    ->where('type.segment', $segment),
                );
        }
    });

    it('404s on a document type that does not exist', function (): void {
        // Not a validation error: /purchases/widgets is a page that is not
        // there. Purchases has no placeholder entry any more, so nothing
        // claims the module is still arriving.
        $this->get('/purchases/widgets')->assertNotFound();
    });

    it('says which types post and which are commitments', function (): void {
        $this->get('/purchases/bills')
            ->assertInertia(fn (Assert $page) => $page
                ->where('type.posts', true)
                // The word on the button, which is the control on this side.
                ->where('type.issue_verb', 'Approve'),
            );

        $this->get('/purchases/orders')
            ->assertInertia(fn (Assert $page) => $page
                ->where('type.posts', false)
                ->where('type.issue_verb', 'Send'),
            );
    });

    it('saves a draft and computes its totals', function (): void {
        $this->post('/purchases/bills', ($this->payload)())
            ->assertRedirect('/purchases/bills/BILL-000001');

        $bill = PurchaseDocument::query()->sole();

        // §4.6's figures, through the real tax tables.
        expect($bill->status)->toBe(PurchaseDocumentStatus::Draft)
            ->and($bill->total)->toBeDecimal('59000.0000')
            ->and($bill->tax_total)->toBeDecimal('9000.0000')
            ->and($bill->tax_claimable_total)->toBeDecimal('9000.0000');
    });

    it('refuses a line charged to an income account', function (): void {
        $revenue = Account::query()->where('code', '4010')->sole();

        $payload = ($this->payload)();
        $payload['lines'][0]['debit_account_id'] = $revenue->id;

        // It would balance perfectly and put the cost where no report shows it.
        $this->post('/purchases/bills', $payload)
            ->assertSessionHasErrors('lines.0.debit_account_id');
    });

    it('accepts a line charged to an asset account', function (): void {
        /*
         * Wider than the sales side's "income only", and deliberately: stock
         * and equipment are assets, and a purchase legitimately debits them.
         */
        $payload = ($this->payload)();
        $payload['lines'][0]['debit_account_id'] = ledgerAccount(SystemAccount::Inventory)->id;

        $this->post('/purchases/bills', $payload)->assertSessionHasNoErrors();

        expect(PurchaseDocument::query()->sole()->lines()->sole()->debit_account_id)
            ->toBe(ledgerAccount(SystemAccount::Inventory)->id);
    });

    it('refuses a tax that does not apply to purchases', function (): void {
        // GST18F is sales-only in the jurisdiction defaults.
        $salesOnly = Tax::query()->where('code', 'GST18F')->sole();

        $payload = ($this->payload)();
        $payload['lines'][0]['tax_id'] = $salesOnly->id;

        $this->post('/purchases/bills', $payload)
            ->assertSessionHasErrors('lines.0.tax_id');
    });

    it('refuses a second bill with the same vendor reference, naming the first', function (): void {
        $this->post('/purchases/bills', ($this->payload)());

        $response = $this->post('/purchases/bills', ($this->payload)());

        $response->assertSessionHasErrors('vendor_reference');

        // The message has to name the collision, or the person entering it
        // cannot tell whether it really is the same bill.
        expect(session('errors')?->first('vendor_reference'))->toContain('BILL-000001');

        expect(PurchaseDocument::query()->count())->toBe(1);
    });

    it('allows the same reference for a different vendor', function (): void {
        $this->post('/purchases/bills', ($this->payload)());

        $other = Contact::query()->create([
            'kind' => ContactKind::Vendor,
            'display_name' => 'Another Supplier',
        ]);

        $payload = ($this->payload)();
        $payload['contact_id'] = $other->id;

        // Two vendors numbering their own invoices 1001 is not a duplicate.
        $this->post('/purchases/bills', $payload)->assertSessionHasNoErrors();

        expect(PurchaseDocument::query()->count())->toBe(2);
    });

    it('approves a bill, then refuses to edit it', function (): void {
        $this->post('/purchases/bills', ($this->payload)());

        $this->post('/purchases/bills/BILL-000001/approve')->assertSessionHas('success');

        $bill = PurchaseDocument::query()->sole();

        expect($bill->status)->toBe(PurchaseDocumentStatus::Open)
            ->and($bill->journal_entry_id)->not->toBeNull()
            ->and($bill->approved_at)->not->toBeNull();

        // The editor refuses to open, before any write is attempted.
        $this->get('/purchases/bills/BILL-000001/edit')->assertForbidden();

        $this->patch('/purchases/bills/BILL-000001', ($this->payload)())
            ->assertSessionHas('error');
    });

    it('shows an approved bill with its entry and its recoverable tax', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        $this->get('/purchases/bills/BILL-000001')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->component('Purchases/Documents/Show')
                    ->where('document.number', 'BILL-000001')
                    ->where('document.vendor_reference', 'ACME-1001')
                    ->where('document.balance_due', '59000.0000')
                    ->where('document.tax_claimable_total', '9000.0000')
                    ->where('document.tax_capitalised', '0.0000')
                    ->where('contact.display_name', 'Sindh Yarn Traders')
                    // The entry is named, so the ledger is reachable.
                    ->where('document.journal_entry_no', 'JE-000001');

                $summary = $page->toArray()['props']['document']['tax_summary'];

                expect($summary)->toHaveCount(1);
                expect($summary[0]['amount'])->toBe('9000.0000');
                expect($summary[0]['claimable'])->toBe('9000.0000');
            });
    });

    it('states the unclaimable tax separately when a line is blocked', function (): void {
        $this->post('/purchases/bills', ($this->payload)(claimable: false));

        $this->get('/purchases/bills/BILL-000001')
            ->assertInertia(function (Assert $page): void {
                $page->where('document.tax_total', '9000.0000')
                    ->where('document.tax_claimable_total', '0.0000')
                    // The figure a reader comparing two vendors needs.
                    ->where('document.tax_capitalised', '9000.0000');

                $line = $page->toArray()['props']['document']['lines'][0];

                expect($line['tax_is_claimable'])->toBeFalse();
                // Net plus the tax that could not be reclaimed.
                expect($line['capitalised_cost'])->toBe('59000.0000');
            });
    });

    it('voids an approved bill and refuses to delete it', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        // Deleting an approved bill would leave a numbering gap.
        $this->delete('/purchases/bills/BILL-000001')->assertSessionHas('error');

        $this->post('/purchases/bills/BILL-000001/void', ['reason' => 'Duplicate'])
            ->assertSessionHas('success');

        $bill = PurchaseDocument::query()->sole();

        expect($bill->status)->toBe(PurchaseDocumentStatus::Void)
            ->and($bill->void_journal_entry_id)->not->toBeNull()
            // Both entries survive.
            ->and($bill->journal_entry_id)->not->toBeNull();
    });

    it('deletes a draft outright', function (): void {
        $this->post('/purchases/bills', ($this->payload)());

        $this->delete('/purchases/bills/BILL-000001')->assertRedirect('/purchases/bills');

        expect(PurchaseDocument::query()->count())->toBe(0);
    });

    it('converts a purchase order to a bill as a draft', function (): void {
        $this->post('/purchases/orders', ($this->payload)(vendorRef: null));
        $this->post('/purchases/orders/PO-000001/approve');

        $this->post('/purchases/orders/PO-000001/convert', ['to' => 'bill'])
            ->assertRedirect('/purchases/bills/BILL-000001');

        $bill = PurchaseDocument::query()->ofType(PurchaseDocumentType::Bill)->sole();

        // A draft: comparing the bill against the order is the point of the
        // step, and approving as a side effect would skip it.
        expect($bill->status)->toBe(PurchaseDocumentStatus::Draft)
            ->and($bill->total)->toBeDecimal('59000.0000')
            // Theirs does not travel, so the duplicate guard still works.
            ->and($bill->vendor_reference)->toBeNull();
    });

    it('filters by overdue without a stored status', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        // Dated 2026-09-15 on 30-day terms, so due 2026-10-15 — a month
        // before the frozen "today".
        $this->get('/purchases/bills?status=overdue')
            ->assertInertia(fn (Assert $page) => $page->where('documents.total', 1));

        $this->get('/purchases/bills?status=paid')
            ->assertInertia(fn (Assert $page) => $page->where('documents.total', 0));
    });

    it('summarises what is owed, overdue, and waiting for approval', function (): void {
        // One approved, one still a draft.
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');
        $this->post('/purchases/bills', ($this->payload)(price: '1000.00', vendorRef: 'ACME-2'));

        $this->get('/purchases/bills')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.outstanding', '59000.0000')
                ->where('summary.overdue', '59000.0000')
                // The figure with no sales equivalent: owed, but not yet in
                // the books.
                ->where('summary.draft', '1180.0000'),
            );
    });

    it('finds a bill by the vendor’s own number', function (): void {
        $this->post('/purchases/bills', ($this->payload)());

        // The number people actually have in front of them.
        $this->get('/purchases/bills?search=ACME-1001')
            ->assertInertia(fn (Assert $page) => $page->where('documents.total', 1));

        $this->get('/purchases/bills?search=ACME-9999')
            ->assertInertia(fn (Assert $page) => $page->where('documents.total', 0));
    });

    it('404s on a document number from another organisation', function (): void {
        $other = Organization::factory()->create();

        app(TenantContext::class)->runAs($other, function () use ($other): void {
            withLedger($other);
        });

        $this->get('/purchases/bills/BILL-000001')->assertNotFound();
    });
});

describe('vendor payments', function (): void {
    it('records a payment against a bill and settles it in full', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        $bill = PurchaseDocument::query()->sole();

        $this->post('/purchases/payments', [
            'contact_id' => $this->vendor->id,
            'bank_account_id' => $this->bank->id,
            'payment_date' => '2026-10-01',
            'amount' => '59000.00',
            'withholding_amount' => '5900.00',
            'allocations' => [
                ['document_id' => $bill->id, 'amount' => '59000.00'],
            ],
        ])->assertRedirect('/purchases/payments');

        $payment = Payment::query()->sole();

        expect($payment->number)->toBe('PAY-000001')
            ->and($payment->direction)->toBe('made')
            ->and($payment->amount_received)->toBeDecimal('53100.0000')
            // Settled in full, not left 5,900 short: the vendor considers it
            // paid, and so do we.
            ->and($bill->fresh()?->status)->toBe(PurchaseDocumentStatus::Paid);

        // And the withheld tax is a liability we now owe the authority, not a
        // saving. Phase 4's exit criterion says it has to reconcile.
        expect(BigDecimal::of(ledgerAccount(SystemAccount::WithholdingTaxPayable)->balance())->abs())
            ->toBeDecimal('5900.0000');
    });

    it('refuses to allocate more than was paid', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        $bill = PurchaseDocument::query()->sole();

        $this->post('/purchases/payments', [
            'contact_id' => $this->vendor->id,
            'bank_account_id' => $this->bank->id,
            'payment_date' => '2026-10-01',
            'amount' => '1000.00',
            'allocations' => [
                ['document_id' => $bill->id, 'amount' => '5000.00'],
            ],
        ])->assertSessionHasErrors('allocations');

        expect(Payment::query()->count())->toBe(0);
    });

    it('refuses withholding greater than the payment', function (): void {
        $this->post('/purchases/payments', [
            'contact_id' => $this->vendor->id,
            'bank_account_id' => $this->bank->id,
            'payment_date' => '2026-10-01',
            'amount' => '1000.00',
            'withholding_amount' => '2000.00',
        ])->assertSessionHasErrors('withholding_amount');
    });

    it('refuses money paid out of an expense account', function (): void {
        $this->post('/purchases/payments', [
            'contact_id' => $this->vendor->id,
            'bank_account_id' => $this->fees->id,
            'payment_date' => '2026-10-01',
            'amount' => '1000.00',
        ])->assertSessionHasErrors('bank_account_id');
    });

    it('refuses a customer who is not a vendor', function (): void {
        $customer = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Karachi Textiles',
        ]);

        $this->post('/purchases/payments', [
            'contact_id' => $customer->id,
            'bank_account_id' => $this->bank->id,
            'payment_date' => '2026-10-01',
            'amount' => '1000.00',
        ])->assertSessionHasErrors('contact_id');
    });

    it('offers a vendor their outstanding bills, due soonest first', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        $this->getJson("/purchases/payments/outstanding/{$this->vendor->id}")
            ->assertOk()
            ->assertJsonPath('outstanding.0.number', 'BILL-000001')
            ->assertJsonPath('outstanding.0.vendor_reference', 'ACME-1001')
            ->assertJsonPath('outstanding.0.balance_due', '59000.0000');
    });

    it('404s on a vendor from another organisation', function (): void {
        $other = Organization::factory()->create();

        $theirs = app(TenantContext::class)->runAs($other, fn (): Contact => Contact::query()->create([
            'kind' => ContactKind::Vendor,
            'display_name' => 'Theirs',
        ]));

        actingAsMember($this->organization, Role::Owner->value);

        $this->getJson("/purchases/payments/outstanding/{$theirs->id}")->assertNotFound();
    });
});

describe('the payables report', function (): void {
    it('ages the balance and says whether it reconciles', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        $this->get('/purchases/payables')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchases/Reports/Payables')
                ->where('totals.total', '59000.0000')
                // Phase 4's exit criterion, on the screen rather than only in
                // a test.
                ->where('reconciliation.reconciles', true)
                ->where('reconciliation.difference', '0.0000')
                // Stated as a positive figure: a payable is a credit balance,
                // and comparing the account's signed balance with a positive
                // aged total would report double the difference on books that
                // agree perfectly.
                ->where('reconciliation.control', '59000.0000'),
            );
    });

    it('separates what is already late from what falls due this week', function (): void {
        // Due 2026-10-15, a month before the frozen today.
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        $this->get('/purchases/payables')
            ->assertInertia(fn (Assert $page) => $page
                ->where('dueSoon.overdue', '59000.0000')
                // Neither figure is visible in the ageing buckets, where "not
                // yet due" mixes tomorrow with two months away.
                ->where('dueSoon.within_7_days', '0.0000')
                ->where('dueSoon.count_within_7_days', 0),
            );
    });

    it('notices when the report and the ledger disagree', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        /*
         * A manual journal straight to the payables control account. That is
         * legitimate — an opening balance arrives that way — but it means the
         * ageing no longer explains the whole balance, and the report has to
         * say so rather than let the reader assume.
         */
        $ap = ledgerAccount(SystemAccount::AccountsPayable);

        app(PostJournalEntry::class)->handle(
            JournalDraft::inBaseCurrency(
                date: $this->inYear,
                currency: 'PKR',
                lines: [
                    JournalLineDraft::debit($this->fees->id, '5000.0000'),
                    JournalLineDraft::credit($ap->id, '5000.0000'),
                ],
                source: ['manual', null, 'issue'],
            ),
            $this->owner,
        );

        $this->get('/purchases/payables')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reconciliation.reconciles', false)
                ->where('reconciliation.difference', '5000.0000'),
            );
    });
});

describe('the vendor statement', function (): void {
    it('appears on the contact page and reconciles to payables', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        $this->get("/sales/customers/{$this->vendor->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Contacts/Show')
                ->where('purchases.statement.closing', '59000.0000')
                ->where('purchases.statement.rows.0.number', 'BILL-000001')
                ->where('purchases.statement.rows.0.url', 'bills')
                ->where('purchases.payable', '59000.0000')
                // Past due, so not in the current bucket.
                ->where('purchases.aging.current', '0.0000'),
            );
    });

    it('shows nothing for a contact who is only a customer', function (): void {
        $customer = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Karachi Textiles',
        ]);

        // Null rather than an empty statement, so the page shows no section
        // at all.
        $this->get("/sales/customers/{$customer->id}")
            ->assertInertia(fn (Assert $page) => $page->where('purchases', null));
    });

    it('keeps the two balances apart for a contact who is both', function (): void {
        $both = Contact::query()->create([
            'kind' => ContactKind::Both,
            'display_name' => 'Lahore Mills',
            'payment_terms_days' => 30,
        ]);

        $payload = ($this->payload)();
        $payload['contact_id'] = $both->id;

        $this->post('/purchases/bills', $payload);
        $this->post('/purchases/bills/BILL-000001/approve');

        $this->post('/sales/invoices', [
            'contact_id' => $both->id,
            'issue_date' => $this->inYear->toDateString(),
            'lines' => [[
                'description' => 'Yarn sold back to them',
                'quantity' => '1',
                'unit_price' => '20000.00',
                'tax_id' => $this->gst->id,
                'revenue_account_id' => Account::query()->where('code', '4010')->sole()->id,
            ]],
        ]);
        $this->post('/sales/invoices/INV-000001/issue');

        /*
         * Both, in full, never netted. Netting them would hide a receivable
         * behind a payable and leave neither collectable nor payable on its
         * own — and the accounts are separate in the ledger for exactly that
         * reason.
         */
        $this->get("/sales/customers/{$both->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('contact.outstanding', '23600.0000')
                ->where('purchases.payable', '59000.0000'),
            );

        $this->get('/sales/customers')
            ->assertInertia(function (Assert $page): void {
                $row = collect($page->toArray()['props']['contacts']['data'])
                    ->firstWhere('display_name', 'Lahore Mills');

                expect($row['outstanding'])->toBe('23600.0000');
                expect($row['payable'])->toBe('59000.0000');
            });
    });

    it('refuses to archive a vendor we still owe', function (): void {
        $this->post('/purchases/bills', ($this->payload)());
        $this->post('/purchases/bills/BILL-000001/approve');

        // The payable would stay on the balance sheet with nobody attached.
        $this->delete("/sales/customers/{$this->vendor->id}")
            ->assertSessionHas('error');

        expect($this->vendor->fresh()?->archived_at)->toBeNull();
    });
});

describe('authorisation, by role', function (): void {
    it('lets a viewer read the purchase screens and change nothing', function (): void {
        $this->post('/purchases/bills', ($this->payload)());

        actingAsMember($this->organization, Role::Viewer->value);

        $this->get('/purchases/bills')->assertOk();
        $this->get('/purchases/payments')->assertOk();
        $this->get('/purchases/payables')->assertOk();

        $this->get('/purchases/bills/new')->assertForbidden();
        $this->post('/purchases/bills', ($this->payload)())->assertForbidden();
        $this->post('/purchases/bills/BILL-000001/approve')->assertForbidden();
        $this->post('/purchases/bills/BILL-000001/void')->assertForbidden();
        $this->get('/purchases/payments/new')->assertForbidden();

        expect(PurchaseDocument::query()->count())->toBe(1);
    });

    /*
     * The two halves of the separation of duties this module exists for.
     * Neither role can both originate and authorise, and that is the whole
     * reason they are separate roles rather than one "purchasing" role.
     */
    it('lets a bookkeeper prepare a bill but not approve it', function (): void {
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->post('/purchases/bills', ($this->payload)())->assertSessionHasNoErrors();

        $this->post('/purchases/bills/BILL-000001/approve')->assertForbidden();

        expect(PurchaseDocument::query()->sole()->status)
            ->toBe(PurchaseDocumentStatus::Draft);
    });

    it('lets an approver approve a bill but not create one', function (): void {
        // Prepared by somebody with the right to.
        $this->post('/purchases/bills', ($this->payload)());

        actingAsMember($this->organization, Role::Approver->value);

        $this->post('/purchases/bills', ($this->payload)(vendorRef: 'ACME-2'))
            ->assertForbidden();

        $this->post('/purchases/bills/BILL-000001/approve')->assertSessionHas('success');

        expect(PurchaseDocument::query()->count())->toBe(1)
            ->and(PurchaseDocument::query()->sole()->status)
            ->toBe(PurchaseDocumentStatus::Open);
    });

    it('does not require the ledger permission to approve, only to void', function (): void {
        /*
         * The deliberate difference from the sales side.
         *
         * Issuing an invoice needs `accounting.post` on top of `sales.send`,
         * because "send" is not a posting authority and a bookkeeper has it.
         * Here `purchases.approve` IS the posting authority — it is what the
         * Approver role is built around, and that role has no
         * `accounting.post`. Requiring it would lock the role out of the one
         * action it exists to perform.
         *
         * Voiding is different: it has no dedicated permission, so the ledger
         * write needs the ledger permission.
         */
        $this->post('/purchases/bills', ($this->payload)());

        actingAsMember($this->organization, Role::Approver->value);

        $this->post('/purchases/bills/BILL-000001/approve')->assertSessionHas('success');

        expect($this->owner->can('accounting.post'))->toBeTrue();

        // The Approver can approve but cannot void, because voiding reverses.
        $this->post('/purchases/bills/BILL-000001/void')->assertForbidden();
    });

    it('needs the payment permission to pay, separately from approving', function (): void {
        actingAsMember($this->organization, Role::Bookkeeper->value);

        // A bookkeeper prepares but never moves money.
        $this->post('/purchases/payments', [
            'contact_id' => $this->vendor->id,
            'bank_account_id' => $this->bank->id,
            'payment_date' => '2026-10-01',
            'amount' => '1000.00',
        ])->assertForbidden();

        expect(Payment::query()->count())->toBe(0);
    });
});
