<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Documents\Models\Attachment;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\MileageRate;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Tax\Models\Tax;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| The expense screens, over HTTP
|---------------------------------------------------------------------------
|
| The domain tests already prove the arithmetic, the posting and the workflow.
| These cover what only the HTTP layer can: that every write is refused
| without its own permission, that a URL naming another organisation's record
| produces a 404, and that a receipt is served through the application rather
| than from storage.
|
| Authorisation is asserted per ROLE, and on this module the roles carry the
| point: a bookkeeper records expenses and cannot approve them, an approver
| approves and cannot record. The self-approval rule is asserted on top of
| both, because it is the one rule that holds even for somebody with every
| permission.
*/

beforeEach(function (): void {
    Storage::fake('s3');
    Carbon::setTestNow('2026-11-20');

    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);

    /*
     * TWO members, deliberately.
     *
     * With one, the controller treats the organisation as a sole trader and
     * permits self-approval — so a single-member fixture would quietly make
     * every separation-of-duties assertion below vacuous.
     */
    $this->owner = actingAsMember($this->organization, Role::Owner->value);
    $this->second = actingAsMember($this->organization, Role::Admin->value);

    // Back to the owner, since actingAsMember switches the acting user.
    $this->actingAs($this->owner);

    $this->gst = Tax::query()->where('code', 'GST18')->sole();
    $this->travel = Account::query()->where('code', '6500')->sole();
    $this->bank = ledgerAccount('1020');

    $this->inYear = Carbon::parse('2026-09-15');

    /** The payload a draft expense is saved from. */
    $this->payload = fn (
        string $price = '10000.00',
        bool $claimable = true,
        string $mode = 'company',
        bool $billable = false,
        ?string $billTo = null,
    ): array => array_filter([
        'expense_date' => $this->inYear->toDateString(),
        'merchant' => 'Careem',
        'payment_mode' => $mode,
        'paid_through_account_id' => $mode === 'company' ? $this->bank->id : null,
        'reimburse_user_id' => $mode === 'reimbursable' ? $this->owner->id : null,
        'is_billable' => $billable,
        'billable_contact_id' => $billTo,
        'lines' => [
            [
                'description' => 'Airport transfers, client visit',
                'quantity' => '1',
                'unit_price' => $price,
                'tax_id' => $this->gst->id,
                'debit_account_id' => $this->travel->id,
                'tax_is_claimable' => $claimable,
            ],
        ],
    ], static fn (mixed $value): bool => $value !== null);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('the expense screens', function (): void {
    it('serves the list', function (): void {
        $this->get('/expenses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Expenses/Index'));
    });

    it('404s on a path under expenses that does not exist', function (): void {
        // Expenses has no placeholder entry any more, so nothing claims the
        // module is still arriving.
        $this->get('/expenses/categories')->assertNotFound();
    });

    it('saves a draft and computes its totals', function (): void {
        $this->post('/expenses', ($this->payload)())
            ->assertRedirect('/expenses/EXP-000001');

        $expense = Expense::query()->sole();

        expect($expense->status)->toBe(ExpenseStatus::Draft)
            ->and($expense->total)->toBeDecimal('11800.0000')
            ->and($expense->tax_claimable_total)->toBeDecimal('1800.0000');
    });

    it('refuses a company-paid expense with no account', function (): void {
        $payload = ($this->payload)();
        unset($payload['paid_through_account_id']);

        $this->post('/expenses', $payload)
            ->assertSessionHasErrors('paid_through_account_id');
    });

    it('refuses a reimbursable expense with nobody to reimburse', function (): void {
        $payload = ($this->payload)(mode: 'reimbursable');
        unset($payload['reimburse_user_id']);

        $this->post('/expenses', $payload)
            ->assertSessionHasErrors('reimburse_user_id');
    });

    it('refuses money paid out of a revenue account', function (): void {
        $payload = ($this->payload)();
        $payload['paid_through_account_id'] = Account::query()->where('code', '4010')->sole()->id;

        $this->post('/expenses', $payload)
            ->assertSessionHasErrors('paid_through_account_id');
    });

    it('refuses a line charged to an income account', function (): void {
        $payload = ($this->payload)();
        $payload['lines'][0]['debit_account_id'] = Account::query()->where('code', '4010')->sole()->id;

        $this->post('/expenses', $payload)
            ->assertSessionHasErrors('lines.0.debit_account_id');
    });

    it('accepts a line charged to an asset account', function (): void {
        // A laptop is an asset, and the expense screen is where one gets
        // bought.
        $payload = ($this->payload)();
        $payload['lines'][0]['debit_account_id'] = ledgerAccount(SystemAccount::Inventory)->id;

        $this->post('/expenses', $payload)->assertSessionHasNoErrors();
    });

    it('refuses billable with no customer named', function (): void {
        $payload = ($this->payload)(billable: true);

        $this->post('/expenses', $payload)
            ->assertSessionHasErrors('billable_contact_id');
    });

    it('shows a draft, and says plainly that nothing has posted', function (): void {
        $this->post('/expenses', ($this->payload)());

        $this->get('/expenses/EXP-000001')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Expenses/Show')
                ->where('expense.number', 'EXP-000001')
                ->where('expense.status', 'draft')
                ->where('expense.merchant', 'Careem')
                ->where('expense.has_receipt', false)
                ->where('expense.journal_entry_no', null),
            );
    });

    it('deletes a draft outright', function (): void {
        $this->post('/expenses', ($this->payload)());

        $this->delete('/expenses/EXP-000001')->assertRedirect('/expenses');

        expect(Expense::query()->count())->toBe(0);
    });

    it('summarises what is awaiting approval and what is owed to people', function (): void {
        // One submitted, one approved and reimbursable.
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->post('/expenses', ($this->payload)(price: '5000.00', mode: 'reimbursable'));
        $this->post('/expenses/EXP-000002/submit');

        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000002/approve');
        $this->actingAs($this->owner);

        $this->get('/expenses')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.awaiting_approval', '11800.0000')
                ->where('summary.awaiting_count', 1)
                // The figure a payroll run needs, and nothing else shows.
                ->where('summary.owed_to_people', '5900.0000'),
            );
    });

    it('404s on an expense number from another organisation', function (): void {
        $other = Organization::factory()->create();

        app(TenantContext::class)->runAs($other, function () use ($other): void {
            withLedger($other);
        });

        $this->get('/expenses/EXP-000001')->assertNotFound();
    });
});

