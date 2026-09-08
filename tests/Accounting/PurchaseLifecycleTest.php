<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\RecordExchangeRate;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Purchases\Actions\ApprovePurchaseDocument;
use App\Domain\Purchases\Actions\ConvertPurchaseDocument;
use App\Domain\Purchases\Actions\RecordVendorPayment;
use App\Domain\Purchases\Actions\SavePurchaseDocument;
use App\Domain\Purchases\Actions\VoidPurchaseDocument;
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Exceptions\PurchaseDocumentRefused;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Tax\Models\Tax;
use App\Domain\Tax\Models\TaxComponent;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| The purchase lifecycle, end to end
|---------------------------------------------------------------------------
|
| Phase 4's exit criterion, asserted directly: purchase order → bill →
| payment posts correctly at every step, payables age accurately, and
| withholding is recorded as a liability that reconciles. Against a real
| database, through the real Actions, with the resulting journal LINES
| checked rather than an HTTP status.
|
| The pure tests already prove the posting rules. What these add is what the
| pure tests cannot see: numbering, idempotency, tax components resolving by
| date, claimability flowing from the line through to the entry, a bill's
| status moving with its balance, and — most of all — that the vendor's
| balance and the ledger agree after every step.
|
| @see ACCOUNTING_RULES.md §4.6, §4.7, §6, §10
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->save = app(SavePurchaseDocument::class);
    $this->approve = app(ApprovePurchaseDocument::class);
    $this->void = app(VoidPurchaseDocument::class);
    $this->convert = app(ConvertPurchaseDocument::class);
    $this->pay = app(RecordVendorPayment::class);

    $this->ap = ledgerAccount(SystemAccount::AccountsPayable);
    $this->gstInput = ledgerAccount(SystemAccount::GstInput);
    $this->whtPayable = ledgerAccount(SystemAccount::WithholdingTaxPayable);
    $this->vendorAdvances = ledgerAccount(SystemAccount::VendorAdvances);
    $this->inventory = ledgerAccount(SystemAccount::Inventory);
    $this->fx = ledgerAccount(SystemAccount::FxGainLoss);
    $this->bank = ledgerAccount('1020');
    $this->supplies = Account::query()->where('code', '6300')->sole();
    $this->fees = Account::query()->where('code', '6400')->sole();

    $this->gst = Tax::query()->where('code', 'GST18')->sole();

    $this->vendor = Contact::query()->create([
        'kind' => ContactKind::Vendor,
        'display_name' => 'Sindh Yarn Traders',
        'email' => 'billing@sindhyarn.test',
        'payment_terms_days' => 30,
        'is_tax_filer' => true,
    ]);

    $this->inYear = Carbon::parse('2026-09-15');

    /**
     * A one-line document, priced so §4.6's worked example falls out:
     * 50,000 of services at 18% GST.
     */
    $this->draft = fn (
        PurchaseDocumentType $type = PurchaseDocumentType::Bill,
        string $price = '50000.00',
        bool $claimable = true,
        ?string $accountId = null,
        ?string $vendorRef = 'ACME-1001',
        ?Carbon $on = null,
    ): PurchaseDocument => $this->save->handle(
        type: $type,
        attributes: [
            'contact_id' => $this->vendor->id,
            'issue_date' => ($on ?? $this->inYear)->toDateString(),
            'vendor_reference' => $vendorRef,
        ],
        lines: [
            [
                'description' => 'Spinning subcontract, September',
                'quantity' => '1',
                'unit_price' => $price,
                'tax_id' => $this->gst->id,
                'debit_account_id' => $accountId ?? $this->fees->id,
                'tax_is_claimable' => $claimable,
            ],
        ],
        actor: $this->actor,
    );
});

