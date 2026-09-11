<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Sales\Models\SalesDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| The opening-balances screen
|---------------------------------------------------------------------------
|
| The domain tests prove §4.13 posts correctly. These cover the screen's own
| job, which is a reporting job: saying how much is left in opening balance
| equity and never letting a half-finished migration look finished.
|
| Plus the usual HTTP-layer properties — the write needs its own permission,
| and the control accounts are not offered.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-20');

    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->owner = actingAsMember($this->organization, Role::Owner->value);

    $this->bank = ledgerAccount('1020');
    $this->inventory = ledgerAccount(SystemAccount::Inventory);
    $this->ar = ledgerAccount(SystemAccount::AccountsReceivable);
    $this->loan = Account::query()->where('code', '2700')->sole();

    // The first day the books cover, which is where an opening position
    // belongs: the year then opens with these figures.
    $this->openingDate = '2026-07-01';

    $this->customer = Contact::query()->create([
        'kind' => ContactKind::Customer,
        'display_name' => 'Karachi Textiles',
        'payment_terms_days' => 30,
    ]);

    $this->vendor = Contact::query()->create([
        'kind' => ContactKind::Vendor,
        'display_name' => 'Sindh Yarn Traders',
        'payment_terms_days' => 30,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('serves the screen and suggests the first day of the books', function (): void {
    $this->get('/accounting/opening-balances')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/OpeningBalances')
            ->where('openingDate', $this->openingDate)
            ->where('entered.accounts', false)
            ->where('equity.balance', '0.0000'),
        );
});

it('does not offer the control accounts', function (): void {
    /*
     * Excluded from the picker rather than offered and refused. Receivables
     * and payables come across as documents, and a list that includes them
     * invites the mistake the action then has to explain.
     */
    $this->get('/accounting/opening-balances')
        ->assertInertia(function (Assert $page): void {
            $ids = collect($page->toArray()['props']['accounts'])->pluck('value');

            expect($ids)->not->toContain($this->ar->id)
                ->and($ids)->not->toContain(ledgerAccount(SystemAccount::AccountsPayable)->id)
                // But an ordinary account is there.
                ->and($ids)->toContain($this->bank->id);
        });
});

it('posts account balances and reports what went to equity', function (): void {
    $this->post('/accounting/opening-balances/accounts', [
        'as_of' => $this->openingDate,
        'balances' => [
            ['account_id' => $this->bank->id, 'debit' => '500000.00'],
            ['account_id' => $this->inventory->id, 'debit' => '300000.00'],
            ['account_id' => $this->loan->id, 'credit' => '250000.00'],
        ],
    ])->assertSessionHas('success');

    $this->get('/accounting/opening-balances')
        ->assertInertia(fn (Assert $page) => $page
            ->where('entered.accounts', true)
            /*
             * 500,000 + 300,000 − 250,000, and POSITIVE.
             *
             * Equity is credit-normal, so a credit balance reads as positive
             * — the signed-balance convention is "positive means the account
             * holds what its type expects". A negative figure here would mean
             * negative equity, which is a different and much worse statement.
             */
            ->where('equity.balance', '550000.0000')
            ->where('equity.is_settled', false),
        );
});

it('refuses a balance aimed at a control account, with a reason', function (): void {
    $response = $this->post('/accounting/opening-balances/accounts', [
        'as_of' => $this->openingDate,
        'balances' => [['account_id' => $this->ar->id, 'debit' => '100000.00']],
    ]);

    $response->assertSessionHasErrors('balances');

    expect(session('errors')?->first('balances'))->toContain('counted twice');
});

it('brings an unpaid invoice across, ageing from its real date', function (): void {
    /*
     * Two dates, and they differ on purpose. The invoice was issued in June;
     * the books start in July. It keeps June so the ageing is right, and the
     * ENTRY lands in July, inside a period this system keeps.
     */
    $this->post('/accounting/opening-balances/invoices', [
        'contact_id' => $this->customer->id,
        'amount' => '118000.00',
        'issue_date' => '2026-06-15',
        'due_date' => '2026-07-15',
        'reference' => 'OLD-4471',
        'opening_date' => $this->openingDate,
    ])->assertSessionHas('success');

    $invoice = SalesDocument::query()->sole();

    expect($invoice->is_opening_balance)->toBeTrue()
        ->and($invoice->issue_date->toDateString())->toBe('2026-06-15')
        ->and($invoice->total)->toBeDecimal('118000.0000')
        // No tax: it was reported in the old system's return.
        ->and($invoice->tax_total)->toBeDecimal('0.0000');

    // The entry is dated on the opening date, not the invoice's own.
    expect($invoice->journalEntry()->sole()->entry_date->toDateString())
        ->toBe($this->openingDate);

    // And it appears on the screen as an overdue receivable, which is the
    // chasing list a migrated business wants on day one.
    $this->get('/accounting/opening-balances')
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.0.number', $invoice->number)
            ->where('invoices.0.balance_due', '118000.0000')
            ->where('invoices.0.is_overdue', true)
            ->where('entered.invoices', 1),
        );
});

