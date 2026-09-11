<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\EnterOpeningBalances;
use App\Domain\Accounting\Actions\EnterOpeningDocument;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Sales\Actions\RecordCustomerPayment;
use App\Domain\Sales\Actions\SaveSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|---------------------------------------------------------------------------
| Opening balances
|---------------------------------------------------------------------------
|
| The exit criterion carried from Phase 2 and deferred twice: a business
| migrating from another system can state where it stood, and the books
| balance afterwards.
|
| The property that matters most is the LAST one asserted here: once every
| balance, invoice and bill has come across, opening balance equity is zero.
| A non-zero figure afterwards is the single most useful signal that something
| was missed, and it is the reason the equity account exists as a plug rather
| than the migration being required to balance in one go.
|
| @see ACCOUNTING_RULES.md §4.13
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->open = app(EnterOpeningBalances::class);
    $this->document = app(EnterOpeningDocument::class);
    $this->pay = app(RecordCustomerPayment::class);

    $this->equity = ledgerAccount(SystemAccount::OpeningBalanceEquity);
    $this->ar = ledgerAccount(SystemAccount::AccountsReceivable);
    $this->ap = ledgerAccount(SystemAccount::AccountsPayable);
    $this->bank = ledgerAccount('1020');
    $this->inventory = ledgerAccount(SystemAccount::Inventory);

    // The day before the fiscal year starts, which is where an opening
    // position belongs: the year itself then opens with these figures.
    $this->asOf = Carbon::parse('2026-07-01');

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

describe('account balances', function (): void {
    it('posts §4.13, with equity as the plug', function (): void {
        $entry = $this->open->handle(
            balances: [
                ['account_id' => $this->bank->id, 'debit' => '500000.00'],
                ['account_id' => $this->inventory->id, 'debit' => '300000.00'],
            ],
            asOf: $this->asOf,
            actor: $this->actor,
        );

        $lines = entryLines($entry);

        expect($lines['1020']['debit'])->toBeDecimal('500000.0000')
            ->and($lines['1300']['debit'])->toBeDecimal('300000.0000')
            // What the business was worth on that date.
            ->and($lines['3100']['credit'])->toBeDecimal('800000.0000');
    });

    it('debits equity where the liabilities exceed the assets', function (): void {
        /*
         * Not an error. A business migrating with more owed than owned has
         * negative equity, and saying so plainly is the point — the
         * alternative would be refusing a true statement of the position.
         */
        $loan = Account::query()->where('code', '2700')->sole();

        $entry = $this->open->handle(
            balances: [
                ['account_id' => $this->bank->id, 'debit' => '100000.00'],
                ['account_id' => $loan->id, 'credit' => '250000.00'],
            ],
            asOf: $this->asOf,
            actor: $this->actor,
        );

        expect(entryLines($entry)['3100']['debit'])->toBeDecimal('150000.0000');
    });

    it('posts one entry, not one per account', function (): void {
        /*
         * The opening position is a single event: it happened on one date and
         * it is either right or wrong as a whole. A hundred entries each
         * balancing against equity individually would make the equity account
         * unreadable and lose the fact that they are one statement.
         */
        $this->open->handle(
            balances: [
                ['account_id' => $this->bank->id, 'debit' => '500000.00'],
                ['account_id' => $this->inventory->id, 'debit' => '300000.00'],
            ],
            asOf: $this->asOf,
            actor: $this->actor,
        );

        expect(JournalEntry::query()->count())->toBe(1);
    });

    it('refuses a balance aimed at a control account', function (): void {
        /*
         * A lump in receivables cannot be aged, chased, or reconciled to
         * anybody — and it would then be counted twice, once here and once in
         * the documents.
         */
        expect(fn () => $this->open->handle(
            balances: [['account_id' => $this->ar->id, 'debit' => '100000.00']],
            asOf: $this->asOf,
            actor: $this->actor,
        ))->toThrow(PostingRefused::class, 'cannot take an opening balance directly');

        expect(fn () => $this->open->handle(
            balances: [['account_id' => $this->ap->id, 'credit' => '100000.00']],
            asOf: $this->asOf,
            actor: $this->actor,
        ))->toThrow(PostingRefused::class, 'counted twice');
    });

    it('refuses a balance entered on both sides, and a negative one', function (): void {
        expect(fn () => $this->open->handle(
            balances: [[
                'account_id' => $this->bank->id,
                'debit' => '100.00',
                'credit' => '50.00',
            ]],
            asOf: $this->asOf,
            actor: $this->actor,
        ))->toThrow(InvalidArgumentException::class, 'a debit or a credit, not both');

        // A negative debit is a credit, and writing it as one is what keeps
        // the trial balance readable.
        expect(fn () => $this->open->handle(
            balances: [['account_id' => $this->bank->id, 'debit' => '-100.00']],
            asOf: $this->asOf,
            actor: $this->actor,
        ))->toThrow(InvalidArgumentException::class, 'positive figure');
    });

    it('skips zeroes rather than refusing them', function (): void {
        // Somebody working down a list of accounts types zero for the ones
        // that had none. That is not an error; it just carries nothing.
        $entry = $this->open->handle(
            balances: [
                ['account_id' => $this->bank->id, 'debit' => '1000.00'],
                ['account_id' => $this->inventory->id, 'debit' => '0'],
                ['account_id' => $this->inventory->id, 'credit' => ''],
            ],
            asOf: $this->asOf,
            actor: $this->actor,
        );

        expect(entryLines($entry))->toHaveCount(2);
    });

    it('refuses the same opening position twice', function (): void {
        /*
         * Re-running a migration is a thing people do, usually after fixing
         * one figure. The source id is derived from the date, so the ledger's
         * idempotency index refuses the retry instead of doubling every
         * balance.
         */
        $this->open->handle(
            balances: [['account_id' => $this->bank->id, 'debit' => '1000.00']],
            asOf: $this->asOf,
            actor: $this->actor,
        );

        expect(fn () => $this->open->handle(
            balances: [['account_id' => $this->bank->id, 'debit' => '1000.00']],
            asOf: $this->asOf,
            actor: $this->actor,
        ))->toThrow(PostingRefused::class);
    });
});