describe('a draft bill', function (): void {
    it('computes and stores every total, including what is recoverable', function (): void {
        $bill = ($this->draft)();

        expect($bill->number)->toBe('BILL-000001')
            ->and($bill->status)->toBe(PurchaseDocumentStatus::Draft)
            ->and($bill->subtotal)->toBeDecimal('50000.0000')
            ->and($bill->tax_total)->toBeDecimal('9000.0000')
            ->and($bill->tax_claimable_total)->toBeDecimal('9000.0000')
            ->and($bill->total)->toBeDecimal('59000.0000')
            // Nothing has posted: a draft has no accounting effect at all.
            ->and($bill->journal_entry_id)->toBeNull()
            ->and($bill->approved_at)->toBeNull();
    });

    it('states nothing recoverable when the line’s tax is blocked', function (): void {
        $bill = ($this->draft)(claimable: false);

        expect($bill->tax_total)->toBeDecimal('9000.0000')
            // Charged, but not recoverable.
            ->and($bill->tax_claimable_total)->toBeDecimal('0.0000')
            ->and($bill->taxCapitalised())->toBeDecimal('9000.0000')
            // The line carries the tax in its cost.
            ->and($bill->lines()->sole()->capitalisedCost())->toBeDecimal('59000.0000');
    });

    it('resolves the tax rate as at the bill’s date, not today', function (): void {
        /*
         * A component effective from 1 October at 20%. A bill dated 15
         * September must still be taxed at 18% — the rate in force when the
         * supply happened.
         */
        $raised = new TaxComponent;

        $raised->forceFill([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->id,
            'tax_id' => $this->gst->id,
            'name' => 'GST (raised)',
            'sequence' => 1,
            'rate' => '0.200000',
            'effective_from' => '2026-10-01',
            'output_account_id' => ledgerAccount(SystemAccount::GstOutput)->id,
            'input_account_id' => $this->gstInput->id,
        ])->save();

        expect(($this->draft)()->tax_total)->toBeDecimal('9000.0000');

        $later = $this->save->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => '2026-10-15',
                'vendor_reference' => 'ACME-1002',
            ],
            lines: [[
                'description' => 'October subcontract',
                'quantity' => '1',
                'unit_price' => '50000.00',
                'tax_id' => $this->gst->id,
                'debit_account_id' => $this->fees->id,
            ]],
            actor: $this->actor,
        );

        expect($later->tax_total)->toBeDecimal('10000.0000');
    });

    it('refuses a second bill carrying the same vendor reference', function (): void {
        // Paying the same bill twice is the most expensive routine mistake in
        // accounts payable, and the vendor's own number is the only reliable
        // way to notice it.
        ($this->draft)();

        expect(fn () => ($this->draft)())
            ->toThrow(PurchaseDocumentRefused::class, 'BILL-000001');
    });

    it('allows the same reference again once the first bill is void', function (): void {
        /*
         * Voiding a bill usually means it is being replaced — by a corrected
         * copy of itself, carrying the vendor's same number. Refusing that
         * would make the guard unusable in the one situation it most often
         * comes up.
         */
        $first = ($this->draft)();
        $this->approve->handle($first, $this->actor);
        $this->void->handle($first, $this->actor, 'Wrong account');

        $replacement = ($this->draft)();

        expect($replacement->number)->toBe('BILL-000002')
            ->and($replacement->vendor_reference)->toBe('ACME-1001');
    });

    it('refuses a contact who is not a vendor', function (): void {
        $customer = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Karachi Textiles',
        ]);

        expect(fn () => $this->save->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $customer->id,
                'issue_date' => $this->inYear->toDateString(),
            ],
            lines: [[
                'description' => 'Something',
                'quantity' => '1',
                'unit_price' => '100.00',
                'debit_account_id' => $this->supplies->id,
            ]],
            actor: $this->actor,
        ))->toThrow(PurchaseDocumentRefused::class, 'not marked as a vendor');
    });

    it('is deleted outright, because nothing has posted', function (): void {
        $bill = ($this->draft)();
        $bill->delete();

        expect(PurchaseDocument::query()->count())->toBe(0);
    });
});