describe('the approval workflow', function (): void {
    it('submits, then refuses a second submission', function (): void {
        $this->post('/expenses', ($this->payload)());

        $this->post('/expenses/EXP-000001/submit')->assertSessionHas('success');

        expect(Expense::query()->sole()->status)->toBe(ExpenseStatus::Submitted);

        $this->post('/expenses/EXP-000001/submit')->assertSessionHas('error');
    });

    it('refuses to let the claimant approve their own expense', function (): void {
        /*
         * The rule that survives every permission. The owner has every
         * permission there is, and still cannot approve what they claimed —
         * because an approval by the person claiming the money is not a
         * review.
         */
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->post('/expenses/EXP-000001/approve')->assertSessionHas('error');

        expect(Expense::query()->sole()->status)->toBe(ExpenseStatus::Submitted);
    });

    it('hides the approve button from whoever submitted it', function (): void {
        // Hidden rather than refused: a control that appears and then always
        // fails teaches people the software is broken.
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->get('/expenses/EXP-000001')
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.approve', false)
                // Rejecting your own claim is harmless, so it stays offered.
                ->where('can.reject', true),
            );
    });

    it('lets somebody else approve it, which posts', function (): void {
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->actingAs($this->second);

        $this->post('/expenses/EXP-000001/approve')->assertSessionHas('success');

        $expense = Expense::query()->sole();

        expect($expense->status)->toBe(ExpenseStatus::Approved)
            ->and($expense->approved_by)->toBe($this->second->id)
            ->and($expense->journal_entry_id)->not->toBeNull();
    });

    it('shows the posted entry, the approver and the tax treatment', function (): void {
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000001/approve');

        $this->get('/expenses/EXP-000001')
            ->assertInertia(function (Assert $page): void {
                $page->where('expense.status', 'approved')
                    ->where('expense.journal_entry_no', 'JE-000001')
                    ->where('expense.tax_claimable_total', '1800.0000')
                    ->where('expense.tax_capitalised', '0.0000')
                    // Both people are named, because the whole point of the
                    // step is that they are different people.
                    ->where('expense.submitted_by_name', $this->owner->name)
                    ->where('expense.approved_by_name', $this->second->name);

                $summary = $page->toArray()['props']['expense']['tax_summary'];

                expect($summary)->toHaveCount(1);
                expect($summary[0]['claimable'])->toBe('1800.0000');
            });
    });

    it('states the unclaimable tax separately when a line is blocked', function (): void {
        $this->post('/expenses', ($this->payload)(claimable: false));

        $this->get('/expenses/EXP-000001')
            ->assertInertia(function (Assert $page): void {
                $page->where('expense.tax_total', '1800.0000')
                    ->where('expense.tax_claimable_total', '0.0000')
                    ->where('expense.tax_capitalised', '1800.0000');

                $line = $page->toArray()['props']['expense']['lines'][0];

                expect($line['tax_is_claimable'])->toBeFalse();
                expect($line['capitalised_cost'])->toBe('11800.0000');
            });
    });

    it('sends one back with a reason, and refuses one without', function (): void {
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->actingAs($this->second);

        $this->post('/expenses/EXP-000001/reject')->assertSessionHasErrors('reason');

        $this->post('/expenses/EXP-000001/reject', ['reason' => 'No receipt'])
            ->assertSessionHas('success');

        $expense = Expense::query()->sole();

        expect($expense->status)->toBe(ExpenseStatus::Rejected)
            ->and($expense->rejection_reason)->toBe('No receipt');
    });

    it('lets a rejected expense be edited and resubmitted', function (): void {
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000001/reject', ['reason' => 'Wrong category']);
        $this->actingAs($this->owner);

        // Editable again, which is the point of a rejection.
        $this->get('/expenses/EXP-000001/edit')->assertOk();

        $this->patch('/expenses/EXP-000001', ($this->payload)(price: '9000.00'))
            ->assertSessionHasNoErrors();

        $expense = Expense::query()->sole();

        expect($expense->status)->toBe(ExpenseStatus::Draft)
            ->and($expense->rejection_reason)->toBeNull()
            ->and($expense->total)->toBeDecimal('10620.0000');
    });

    it('refuses to edit or delete an approved expense', function (): void {
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000001/approve');

        $this->get('/expenses/EXP-000001/edit')->assertForbidden();
        $this->patch('/expenses/EXP-000001', ($this->payload)())->assertSessionHas('error');
        $this->delete('/expenses/EXP-000001')->assertSessionHas('error');

        expect(Expense::query()->sole()->status)->toBe(ExpenseStatus::Approved);
    });

    it('voids an approved expense, reversing it', function (): void {
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000001/approve');

        $this->post('/expenses/EXP-000001/void', ['reason' => 'Personal, not business'])
            ->assertSessionHas('success');

        $expense = Expense::query()->sole();

        expect($expense->status)->toBe(ExpenseStatus::Void)
            ->and($expense->void_journal_entry_id)->not->toBeNull()
            // Both entries and the approval survive.
            ->and($expense->journal_entry_id)->not->toBeNull()
            ->and($expense->approved_at)->not->toBeNull();
    });
});

