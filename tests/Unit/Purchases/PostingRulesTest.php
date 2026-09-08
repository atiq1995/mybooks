<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Purchases\Data\BillPosting;
use App\Domain\Purchases\Data\VendorCreditPosting;
use App\Domain\Purchases\Data\VendorPaymentPosting;
use Illuminate\Support\Carbon;

/*
|---------------------------------------------------------------------------
| The purchase posting rules
|---------------------------------------------------------------------------
|
| ACCOUNTING_RULES.md §10 requires that any change to posting logic ships with
| tests asserting the resulting journal LINES — account, debit, credit — not
| merely that a request returned 200. These are those tests, and they are
| pure: the posting rules are functions of figures, so no database is
| involved.
|
| §4.6's and §4.7's worked examples are asserted to the cent, and then the
| places where a plausible alternative would ALSO balance — but would state
| something false — are asserted separately. An entry that balances is not the
| same as an entry that is right, and on the purchase side the two most
| expensive ways to be wrong both balance perfectly: capitalising tax that
| could have been reclaimed, and reclaiming tax that could not.
|
| Constants are prefixed. A top-level `const` in a Pest file is global to the
| PHP process, so two test files declaring AR pass alone and fail whenever the
| parallel runner puts them in one worker.
|
| @see ACCOUNTING_RULES.md §4.6, §4.7, §4.11, §10
*/

const PUR_AP = 'account-ap';
const PUR_EXPENSE = 'account-office-supplies';
const PUR_SERVICES = 'account-professional-fees';
const PUR_INVENTORY = 'account-inventory';
const PUR_GST_INPUT = 'account-gst-input';
const PUR_PST_INPUT = 'account-pst-input';
const PUR_BANK = 'account-bank';
const PUR_WHT_PAYABLE = 'account-wht-payable';
const PUR_ADVANCES = 'account-vendor-advances';
const PUR_FX = 'account-fx';