describe('approving a bill', function (): void {
    it('posts §4.6 exactly, and settles the vendor’s balance against the ledger', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        expect($bill->status)->toBe(PurchaseDocumentStatus::Open)
            ->and($bill->approved_at)->not->toBeNull()
            ->and($bill->journal_entry_id)->not->toBeNull();

        $lines = entryLines($bill->journalEntry()->sole());

        expect($lines['6400']['debit'])->toBeDecimal('50000.0000')
            ->and($lines['1400']['debit'])->toBeDecimal('9000.0000')
            ->and($lines['2100']['credit'])->toBeDecimal('59000.0000');

        // The vendor's balance and the control account agree, which is the
        // property the whole module exists to keep.
        expect($this->vendor->payableBalance())->toBeDecimal('59000.0000')
            ->and(BigDecimal::of($this->ap->balance())->abs())->toBeDecimal('59000.0000');
    });

    it('capitalises blocked input tax into the cost', function (): void {
        /*
         * §4.6's other half, asserted against the ledger rather than in the
         * abstract. The whole 59,000 lands in the expense; nothing reaches
         * GST Input Receivable.
         */
        $bill = $this->approve->handle(($this->draft)(claimable: false), $this->actor);

        $lines = entryLines($bill->journalEntry()->sole());

        expect($lines['6400']['debit'])->toBeDecimal('59000.0000')
            ->and($lines['2100']['credit'])->toBeDecimal('59000.0000')
            ->and($lines)->not->toHaveKey('1400');

        // And nothing recoverable was claimed anywhere.
        expect($this->gstInput->balance())->toBeDecimal('0.0000');
    });

    it('splits a bill with one claimable line and one blocked one', function (): void {
        $bill = $this->save->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => $this->inYear->toDateString(),
            ],
            lines: [
                [
                    'description' => 'Raw cotton',
                    'quantity' => '1',
                    'unit_price' => '100000.00',
                    'tax_id' => $this->gst->id,
                    'debit_account_id' => $this->inventory->id,
                    'tax_is_claimable' => true,
                ],
                [
                    'description' => 'Client entertainment',
                    'quantity' => '1',
                    'unit_price' => '10000.00',
                    'tax_id' => $this->gst->id,
                    'debit_account_id' => $this->supplies->id,
                    'tax_is_claimable' => false,
                ],
            ],
            actor: $this->actor,
        );

        expect($bill->tax_total)->toBeDecimal('19800.0000')
            ->and($bill->tax_claimable_total)->toBeDecimal('18000.0000');

        $lines = entryLines($this->approve->handle($bill, $this->actor)->journalEntry()->sole());

        expect($lines['1300']['debit'])->toBeDecimal('100000.0000')
            // 10,000 plus the 1,800 that cannot be reclaimed.
            ->and($lines['6300']['debit'])->toBeDecimal('11800.0000')
            ->and($lines['1400']['debit'])->toBeDecimal('18000.0000')
            ->and($lines['2100']['credit'])->toBeDecimal('129800.0000');
    });

    it('refuses to approve twice', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        expect(fn () => $this->approve->handle($bill, $this->actor))
            ->toThrow(PurchaseDocumentRefused::class, 'already been approved');
    });

    it('refuses a bill with no lines', function (): void {
        $bill = $this->save->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => $this->inYear->toDateString(),
            ],
            lines: [],
            actor: $this->actor,
        );

        expect(fn () => $this->approve->handle($bill, $this->actor))
            ->toThrow(PurchaseDocumentRefused::class, 'no lines');
    });

    it('refuses to edit an approved bill', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        expect(fn () => $this->save->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => $this->inYear->toDateString(),
            ],
            lines: [[
                'description' => 'Changed my mind',
                'quantity' => '1',
                'unit_price' => '1.00',
                'debit_account_id' => $this->supplies->id,
            ]],
            document: $bill,
            actor: $this->actor,
        ))->toThrow(PurchaseDocumentRefused::class, 'can no longer be edited');
    });
});