describe('receipts', function (): void {
    it('attaches one, lists it, and serves it through the application', function (): void {
        $this->post('/expenses', ($this->payload)());

        $this->post('/expenses/EXP-000001/receipts', [
            'receipt' => UploadedFile::fake()->create('taxi.jpg', 40),
        ])->assertSessionHas('success');

        $attachment = Attachment::query()->sole();

        Storage::disk('s3')->assertExists($attachment->path);

        $this->get('/expenses/EXP-000001')
            ->assertInertia(fn (Assert $page) => $page
                ->where('expense.has_receipt', true)
                ->where('expense.receipts.0.name', 'taxi.jpg')
                ->where('expense.receipts.0.is_image', true),
            );

        /*
         * Served by us, not by storage. A presigned URL is a financial
         * record that leaks with no audit trail, no permission check and no
         * expiry anybody can rely on.
         */
        $response = $this->get("/expenses/EXP-000001/receipts/{$attachment->id}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('refuses a file that is not a receipt', function (): void {
        $this->post('/expenses', ($this->payload)());

        $this->post('/expenses/EXP-000001/receipts', [
            'receipt' => UploadedFile::fake()->create('macros.xlsm', 20),
        ])->assertSessionHasErrors('receipt');

        expect(Attachment::query()->count())->toBe(0);
    });

    it('refuses a file over the size limit', function (): void {
        $this->post('/expenses', ($this->payload)());

        $this->post('/expenses/EXP-000001/receipts', [
            'receipt' => UploadedFile::fake()->create('huge.jpg', 11 * 1024),
        ])->assertSessionHasErrors('receipt');
    });

    it('refuses the same file twice', function (): void {
        $this->post('/expenses', ($this->payload)());

        $this->post('/expenses/EXP-000001/receipts', [
            'receipt' => UploadedFile::fake()->create('receipt.jpg', 20),
        ]);

        // A duplicate receipt is usually a duplicate claim.
        $this->post('/expenses/EXP-000001/receipts', [
            'receipt' => UploadedFile::fake()->create('receipt.jpg', 20),
        ])->assertSessionHasErrors('receipt');

        expect(Attachment::query()->count())->toBe(1);
    });

    it('removes one from a draft, and refuses once approved', function (): void {
        $this->post('/expenses', ($this->payload)());

        $this->post('/expenses/EXP-000001/receipts', [
            'receipt' => UploadedFile::fake()->create('receipt.jpg', 20),
        ]);

        $first = Attachment::query()->sole();
        $path = $first->path;

        $this->delete("/expenses/EXP-000001/receipts/{$first->id}")
            ->assertSessionHas('success');

        expect(Attachment::query()->count())->toBe(0);
        Storage::disk('s3')->assertMissing($path);

        // And once approved, the receipts are part of the record.
        $this->post('/expenses/EXP-000001/receipts', [
            'receipt' => UploadedFile::fake()->create('again.jpg', 20),
        ]);

        $second = Attachment::query()->sole();

        $this->post('/expenses/EXP-000001/submit');
        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000001/approve');

        $this->delete("/expenses/EXP-000001/receipts/{$second->id}")
            ->assertSessionHas('error');

        expect(Attachment::query()->count())->toBe(1);
    });

    it('404s on a receipt reached through the wrong expense', function (): void {
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses', ($this->payload)(price: '500.00'));

        $this->post('/expenses/EXP-000001/receipts', [
            'receipt' => UploadedFile::fake()->create('receipt.jpg', 20),
        ]);

        $attachment = Attachment::query()->sole();

        // An id guessed from another record must not be readable through a
        // URL the user does have access to.
        $this->get("/expenses/EXP-000002/receipts/{$attachment->id}")->assertNotFound();
    });

    it('404s on a receipt belonging to another organisation', function (): void {
        $other = Organization::factory()->create();

        $theirs = app(TenantContext::class)->runAs($other, function () use ($other): Attachment {
            withLedger($other);

            $attachment = new Attachment;

            $attachment->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $other->id,
                'attachable_type' => (new Expense)->getMorphClass(),
                'attachable_id' => (string) Str::uuid7(),
                'disk' => 's3',
                'path' => 'organizations/theirs/receipt.jpg',
                'original_name' => 'theirs.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 100,
                'checksum' => str_repeat('a', 64),
            ])->save();

            return $attachment;
        });

        actingAsMember($this->organization, Role::Owner->value);

        $this->post('/expenses', ($this->payload)());

        $this->get("/expenses/EXP-000001/receipts/{$theirs->id}")->assertNotFound();
    });
});