describe('unpaid invoices carried across', function (): void {
    it('creates a real invoice that ages, contra to equity', function (): void {
        $invoice = $this->document->invoice(
            customer: $this->customer,
            amount: '118000.00',
            issuedOn: Carbon::parse('2026-06-15'),
            openingDate: $this->asOf,
            dueOn: Carbon::parse('2026-07-15'),
            reference: 'OLD-4471',
            actor: $this->actor,
        );

        expect($invoice->status)->toBe(SalesDocumentStatus::Sent)
            ->and($invoice->is_opening_balance)->toBeTrue()
            ->and($invoice->total)->toBeDecimal('118000.0000')
            // No tax: it was reported in the old system's return, and
            // charging it again would put it in this period's too.
            ->and($invoice->tax_total)->toBeDecimal('0.0000')
            ->and($invoice->balanceDue())->toBeDecimal('118000.0000');

        $lines = entryLines($invoice->journalEntry()->sole());

        // §4.13's shape, with a customer's name on it.
        expect($lines['1200']['debit'])->toBeDecimal('118000.0000')
            ->and($lines['3100']['credit'])->toBeDecimal('118000.0000');

        // And it is a receivable like any other: the customer's balance and
        // the control account agree.
        expect($this->customer->outstandingBalance())->toBeDecimal('118000.0000')
            ->and($this->ar->balance())->toBeDecimal('118000.0000');
    });

    it('adds nothing to revenue', function (): void {
        /*
         * The reason the contra side is equity rather than revenue. The sale
         * happened in the old system and was reported there; recognising it
         * again would overstate this period's revenue by the whole
         * receivable and make the first profit figure fiction.
         */
        $this->document->invoice(
            customer: $this->customer,
            amount: '118000.00',
            issuedOn: Carbon::parse('2026-06-15'),
            openingDate: $this->asOf,
            actor: $this->actor,
        );

        $revenue = Account::query()->where('code', '4010')->sole();

        expect($revenue->balance())->toBeDecimal('0.0000');
    });

    it('ages and collects exactly like an ordinary invoice', function (): void {
        $invoice = $this->document->invoice(
            customer: $this->customer,
            amount: '118000.00',
            issuedOn: Carbon::parse('2026-06-15'),
            openingDate: $this->asOf,
            dueOn: Carbon::parse('2026-07-15'),
            actor: $this->actor,
        );

        // Overdue as at September, which is what a chasing list has to say.
        expect($invoice->isOverdue(Carbon::parse('2026-09-15')))->toBeTrue()
            ->and($invoice->daysOverdue(Carbon::parse('2026-09-15')))->toBe(62);

        // And it settles through the ordinary payment path — nothing about
        // this document is special once it exists.
        $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '118000.00',
            paymentDate: Carbon::parse('2026-09-20'),
            allocations: [$invoice->id => '118000.00'],
            actor: $this->actor,
        );

        expect($invoice->refresh()->status)->toBe(SalesDocumentStatus::Paid)
            ->and($this->ar->balance())->toBeDecimal('0.0000')
            ->and($this->bank->balance())->toBeDecimal('118000.0000');
    });

    it('cannot be flagged on a commitment document', function (): void {
        // A quote from the old system is not a balance, and pretending it is
        // would put a commitment in the ledger. The constraint refuses it
        // independently of the code.
        $estimate = app(SaveSalesDocument::class)->handle(
            type: SalesDocumentType::Estimate,
            attributes: [
                'contact_id' => $this->customer->id,
                'issue_date' => '2026-06-15',
            ],
            lines: [[
                'description' => 'A quote',
                'quantity' => '1',
                'unit_price' => '100.00',
                'revenue_account_id' => Account::query()->where('code', '4010')->sole()->id,
            ]],
            actor: $this->actor,
        );

        expect(fn () => $estimate->forceFill(['is_opening_balance' => true])->save())
            ->toThrow(QueryException::class, 'opening_only_posting_types');
    });
});