describe('a purchase order', function (): void {
    it('never posts, whatever is done to it', function (): void {
        $order = $this->approve->handle(
            ($this->draft)(type: PurchaseDocumentType::PurchaseOrder, vendorRef: null),
            $this->actor,
        );

        expect($order->number)->toBe('PO-000001')
            ->and($order->status)->toBe(PurchaseDocumentStatus::Sent)
            ->and($order->journal_entry_id)->toBeNull()
            // A commitment has no approver, because nothing was approved.
            ->and($order->approved_at)->toBeNull();
    });

    it('cannot acquire a journal entry even by direct assignment', function (): void {
        // §6 in the database, independent of the enum: the constraint refuses
        // it however the code is later refactored.
        $order = ($this->draft)(type: PurchaseDocumentType::PurchaseOrder, vendorRef: null);
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        expect(fn () => $order->forceFill([
            'journal_entry_id' => $bill->journal_entry_id,
        ])->save())->toThrow(QueryException::class, 'commitments_never_post');
    });

    it('converts to a draft bill, priced as ordered', function (): void {
        $order = $this->approve->handle(
            ($this->draft)(type: PurchaseDocumentType::PurchaseOrder, vendorRef: null),
            $this->actor,
        );

        $bill = $this->convert->handle(
            source: $order,
            to: PurchaseDocumentType::Bill,
            actor: $this->actor,
            issueDate: $this->inYear,
        );

        expect($bill->number)->toBe('BILL-000001')
            // A DRAFT. Converting must not post whatever the order said —
            // checking the bill against the order is the whole point of the
            // step.
            ->and($bill->status)->toBe(PurchaseDocumentStatus::Draft)
            ->and($bill->journal_entry_id)->toBeNull()
            ->and($bill->total)->toBeDecimal('59000.0000')
            ->and($bill->converted_from_id)->toBe($order->id)
            // The vendor's own number does not travel: the order has ours,
            // the bill will have theirs.
            ->and($bill->vendor_reference)->toBeNull();

        // The order is spoken for, which is what stops it being billed twice.
        expect($order->refresh()->status)->toBe(PurchaseDocumentStatus::Closed);
    });

    it('refuses to convert a bill back to an order', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        expect(fn () => $this->convert->handle(
            source: $bill,
            to: PurchaseDocumentType::PurchaseOrder,
            actor: $this->actor,
        ))->toThrow(InvalidArgumentException::class, 'cannot be converted');
    });
});