describe('mileage', function (): void {
    it('sets a rate, closing the outgoing one', function (): void {
        $this->post('/settings/mileage', [
            'name' => 'Standard',
            'unit' => 'km',
            'rate' => '25.00',
            'effective_from' => '2026-07-01',
        ])->assertSessionHas('success');

        $this->post('/settings/mileage', [
            'name' => 'Raised',
            'unit' => 'km',
            'rate' => '30.00',
            'effective_from' => '2026-10-01',
        ])->assertSessionHas('success');

        // Two rows, one open: "the rate on this date" has one answer.
        expect(MileageRate::query()->count())->toBe(2)
            ->and(MileageRate::query()->current()->count())->toBe(1)
            ->and(MileageRate::query()->where('name', 'Standard')->sole()->effective_to?->toDateString())
            ->toBe('2026-09-30')
            /*
             * The stored FIGURE, not just the row.
             *
             * Asserting the count alone let a real bug through: the string
             * and the model were both called `$rate` in the controller, so
             * the model went into an attribute cast to a string, __toString()
             * serialised the model, and serialising cast the attribute again.
             * The row existed; its rate was a stack overflow.
             */
            ->and(MileageRate::query()->current()->sole()->rate)->toBeDecimal('30.0000');

        $this->get('/settings/mileage')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/MileageRates'));
    });

    it('claims a distance at the rate in force on the expense’s date', function (): void {
        $this->post('/settings/mileage', [
            'name' => 'Standard',
            'unit' => 'km',
            'rate' => '25.00',
            'effective_from' => '2026-07-01',
        ]);

        $this->post('/expenses', [
            'expense_date' => $this->inYear->toDateString(),
            'payment_mode' => 'reimbursable',
            'reimburse_user_id' => $this->owner->id,
            'lines' => [[
                'kind' => 'mileage',
                'description' => 'Karachi to Hyderabad and back',
                'distance' => '320',
                'debit_account_id' => $this->travel->id,
            ]],
        ])->assertSessionHasNoErrors();

        $expense = Expense::query()->sole();

        expect($expense->total)->toBeDecimal('8000.0000')
            ->and($expense->lines()->sole()->unit)->toBe('km');
    });

    it('needs the accounting settings permission to change a rate', function (): void {
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->get('/settings/mileage')->assertForbidden();

        $this->post('/settings/mileage', [
            'name' => 'Sneaky',
            'unit' => 'km',
            'rate' => '999.00',
            'effective_from' => '2026-07-01',
        ])->assertForbidden();
    });
});