/**
 * The journal lines, keyed by account, as [debit, credit] decimal strings.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function purchaseLinesByAccount(JournalDraft $draft): array
{
    $lines = [];

    foreach ($draft->lines as $line) {
        $lines[$line->accountId] = [
            (string) $line->debitValue(),
            (string) $line->creditValue(),
        ];
    }

    return $lines;
}

function billPosting(array $costLines, array $claimableTaxes = [], ?string $vendorRef = null): JournalDraft
{
    return (new BillPosting(
        payableAccountId: PUR_AP,
        costLines: $costLines,
        claimableTaxes: $claimableTaxes,
        currency: 'PKR',
        baseCurrency: 'PKR',
        exchangeRate: '1',
        date: Carbon::parse('2026-09-15'),
        documentId: '01926f00-0000-7000-8000-00000000bill',
        documentNumber: 'BILL-000001',
        sourceType: 'bill',
        contactId: 'contact-vendor',
        vendorReference: $vendorRef,
    ))->toDraft();
}

describe('§4.6 — a vendor bill', function (): void {
    it('debits the expense and the input tax, and credits the whole payable', function (): void {
        /*
         * §4.6's shape, with the section's own arithmetic: 50,000 of services
         * at 18% GST, all of it reclaimable.
         */
        $lines = purchaseLinesByAccount(billPosting(
            costLines: [['account_id' => PUR_SERVICES, 'amount' => '50000.0000']],
            claimableTaxes: [['account_id' => PUR_GST_INPUT, 'amount' => '9000.0000']],
        ));

        expect($lines[PUR_SERVICES][0])->toBeDecimal('50000.0000')
            ->and($lines[PUR_GST_INPUT][0])->toBeDecimal('9000.0000')
            // The gross the vendor is owed — including the tax, which they
            // charged us and will remit themselves.
            ->and($lines[PUR_AP][1])->toBeDecimal('59000.0000');
    });

    it('balances', function (): void {
        $draft = billPosting(
            costLines: [
                ['account_id' => PUR_SERVICES, 'amount' => '50000.0000'],
                ['account_id' => PUR_EXPENSE, 'amount' => '1234.5600'],
            ],
            claimableTaxes: [
                ['account_id' => PUR_GST_INPUT, 'amount' => '9222.2208'],
                ['account_id' => PUR_PST_INPUT, 'amount' => '100.0000'],
            ],
        );

        expect((string) $draft->totalDebit())->toBeDecimal((string) $draft->totalCredit());
    });

    it('capitalises input tax that cannot be reclaimed', function (): void {
        /*
         * The single most consequential line in the purchases module.
         *
         * The caller has already added the blocked tax into the cost — that
         * is what `capitalisedCost()` does — and passes NO claimable tax. So
         * 50,000 of entertainment at 18% arrives as one 59,000 debit.
         *
         * The alternative balances just as well: debit the expense 50,000 and
         * GST Input 9,000. It would also put 9,000 of unrecoverable tax on
         * the balance sheet as an asset, understate the cost of the
         * entertainment by the same amount, and overstate profit until
         * somebody wrote the receivable off — which nobody would, because
         * nothing would ever flag it.
         */
        $lines = purchaseLinesByAccount(billPosting(
            costLines: [['account_id' => PUR_EXPENSE, 'amount' => '59000.0000']],
            claimableTaxes: [],
        ));

        expect($lines[PUR_EXPENSE][0])->toBeDecimal('59000.0000')
            ->and($lines[PUR_AP][1])->toBeDecimal('59000.0000')
            // No receivable at all: there is nothing to reclaim.
            ->and($lines)->not->toHaveKey(PUR_GST_INPUT);
    });

    it('handles a bill with one claimable line and one blocked one', function (): void {
        /*
         * A real bill: 100,000 of raw material whose tax we can reclaim, and
         * 10,000 of staff entertainment whose tax we cannot. The blocked
         * 1,800 rides in the expense; the claimable 18,000 goes to the
         * receivable.
         */
        $lines = purchaseLinesByAccount(billPosting(
            costLines: [
                ['account_id' => PUR_INVENTORY, 'amount' => '100000.0000'],
                ['account_id' => PUR_EXPENSE, 'amount' => '11800.0000'],
            ],
            claimableTaxes: [['account_id' => PUR_GST_INPUT, 'amount' => '18000.0000']],
        ));

        expect($lines[PUR_INVENTORY][0])->toBeDecimal('100000.0000')
            ->and($lines[PUR_EXPENSE][0])->toBeDecimal('11800.0000')
            ->and($lines[PUR_GST_INPUT][0])->toBeDecimal('18000.0000')
            ->and($lines[PUR_AP][1])->toBeDecimal('129800.0000');
    });

    it('nets a discount into the cost rather than grossing it up', function (): void {
        /*
         * The deliberate asymmetry with the sales side.
         *
         * A discount GIVEN is grossed up into contra-revenue, because "what
         * did we sell, and what did we give away" cannot be recovered from a
         * net figure. A discount RECEIVED is not like that: the cost of what
         * was bought simply is what was paid for it. Grossing it up and
         * crediting a purchase-discount account would state an inventory
         * value the business never paid — which is what IAS 2 forbids — and
         * would leave the expense permanently overstated.
         *
         * So 10,000 less a 1,000 discount is a 9,000 debit, and there is no
         * contra account in this entry at all.
         */
        $lines = purchaseLinesByAccount(billPosting(
            costLines: [['account_id' => PUR_EXPENSE, 'amount' => '9000.0000']],
        ));

        expect($lines[PUR_EXPENSE][0])->toBeDecimal('9000.0000')
            ->and($lines[PUR_AP][1])->toBeDecimal('9000.0000')
            ->and($lines)->toHaveCount(2);
    });

    it('groups two lines charged to the same account into one journal line', function (): void {
        // A journal is a summary of a document, not a copy of it. A
        // forty-line bill must not produce forty ledger lines.
        $lines = purchaseLinesByAccount(billPosting(
            costLines: [
                ['account_id' => PUR_EXPENSE, 'amount' => '400.0000'],
                ['account_id' => PUR_EXPENSE, 'amount' => '600.0000'],
                ['account_id' => PUR_SERVICES, 'amount' => '1000.0000'],
            ],
        ));

        expect($lines)->toHaveCount(3)
            ->and($lines[PUR_EXPENSE][0])->toBeDecimal('1000.0000')
            ->and($lines[PUR_SERVICES][0])->toBeDecimal('1000.0000')
            ->and($lines[PUR_AP][1])->toBeDecimal('2000.0000');
    });

    it('drops a line worth nothing', function (): void {
        // The ledger refuses a zero-amount line, and a line worth nothing
        // carries no information anyway.
        $lines = purchaseLinesByAccount(billPosting(
            costLines: [
                ['account_id' => PUR_EXPENSE, 'amount' => '1000.0000'],
                ['account_id' => PUR_SERVICES, 'amount' => '0.0000'],
            ],
            claimableTaxes: [['account_id' => PUR_GST_INPUT, 'amount' => '0.0000']],
        ));

        expect($lines)->toHaveCount(2)
            ->and($lines)->not->toHaveKey(PUR_SERVICES)
            ->and($lines)->not->toHaveKey(PUR_GST_INPUT);
    });

    it('names both our number and the vendor’s in the memo', function (): void {
        // Reconciling to a vendor statement means matching their number;
        // finding the document again means matching ours.
        $draft = billPosting(
            costLines: [['account_id' => PUR_EXPENSE, 'amount' => '1000.0000']],
            vendorRef: 'ACME-99812',
        );

        expect($draft->memo)->toContain('BILL-000001')
            ->and($draft->memo)->toContain('ACME-99812');
    });

    it('records the document as its source, so a retried approval is refused', function (): void {
        $draft = billPosting(costLines: [['account_id' => PUR_EXPENSE, 'amount' => '1000.0000']]);

        expect($draft->sourceType())->toBe('bill')
            ->and($draft->sourceId())->toBe('01926f00-0000-7000-8000-00000000bill');
    });
});