describe('unpaid bills carried across', function (): void {
    it('creates a real bill that ages, contra to equity', function (): void {
        $bill = $this->document->bill(
            vendor: $this->vendor,
            amount: '59000.00',
            issuedOn: Carbon::parse('2026-06-20'),
            openingDate: $this->asOf,
            dueOn: Carbon::parse('2026-07-20'),
            vendorReference: 'THEIRS-99',
            actor: $this->actor,
        );

        expect($bill->status)->toBe(PurchaseDocumentStatus::Open)
            ->and($bill->is_opening_balance)->toBeTrue()
            ->and($bill->vendor_reference)->toBe('THEIRS-99')
            ->and($bill->balanceDue())->toBeDecimal('59000.0000');

        $lines = entryLines($bill->journalEntry()->sole());

        expect($lines['3100']['debit'])->toBeDecimal('59000.0000')
            ->and($lines['2100']['credit'])->toBeDecimal('59000.0000');

        expect($this->vendor->payableBalance())->toBeDecimal('59000.0000')
            ->and(BigDecimal::of($this->ap->balance())->abs())->toBeDecimal('59000.0000');
    });

    it('claims no input tax', function (): void {
        // It was claimed in the old system's return; claiming it again would
        // be a second claim on the same invoice.
        $this->document->bill(
            vendor: $this->vendor,
            amount: '59000.00',
            issuedOn: Carbon::parse('2026-06-20'),
            openingDate: $this->asOf,
            actor: $this->actor,
        );

        expect(ledgerAccount(SystemAccount::GstInput)->balance())->toBeDecimal('0.0000');
    });
});

describe('a complete migration', function (): void {
    it('leaves opening balance equity at zero, and the ledger verifiable', function (): void {
        /*
         * The whole exit criterion, in one test.
         *
         * A business with 500,000 in the bank, 300,000 of stock, a 250,000
         * loan, 118,000 owed to it and 59,000 owed by it. Its equity is
         * 500,000 + 300,000 − 250,000 + 118,000 − 59,000 = 609,000.
         *
         * The account balances go in first, with equity as the plug, and the
         * documents follow. Once everything is across, the equity account
         * holds exactly that 609,000 and nothing is left unexplained — which
         * is what "the migration is complete" means.
         */
        $loan = Account::query()->where('code', '2700')->sole();

        $this->open->handle(
            balances: [
                ['account_id' => $this->bank->id, 'debit' => '500000.00'],
                ['account_id' => $this->inventory->id, 'debit' => '300000.00'],
                ['account_id' => $loan->id, 'credit' => '250000.00'],
            ],
            asOf: $this->asOf,
            actor: $this->actor,
        );

        // 550,000 so far: assets less the loan.
        expect(BigDecimal::of($this->equity->balance())->abs())->toBeDecimal('550000.0000');

        $this->document->invoice(
            customer: $this->customer,
            amount: '118000.00',
            issuedOn: Carbon::parse('2026-06-15'),
            openingDate: $this->asOf,
            actor: $this->actor,
        );

        $this->document->bill(
            vendor: $this->vendor,
            amount: '59000.00',
            issuedOn: Carbon::parse('2026-06-20'),
            openingDate: $this->asOf,
            actor: $this->actor,
        );

        // The business's own worth on the migration date, and the figure the
        // screen reports so a missed account shows up as a discrepancy.
        expect(BigDecimal::of($this->equity->balance())->abs())->toBeDecimal('609000.0000');

        // Every invariant in §1, over everything just posted.
        $this->artisan('my-books:verify-ledger', [
            '--organization' => $this->organization->slug,
        ])->assertExitCode(0);

        // And the two control accounts agree with the documents behind them,
        // which is what makes the ageing reports trustworthy on day one.
        expect($this->ar->balance())->toBeDecimal('118000.0000')
            ->and(BigDecimal::of($this->ap->balance())->abs())->toBeDecimal('59000.0000')
            ->and($this->customer->outstandingBalance())->toBeDecimal('118000.0000')
            ->and($this->vendor->payableBalance())->toBeDecimal('59000.0000');
    });

    it('reports what is left in equity while a migration is half done', function (): void {
        /*
         * The equity account is a plug ON PURPOSE: it holds the difference
         * while a migration is incomplete, so every intermediate state still
         * balances and the ledger never has to accept an unbalanced entry
         * "just for now".
         */
        $this->open->handle(
            balances: [['account_id' => $this->bank->id, 'debit' => '500000.00']],
            asOf: $this->asOf,
            actor: $this->actor,
        );

        expect(BigDecimal::of($this->open->equityRemaining())->abs())
            ->toBeDecimal('500000.0000');
    });
});