describe('billable expenses', function (): void {
    beforeEach(function (): void {
        $this->customer = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Karachi Textiles',
            'payment_terms_days' => 30,
        ]);
    });

    it('lists what is waiting to be rebilled, once approved', function (): void {
        $this->post('/expenses', ($this->payload)(billable: true, billTo: $this->customer->id));

        // Billable, but not yet a cost the books have recognised.
        $this->get('/expenses?view=to_bill')
            ->assertInertia(fn (Assert $page) => $page->where('expenses.total', 0));

        $this->post('/expenses/EXP-000001/submit');
        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000001/approve');
        $this->actingAs($this->owner);

        $this->get('/expenses?view=to_bill')
            ->assertInertia(fn (Assert $page) => $page
                ->where('expenses.total', 1)
                ->where('summary.to_rebill', '11800.0000'),
            );
    });

    it('creates a draft invoice at cost from chosen expenses', function (): void {
        $this->post('/expenses', ($this->payload)(billable: true, billTo: $this->customer->id));
        $this->post('/expenses/EXP-000001/submit');

        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000001/approve');
        $this->actingAs($this->owner);

        $expense = Expense::query()->sole();

        $this->post('/expenses/rebill', ['expenses' => [$expense->id]])
            ->assertRedirect('/sales/invoices/INV-000001');

        expect($expense->fresh()?->billed_document_id)->not->toBeNull();

        // A DRAFT: what to charge for a rebilled cost is a commercial
        // decision, and issuing it would post revenue at a figure nobody
        // chose.
        $this->get('/sales/invoices/INV-000001')
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.status', 'draft')
                ->where('document.subtotal', '10000.0000'),
            );
    });

    it('needs the sales permission to rebill, not merely the expense one', function (): void {
        $this->post('/expenses', ($this->payload)(billable: true, billTo: $this->customer->id));
        $this->post('/expenses/EXP-000001/submit');

        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000001/approve');

        $expense = Expense::query()->sole();

        // The Approver role sees expenses and creates no sales documents, so
        // rebilling — which creates an invoice — is not theirs to do.
        actingAsMember($this->organization, Role::Approver->value);

        $this->post('/expenses/rebill', ['expenses' => [$expense->id]])->assertForbidden();
    });
});