describe('paying a vendor', function (): void {
    it('posts §4.7 exactly, settling the bill in full while holding the tax', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        /*
         * §4.7's worked example, adapted to this bill: we settle 59,000 and
         * withhold 10% of it, remitting the rest.
         */
        $payment = $this->pay->handle(
            contact: $this->vendor,
            bankAccountId: $this->bank->id,
            amount: '59000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$bill->id => '59000.00'],
            withholdingAmount: '5900.00',
            actor: $this->actor,
        );

        expect($payment->number)->toBe('PAY-000001')
            ->and($payment->amount)->toBeDecimal('59000.0000')
            ->and($payment->withholding_amount)->toBeDecimal('5900.0000')
            // What actually left the bank.
            ->and($payment->amount_received)->toBeDecimal('53100.0000');

        $lines = entryLines($payment->journalEntry()->sole());

        expect($lines['2100']['debit'])->toBeDecimal('59000.0000')
            ->and($lines['1020']['credit'])->toBeDecimal('53100.0000')
            ->and($lines['2350']['credit'])->toBeDecimal('5900.0000');

        // The bill is SETTLED, not left 5,900 short — the vendor considers
        // it paid, and so do we.
        expect($bill->refresh()->status)->toBe(PurchaseDocumentStatus::Paid)
            ->and($bill->balanceDue())->toBeDecimal('0.0000')
            ->and($this->vendor->payableBalance())->toBeDecimal('0.0000')
            ->and($this->ap->balance())->toBeDecimal('0.0000');

        // And the withheld tax is a liability we now owe the authority.
        expect(BigDecimal::of($this->whtPayable->balance())->abs())
            ->toBeDecimal('5900.0000');
    });

    it('marks a bill partially paid and leaves the rest outstanding', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        $this->pay->handle(
            contact: $this->vendor,
            bankAccountId: $this->bank->id,
            amount: '20000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$bill->id => '20000.00'],
            actor: $this->actor,
        );

        expect($bill->refresh()->status)->toBe(PurchaseDocumentStatus::PartiallyPaid)
            ->and($bill->balanceDue())->toBeDecimal('39000.0000')
            ->and(BigDecimal::of($this->ap->balance())->abs())->toBeDecimal('39000.0000');
    });

    it('holds an unallocated payment as an advance with the vendor', function (): void {
        $payment = $this->pay->handle(
            contact: $this->vendor,
            bankAccountId: $this->bank->id,
            amount: '25000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            actor: $this->actor,
        );

        $lines = entryLines($payment->journalEntry()->sole());

        // An asset — they owe us goods or the money back — not a negative
        // payable.
        expect($lines['1250']['debit'])->toBeDecimal('25000.0000')
            ->and($lines['1020']['credit'])->toBeDecimal('25000.0000')
            ->and($this->vendorAdvances->balance())->toBeDecimal('25000.0000')
            ->and($this->ap->balance())->toBeDecimal('0.0000');
    });

    it('refuses to allocate more than the bill still owes', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        expect(fn () => $this->pay->handle(
            contact: $this->vendor,
            bankAccountId: $this->bank->id,
            amount: '70000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$bill->id => '70000.00'],
            actor: $this->actor,
        ))->toThrow(PurchaseDocumentRefused::class, 'only 59000.0000 outstanding');
    });

    it('refuses to allocate more than was paid', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        expect(fn () => $this->pay->handle(
            contact: $this->vendor,
            bankAccountId: $this->bank->id,
            amount: '1000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$bill->id => '5000.00'],
            actor: $this->actor,
        ))->toThrow(PurchaseDocumentRefused::class, 'left to allocate');
    });

    it('refuses to settle another vendor’s bill', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        $other = Contact::query()->create([
            'kind' => ContactKind::Vendor,
            'display_name' => 'Someone Else',
        ]);

        expect(fn () => $this->pay->handle(
            contact: $other,
            bankAccountId: $this->bank->id,
            amount: '59000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$bill->id => '59000.00'],
            actor: $this->actor,
        ))->toThrow(InvalidArgumentException::class, 'different vendor');
    });

    it('records realised FX when the rupee cost of a dollar bill has fallen', function (): void {
        /*
         * A 1,000 dollar bill booked at 280 and paid when the dollar is 275.
         * We are relieved of a 280,000 liability for 275,000: a 5,000 gain,
         * and it is a fact about this settlement rather than about when the
         * report was run.
         */
        app(RecordExchangeRate::class)->handle(
            from: 'USD',
            to: 'PKR',
            rate: '280',
            effectiveOn: $this->inYear,
        );

        $bill = $this->save->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => $this->inYear->toDateString(),
                'currency' => 'USD',
                'exchange_rate' => '280',
                'vendor_reference' => 'USD-77',
            ],
            lines: [[
                'description' => 'Imported dyestuff',
                'quantity' => '1',
                'unit_price' => '1000.00',
                'debit_account_id' => $this->inventory->id,
            ]],
            actor: $this->actor,
        );

        $this->approve->handle($bill, $this->actor);

        $payment = $this->pay->handle(
            contact: $this->vendor,
            bankAccountId: $this->bank->id,
            amount: '1000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$bill->id => '1000.00'],
            currency: 'USD',
            exchangeRate: '275',
            actor: $this->actor,
        );

        $entry = $payment->journalEntry()->sole();
        $lines = entryLines($entry);

        expect($lines['2100']['debit'])->toBeDecimal('280000.0000')
            ->and($lines['1020']['credit'])->toBeDecimal('275000.0000')
            ->and($lines['7500']['credit'])->toBeDecimal('5000.0000')
            // The entry is stated in rupees: the gain exists only in base
            // currency, so an entry in dollars could not carry it.
            ->and($entry->currency)->toBe('PKR');

        // The payable is fully cleared despite the two rates.
        expect($bill->refresh()->balanceDue())->toBeDecimal('0.0000')
            ->and($this->ap->balance())->toBeDecimal('0.0000');

        // And the gain is on the allocation, where a payment settling two
        // bills at two rates would keep them apart.
        expect($payment->purchaseAllocations()->sole()->fx_gain_loss_base)
            ->toBeDecimal('5000.0000');
    });

    it('refuses to settle a dollar bill with a rupee payment', function (): void {
        $bill = $this->save->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => $this->inYear->toDateString(),
                'currency' => 'USD',
                'exchange_rate' => '280',
                'vendor_reference' => 'USD-78',
            ],
            lines: [[
                'description' => 'Imported dyestuff',
                'quantity' => '1',
                'unit_price' => '1000.00',
                'debit_account_id' => $this->inventory->id,
            ]],
            actor: $this->actor,
        );

        $this->approve->handle($bill, $this->actor);

        // A real scenario, but it needs a rate for THAT pair to mean
        // anything — guessing one would make the amount cleared ambiguous.
        expect(fn () => $this->pay->handle(
            contact: $this->vendor,
            bankAccountId: $this->bank->id,
            amount: '280000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$bill->id => '280000.00'],
            actor: $this->actor,
        ))->toThrow(PurchaseDocumentRefused::class, 'is in USD');
    });
});