it('brings an unpaid bill across', function (): void {
    $this->post('/accounting/opening-balances/bills', [
        'contact_id' => $this->vendor->id,
        'amount' => '59000.00',
        'issue_date' => '2026-06-20',
        'reference' => 'THEIRS-99',
        'opening_date' => $this->openingDate,
    ])->assertSessionHas('success');

    $bill = PurchaseDocument::query()->sole();

    expect($bill->is_opening_balance)->toBeTrue()
        ->and($bill->vendor_reference)->toBe('THEIRS-99')
        ->and($bill->balanceDue())->toBeDecimal('59000.0000');

    $this->get('/accounting/opening-balances')
        ->assertInertia(fn (Assert $page) => $page->where('entered.bills', 1));
});

it('refuses a customer for a bill and a vendor for an invoice', function (): void {
    // The pickers are already filtered, so this is the guard behind them.
    $this->post('/accounting/opening-balances/invoices', [
        'contact_id' => $this->vendor->id,
        'amount' => '1000.00',
        'issue_date' => '2026-06-15',
        'opening_date' => $this->openingDate,
    ])->assertSessionHasErrors('contact_id');

    $this->post('/accounting/opening-balances/bills', [
        'contact_id' => $this->customer->id,
        'amount' => '1000.00',
        'issue_date' => '2026-06-15',
        'opening_date' => $this->openingDate,
    ])->assertSessionHasErrors('contact_id');
});

it('says so when the migration is complete', function (): void {
    /*
     * The property the whole screen exists for: once everything is across,
     * opening balance equity holds exactly what the business was worth, and
     * nothing is left unexplained.
     */
    $this->post('/accounting/opening-balances/accounts', [
        'as_of' => $this->openingDate,
        'balances' => [
            ['account_id' => $this->bank->id, 'debit' => '500000.00'],
            ['account_id' => $this->loan->id, 'credit' => '500000.00'],
        ],
    ]);

    // Assets exactly equal liabilities, so there is nothing to hold.
    $this->get('/accounting/opening-balances')
        ->assertInertia(fn (Assert $page) => $page
            ->where('equity.balance', '0.0000')
            ->where('equity.is_settled', true),
        );
});

it('404s on an opening document from another organisation', function (): void {
    $other = Organization::factory()->create();

    app(TenantContext::class)->runAs($other, function () use ($other): void {
        withLedger($other);
    });

    // Nothing of theirs is visible here.
    $this->get('/accounting/opening-balances')
        ->assertInertia(fn (Assert $page) => $page
            ->where('entered.invoices', 0)
            ->where('entered.bills', 0),
        );
});

describe('authorisation', function (): void {
    it('needs the opening-balances permission, which a bookkeeper lacks', function (): void {
        /*
         * Its own permission, and not one a bookkeeper has: entering an
         * opening position states where a business stood before this system
         * existed, which nobody can check against a document in it.
         */
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->get('/accounting/opening-balances')->assertForbidden();

        $this->post('/accounting/opening-balances/accounts', [
            'as_of' => $this->openingDate,
            'balances' => [['account_id' => $this->bank->id, 'debit' => '1.00']],
        ])->assertForbidden();

        $this->post('/accounting/opening-balances/invoices', [
            'contact_id' => $this->customer->id,
            'amount' => '1000.00',
            'issue_date' => '2026-06-15',
            'opening_date' => $this->openingDate,
        ])->assertForbidden();
    });

    it('lets an accountant enter them', function (): void {
        actingAsMember($this->organization, Role::Accountant->value);

        $this->get('/accounting/opening-balances')->assertOk();

        $this->post('/accounting/opening-balances/accounts', [
            'as_of' => $this->openingDate,
            'balances' => [['account_id' => $this->bank->id, 'debit' => '1000.00']],
        ])->assertSessionHas('success');
    });
});