describe('authorisation, by role', function (): void {
    it('lets a viewer read the expense screens and change nothing', function (): void {
        $this->post('/expenses', ($this->payload)());

        actingAsMember($this->organization, Role::Viewer->value);

        $this->get('/expenses')->assertOk();
        $this->get('/expenses/EXP-000001')->assertOk();

        $this->get('/expenses/new')->assertForbidden();
        $this->post('/expenses', ($this->payload)())->assertForbidden();
        $this->post('/expenses/EXP-000001/submit')->assertForbidden();
        $this->post('/expenses/EXP-000001/approve')->assertForbidden();
        $this->post('/expenses/EXP-000001/receipts', [
            'receipt' => UploadedFile::fake()->create('receipt.jpg', 20),
        ])->assertForbidden();

        expect(Expense::query()->count())->toBe(1);
    });

    /*
     * The two halves of the separation of duties. Neither role can both
     * record and approve, which is the whole reason they are separate roles.
     */
    it('lets a bookkeeper record and submit but not approve', function (): void {
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->post('/expenses', ($this->payload)())->assertSessionHasNoErrors();
        $this->post('/expenses/EXP-000001/submit')->assertSessionHas('success');

        $this->post('/expenses/EXP-000001/approve')->assertForbidden();

        expect(Expense::query()->sole()->status)->toBe(ExpenseStatus::Submitted);
    });

    it('lets an approver approve but not record', function (): void {
        // Recorded by somebody with the right to.
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        actingAsMember($this->organization, Role::Approver->value);

        $this->post('/expenses', ($this->payload)())->assertForbidden();

        $this->post('/expenses/EXP-000001/approve')->assertSessionHas('success');

        expect(Expense::query()->count())->toBe(1)
            ->and(Expense::query()->sole()->status)->toBe(ExpenseStatus::Approved);
    });

    it('needs the ledger permission to void, on top of the delete one', function (): void {
        $this->post('/expenses', ($this->payload)());
        $this->post('/expenses/EXP-000001/submit');

        $this->actingAs($this->second);
        $this->post('/expenses/EXP-000001/approve');

        /*
         * Voiding posts a reversing entry and has no dedicated permission —
         * unlike approval, where `expenses.approve` IS the posting authority.
         * So the ledger write needs the ledger permission.
         */
        actingAsMember($this->organization, Role::Approver->value);

        $this->post('/expenses/EXP-000001/void')->assertForbidden();

        expect(Expense::query()->sole()->status)->toBe(ExpenseStatus::Approved);
    });
});