describe('a vendor credit', function (): void {
    it('reduces the bill and the payable without touching what was paid', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        $credit = $this->save->handle(
            type: PurchaseDocumentType::VendorCredit,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => Carbon::parse('2026-09-20')->toDateString(),
            ],
            lines: [[
                'description' => 'Short delivery credit',
                'quantity' => '1',
                'unit_price' => '10000.00',
                'tax_id' => $this->gst->id,
                'debit_account_id' => $this->fees->id,
            ]],
            actor: $this->actor,
        );

        $credit->forceFill(['credits_document_id' => $bill->id])->save();

        $credit = $this->approve->handle($credit, $this->actor);

        $lines = entryLines($credit->journalEntry()->sole());

        expect($lines['2100']['debit'])->toBeDecimal('11800.0000')
            // Back to the account the bill debited, so the period's cost is
            // what the period actually cost.
            ->and($lines['6400']['credit'])->toBeDecimal('10000.0000')
            ->and($lines['1400']['credit'])->toBeDecimal('1800.0000');

        $bill->refresh();

        expect($bill->amount_credited)->toBeDecimal('11800.0000')
            // Credited, not paid: "cash out this month" must stay right.
            ->and($bill->amount_paid)->toBeDecimal('0.0000')
            ->and($bill->balanceDue())->toBeDecimal('47200.0000')
            ->and(BigDecimal::of($this->ap->balance())->abs())->toBeDecimal('47200.0000');
    });
});