describe('a vendor credit', function (): void {
    it('credits the account the bill debited, not a purchase-returns account', function (): void {
        /*
         * The mirror image of the sales side's decision, and the reasoning
         * inverts with it.
         *
         * A credit note deliberately does NOT reverse revenue: gross sales
         * is a headline that must not fall when goods come back, so returns
         * sit beside it in their own account. An expense account has no such
         * headline — what it has to state is what the period actually cost,
         * and a purchase returned did not cost anything. Where the goods went
         * to inventory it matters more than presentation: the stock value has
         * to come back down, and only crediting inventory itself does that.
         */
        $draft = (new VendorCreditPosting(
            payableAccountId: PUR_AP,
            costLines: [['account_id' => PUR_INVENTORY, 'amount' => '20000.0000']],
            claimableTaxes: [['account_id' => PUR_GST_INPUT, 'amount' => '3600.0000']],
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-09-20'),
            documentId: '01926f00-0000-7000-8000-0000000vcn1',
            documentNumber: 'VCN-000001',
            contactId: 'contact-vendor',
        ))->toDraft();

        $lines = purchaseLinesByAccount($draft);

        expect($lines[PUR_INVENTORY][1])->toBeDecimal('20000.0000')
            // The input tax we may no longer claim.
            ->and($lines[PUR_GST_INPUT][1])->toBeDecimal('3600.0000')
            // The gross we no longer owe.
            ->and($lines[PUR_AP][0])->toBeDecimal('23600.0000')
            ->and((string) $draft->totalDebit())->toBeDecimal((string) $draft->totalCredit());
    });
});