describe('voiding', function (): void {
    it('reverses the entry and keeps both on the record', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);
        $originalEntry = $bill->journal_entry_id;

        $bill = $this->void->handle($bill, $this->actor, 'Duplicate of BILL-000004');

        expect($bill->status)->toBe(PurchaseDocumentStatus::Void)
            // Both survive: the pair reads as approved, then withdrawn.
            ->and($bill->journal_entry_id)->toBe($originalEntry)
            ->and($bill->void_journal_entry_id)->not->toBeNull();

        // And the balances are back where they started.
        expect($this->ap->balance())->toBeDecimal('0.0000')
            ->and($this->fees->balance())->toBeDecimal('0.0000')
            ->and($this->gstInput->balance())->toBeDecimal('0.0000')
            ->and($this->vendor->payableBalance())->toBeDecimal('0.0000');
    });

    it('refuses to void a bill that has been paid', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        $this->pay->handle(
            contact: $this->vendor,
            bankAccountId: $this->bank->id,
            amount: '10000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$bill->id => '10000.00'],
            actor: $this->actor,
        );

        // The money would stay allocated to something that no longer exists.
        expect(fn () => $this->void->handle($bill->refresh(), $this->actor))
            ->toThrow(PurchaseDocumentRefused::class, 'paid against it');
    });

    it('refuses to delete an approved bill', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        expect(fn () => $bill->delete())
            ->toThrow(RuntimeException::class, 'Void it instead');
    });

    it('gives back the credit when a vendor credit is voided', function (): void {
        $bill = $this->approve->handle(($this->draft)(), $this->actor);

        $credit = $this->save->handle(
            type: PurchaseDocumentType::VendorCredit,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => Carbon::parse('2026-09-20')->toDateString(),
            ],
            lines: [[
                'description' => 'Raised in error',
                'quantity' => '1',
                'unit_price' => '10000.00',
                'debit_account_id' => $this->fees->id,
            ]],
            actor: $this->actor,
        );

        $credit->forceFill(['credits_document_id' => $bill->id])->save();
        $credit = $this->approve->handle($credit, $this->actor);

        expect($bill->refresh()->amount_credited)->toBeDecimal('10000.0000');

        $this->void->handle($credit, $this->actor, 'Raised in error');

        // Otherwise the bill would look part-settled by a document that has
        // been withdrawn, while the ledger — correctly — shows it fully owed.
        expect($bill->refresh()->amount_credited)->toBeDecimal('0.0000')
            ->and($bill->status)->toBe(PurchaseDocumentStatus::Open)
            ->and($bill->balanceDue())->toBeDecimal('59000.0000');
    });
});

describe('the whole chain', function (): void {
    it('leaves the ledger verifiable after order → bill → payment', function (): void {
        $order = $this->approve->handle(
            ($this->draft)(type: PurchaseDocumentType::PurchaseOrder, vendorRef: null),
            $this->actor,
        );

        $bill = $this->convert->handle(
            source: $order,
            to: PurchaseDocumentType::Bill,
            actor: $this->actor,
            issueDate: $this->inYear,
        );

        $bill->forceFill(['vendor_reference' => 'ACME-2001'])->save();

        $this->approve->handle($bill, $this->actor);

        $this->pay->handle(
            contact: $this->vendor,
            bankAccountId: $this->bank->id,
            amount: '59000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$bill->id => '59000.00'],
            withholdingAmount: '5900.00',
            actor: $this->actor,
        );

        // Phase 4's exit criterion, stated as the ledger's own verifier sees
        // it — every invariant in §1, over everything just posted.
        $this->artisan('my-books:verify-ledger', [
            '--organization' => $this->organization->slug,
        ])->assertExitCode(0);

        expect($this->vendor->payableBalance())->toBeDecimal('0.0000')
            ->and($this->ap->balance())->toBeDecimal('0.0000')
            ->and($this->fees->balance())->toBeDecimal('50000.0000')
            ->and($this->gstInput->balance())->toBeDecimal('9000.0000')
            ->and(BigDecimal::of($this->whtPayable->balance())->abs())->toBeDecimal('5900.0000')
            ->and(BigDecimal::of($this->bank->balance())->abs())->toBeDecimal('53100.0000');
    });
});