describe('§4.7 — a vendor payment', function (): void {
    /**
     * @param  array<string, string>  $overrides
     */
    function vendorPayment(array $overrides = []): JournalDraft
    {
        $defaults = [
            'bankAmount' => '45000.0000',
            'withholdingAmount' => '5000.0000',
            'payableCleared' => '50000.0000',
            'payableClearedBase' => '50000.0000',
            'unallocatedAmount' => '0.0000',
            'currency' => 'PKR',
            'exchangeRate' => '1',
        ];

        $values = [...$defaults, ...$overrides];

        return (new VendorPaymentPosting(
            bankAccountId: PUR_BANK,
            payableAccountId: PUR_AP,
            withholdingAccountId: PUR_WHT_PAYABLE,
            advancesAccountId: PUR_ADVANCES,
            fxAccountId: PUR_FX,
            bankAmount: $values['bankAmount'],
            withholdingAmount: $values['withholdingAmount'],
            payableCleared: $values['payableCleared'],
            payableClearedBase: $values['payableClearedBase'],
            unallocatedAmount: $values['unallocatedAmount'],
            currency: $values['currency'],
            baseCurrency: 'PKR',
            exchangeRate: $values['exchangeRate'],
            date: Carbon::parse('2026-10-01'),
            paymentId: '01926f00-0000-7000-8000-00000000pay1',
            paymentNumber: 'PAY-000001',
            contactId: 'contact-vendor',
        ))->toDraft();
    }

    it('settles the vendor in full and holds the withheld tax as a liability', function (): void {
        /*
         * §4.7's worked example exactly: we withhold 10% on a services bill
         * of 50,000 and remit 45,000.
         */
        $draft = vendorPayment();
        $lines = purchaseLinesByAccount($draft);

        expect($lines[PUR_AP][0])->toBeDecimal('50000.0000')
            ->and($lines[PUR_BANK][1])->toBeDecimal('45000.0000')
            ->and($lines[PUR_WHT_PAYABLE][1])->toBeDecimal('5000.0000')
            ->and((string) $draft->totalDebit())->toBeDecimal('50000.0000')
            ->and((string) $draft->totalCredit())->toBeDecimal('50000.0000');
    });

    it('does not settle the bill by only what was paid', function (): void {
        /*
         * The plausible alternative, and it balances: credit the bank 45,000
         * and debit payables 45,000. It would leave the bill permanently
         * 5,000 short, the vendor chasing a balance they do not consider
         * outstanding, and the tax we are holding invisible until the return
         * fell due.
         *
         * The payable debit is the FULL amount settled, and this asserts the
         * difference is where it belongs.
         */
        $lines = purchaseLinesByAccount(vendorPayment());

        expect($lines[PUR_AP][0])->not->toBeDecimal($lines[PUR_BANK][1])
            ->and($lines[PUR_AP][0])->toBeDecimal('50000.0000');
    });

    it('holds an unallocated payment as an advance rather than a negative payable', function (): void {
        /*
         * Debiting payables with the whole payment would leave the vendor's
         * account negative, which reads as "they owe us a bill" rather than
         * "we have paid them in advance". One is an asset we can chase; the
         * other is a data error.
         */
        $lines = purchaseLinesByAccount(vendorPayment([
            'bankAmount' => '30000.0000',
            'withholdingAmount' => '0.0000',
            'payableCleared' => '20000.0000',
            'payableClearedBase' => '20000.0000',
            'unallocatedAmount' => '10000.0000',
        ]));

        expect($lines[PUR_AP][0])->toBeDecimal('20000.0000')
            ->and($lines[PUR_ADVANCES][0])->toBeDecimal('10000.0000')
            ->and($lines[PUR_BANK][1])->toBeDecimal('30000.0000');
    });

    it('posts a pure advance with no payable line at all', function (): void {
        $lines = purchaseLinesByAccount(vendorPayment([
            'bankAmount' => '25000.0000',
            'withholdingAmount' => '0.0000',
            'payableCleared' => '0.0000',
            'payableClearedBase' => '0.0000',
            'unallocatedAmount' => '25000.0000',
        ]));

        expect($lines)->toHaveCount(2)
            ->and($lines)->not->toHaveKey(PUR_AP)
            ->and($lines[PUR_ADVANCES][0])->toBeDecimal('25000.0000');
    });

    it('records a realised gain when the rupee cost of a foreign bill has fallen', function (): void {
        /*
         * A 1,000 dollar bill booked at 280 is carried at 280,000. We pay it
         * when the dollar is 275, so 275,000 leaves the bank and we are
         * relieved of a 280,000 liability: a 5,000 gain.
         *
         * Note the entry states RUPEES throughout. The gain exists only in
         * base currency — in dollars the payment and the payable are the same
         * thousand — so a dollar-denominated FX line would have to be zero
         * dollars and non-zero rupees, which invariant I3 refuses.
         */
        $draft = vendorPayment([
            'bankAmount' => '1000.0000',
            'withholdingAmount' => '0.0000',
            'payableCleared' => '1000.0000',
            'payableClearedBase' => '280000.0000',
            'currency' => 'USD',
            'exchangeRate' => '275',
        ]);

        $lines = purchaseLinesByAccount($draft);

        expect($lines[PUR_AP][0])->toBeDecimal('280000.0000')
            ->and($lines[PUR_BANK][1])->toBeDecimal('275000.0000')
            ->and($lines[PUR_FX][1])->toBeDecimal('5000.0000')
            ->and($draft->currency)->toBe('PKR')
            ->and($draft->exchangeRate)->toBe('1')
            ->and((string) $draft->totalDebit())->toBeDecimal((string) $draft->totalCredit());
    });

    it('records a realised loss when the rupee cost has risen', function (): void {
        $lines = purchaseLinesByAccount(vendorPayment([
            'bankAmount' => '1000.0000',
            'withholdingAmount' => '0.0000',
            'payableCleared' => '1000.0000',
            'payableClearedBase' => '280000.0000',
            'currency' => 'USD',
            'exchangeRate' => '290',
        ]));

        expect($lines[PUR_AP][0])->toBeDecimal('280000.0000')
            ->and($lines[PUR_BANK][1])->toBeDecimal('290000.0000')
            // Paying more rupees than the liability was carried at.
            ->and($lines[PUR_FX][0])->toBeDecimal('10000.0000');
    });

    it('names the foreign amount and rate in the memo', function (): void {
        // The journal states rupees, so a reader needs to know that 275,000
        // was a thousand dollars.
        $draft = vendorPayment([
            'bankAmount' => '1000.0000',
            'withholdingAmount' => '0.0000',
            'payableCleared' => '1000.0000',
            'payableClearedBase' => '280000.0000',
            'currency' => 'USD',
            'exchangeRate' => '275',
        ]);

        $bankLine = collect($draft->lines)
            ->first(fn ($line): bool => $line->accountId === PUR_BANK);

        expect($bankLine?->memo)->toContain('1000.0000')
            ->and($bankLine?->memo)->toContain('USD')
            ->and($bankLine?->memo)->toContain('275');
    });

    it('handles withholding and FX on the same payment', function (): void {
        /*
         * Both at once, because in practice they arrive together and the
         * order they are applied in changes the answer.
         *
         * A 1,000 dollar bill booked at 280 (280,000). We withhold 100
         * dollars and remit 900 at today's 275: 247,500 leaves the bank,
         * 27,500 is held as tax. We are relieved of 280,000, so the gain is
         * 280,000 − 247,500 − 27,500 = 5,000.
         */
        $draft = vendorPayment([
            'bankAmount' => '900.0000',
            'withholdingAmount' => '100.0000',
            'payableCleared' => '1000.0000',
            'payableClearedBase' => '280000.0000',
            'currency' => 'USD',
            'exchangeRate' => '275',
        ]);

        $lines = purchaseLinesByAccount($draft);

        expect($lines[PUR_AP][0])->toBeDecimal('280000.0000')
            ->and($lines[PUR_BANK][1])->toBeDecimal('247500.0000')
            ->and($lines[PUR_WHT_PAYABLE][1])->toBeDecimal('27500.0000')
            ->and($lines[PUR_FX][1])->toBeDecimal('5000.0000')
            ->and((string) $draft->totalDebit())->toBeDecimal((string) $draft->totalCredit());
    });

    it('records the payment as its source', function (): void {
        $draft = vendorPayment();

        expect($draft->sourceType())->toBe('payment_made')
            ->and($draft->sourceId())->toBe('01926f00-0000-7000-8000-00000000pay1');
    });
});
