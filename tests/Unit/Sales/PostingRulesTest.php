<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Sales\Data\CreditNotePosting;
use App\Domain\Sales\Data\CustomerPaymentPosting;
use App\Domain\Sales\Data\InvoicePosting;
use Illuminate\Support\Carbon;

/*
|---------------------------------------------------------------------------
| The sales posting rules
|---------------------------------------------------------------------------
|
| ACCOUNTING_RULES.md §10 requires that any change to posting logic ships with
| tests asserting the resulting journal LINES — account, debit, credit — not
| merely that a request returned 200. These are those tests, and they are pure:
| the posting rules are functions of figures, so no database is involved.
|
| Each rule's worked example from §4 is asserted to the cent, and then the
| places where a plausible alternative would also balance — but would destroy
| information — are asserted separately. An entry that balances is not the same
| as an entry that is right.
|
| @see ACCOUNTING_RULES.md §4.1–§4.5, §4.11, §10
*/

const AR = 'account-ar';
const DISCOUNTS = 'account-trade-discounts';
const RETURNS = 'account-sales-returns';
const REVENUE = 'account-revenue';
const SERVICES = 'account-services-revenue';
const GST = 'account-gst-output';
const PST = 'account-pst-output';
const BANK = 'account-bank';
const WHT = 'account-wht-receivable';
const ADVANCES = 'account-customer-advances';
const FX = 'account-fx';

/**
 * The journal lines, keyed by account, as [debit, credit] decimal strings.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function linesByAccount(JournalDraft $draft): array
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

describe('§4.1 — a sales invoice', function (): void {
    it('produces the worked example exactly', function (): void {
        // 100,000 net, 5% trade discount, 18% GST on the discounted amount.
        $draft = new InvoicePosting(
            receivableAccountId: AR,
            discountAccountId: DISCOUNTS,
            revenueLines: [
                ['account_id' => REVENUE, 'gross' => '100000.0000', 'discount' => '5000.0000'],
            ],
            taxes: [['account_id' => GST, 'amount' => '17100.0000']],
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-09-15'),
            documentId: 'doc-1',
            documentNumber: 'INV-000001',
            sourceType: 'invoice',
        )->toDraft();

        $draft->assertBalanced();

        $lines = linesByAccount($draft);

        // The four lines the rule table names, to the cent.
        expect($lines[AR])->toBe(['112100.0000', '0'])
            ->and($lines[DISCOUNTS])->toBe(['5000.0000', '0'])
            ->and($lines[REVENUE])->toBe(['0', '100000.0000'])
            ->and($lines[GST])->toBe(['0', '17100.0000']);

        expect((string) $draft->totalDebit())->toBeDecimal('117100.0000');
    });

    it('credits revenue GROSS and debits the discount separately', function (): void {
        /*
         * The alternative — crediting revenue net of the discount — balances
         * identically and destroys the answer to "what did we sell, and what
         * did we give away". Neither figure can be recovered from the other
         * afterwards, so this is the assertion that protects a permanent fact.
         */
        $draft = new InvoicePosting(
            receivableAccountId: AR,
            discountAccountId: DISCOUNTS,
            revenueLines: [
                ['account_id' => REVENUE, 'gross' => '1000.0000', 'discount' => '250.0000'],
            ],
            taxes: [],
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-09-15'),
            documentId: 'doc-2',
            documentNumber: 'INV-000002',
            sourceType: 'invoice',
        )->toDraft();

        $lines = linesByAccount($draft);

        expect($lines[REVENUE][1])->toBeDecimal('1000.0000')
            ->and($lines[REVENUE][1])->not->toBe('750.0000')
            ->and($lines[DISCOUNTS][0])->toBeDecimal('250.0000')
            // The customer owes the net.
            ->and($lines[AR][0])->toBeDecimal('750.0000');
    });

    it('omits the discount line entirely when nothing was discounted', function (): void {
        $draft = new InvoicePosting(
            receivableAccountId: AR,
            discountAccountId: DISCOUNTS,
            revenueLines: [
                ['account_id' => REVENUE, 'gross' => '1000.0000', 'discount' => '0'],
            ],
            taxes: [],
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-09-15'),
            documentId: 'doc-3',
            documentNumber: 'INV-000003',
            sourceType: 'invoice',
        )->toDraft();

        // Not a zero line: the ledger refuses those, and a zero discount is
        // information the entry does not need to carry.
        expect(linesByAccount($draft))->not->toHaveKey(DISCOUNTS);
        expect($draft->lines)->toHaveCount(2);
    });

    it('credits each tax component separately, so the return is filable', function (): void {
        $draft = new InvoicePosting(
            receivableAccountId: AR,
            discountAccountId: DISCOUNTS,
            revenueLines: [
                ['account_id' => REVENUE, 'gross' => '1000.0000', 'discount' => '0'],
            ],
            taxes: [
                ['account_id' => GST, 'amount' => '180.0000'],
                ['account_id' => PST, 'amount' => '130.0000'],
            ],
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-09-15'),
            documentId: 'doc-4',
            documentNumber: 'INV-000004',
            sourceType: 'invoice',
        )->toDraft();

        $lines = linesByAccount($draft);

        // A single 310 liability would balance and be unfilable: the return is
        // per component, and a total cannot be taken apart again.
        expect($lines[GST][1])->toBeDecimal('180.0000')
            ->and($lines[PST][1])->toBeDecimal('130.0000')
            ->and($lines[AR][0])->toBeDecimal('1310.0000');
    });

    it('groups several document lines onto one journal line per account', function (): void {
        /*
         * A journal entry summarises a document; it does not copy it. Three
         * invoice rows against the same revenue account are one credit — a
         * fifty-line invoice would otherwise make the general ledger
         * unreadable.
         */
        $draft = new InvoicePosting(
            receivableAccountId: AR,
            discountAccountId: DISCOUNTS,
            revenueLines: [
                ['account_id' => REVENUE, 'gross' => '100.0000', 'discount' => '0'],
                ['account_id' => REVENUE, 'gross' => '200.0000', 'discount' => '0'],
                ['account_id' => SERVICES, 'gross' => '300.0000', 'discount' => '0'],
            ],
            taxes: [],
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-09-15'),
            documentId: 'doc-5',
            documentNumber: 'INV-000005',
            sourceType: 'invoice',
        )->toDraft();

        $lines = linesByAccount($draft);

        expect($draft->lines)->toHaveCount(3);
        expect($lines[REVENUE][1])->toBeDecimal('300.0000')
            ->and($lines[SERVICES][1])->toBeDecimal('300.0000')
            ->and($lines[AR][0])->toBeDecimal('600.0000');
    });

    it('balances in both currencies on a foreign invoice', function (): void {
        $draft = new InvoicePosting(
            receivableAccountId: AR,
            discountAccountId: DISCOUNTS,
            revenueLines: [
                ['account_id' => REVENUE, 'gross' => '1000.0000', 'discount' => '0'],
            ],
            taxes: [],
            currency: 'USD',
            baseCurrency: 'PKR',
            exchangeRate: '278.5000000000',
            date: Carbon::parse('2026-09-15'),
            documentId: 'doc-6',
            documentNumber: 'INV-000006',
            sourceType: 'invoice',
        )->toDraft();

        $draft->assertBalanced();

        expect((string) $draft->totalDebit())->toBeDecimal('1000.0000')
            ->and((string) $draft->totalDebitBase())->toBeDecimal('278500.0000');
    });

    it('names the document as its source, so it cannot post twice', function (): void {
        $draft = new InvoicePosting(
            receivableAccountId: AR,
            discountAccountId: DISCOUNTS,
            revenueLines: [
                ['account_id' => REVENUE, 'gross' => '10.0000', 'discount' => '0'],
            ],
            taxes: [],
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-09-15'),
            documentId: 'doc-7',
            documentNumber: 'INV-000007',
            sourceType: 'invoice',
        )->toDraft();

        expect($draft->sourceType())->toBe('invoice')
            ->and($draft->sourceId())->toBe('doc-7')
            ->and($draft->sourcePurpose())->toBe('issue');
    });
});

describe('§4.5 — a credit note', function (): void {
    it('debits sales returns, not revenue', function (): void {
        $draft = new CreditNotePosting(
            receivableAccountId: AR,
            salesReturnsAccountId: RETURNS,
            netLines: [['amount' => '10000.0000']],
            taxes: [['account_id' => GST, 'amount' => '1800.0000']],
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-09-20'),
            documentId: 'cn-1',
            documentNumber: 'CN-000001',
        )->toDraft();

        $draft->assertBalanced();

        $lines = linesByAccount($draft);

        /*
         * Reversing the original revenue credit would balance identically and
         * make gross sales fall — so a month of heavy returns would look like
         * a month of no sales, and the return rate would be unreportable.
         */
        expect($lines[RETURNS])->toBe(['10000.0000', '0'])
            ->and($lines)->not->toHaveKey(REVENUE);

        // The tax liability genuinely reduces: the supply was credited.
        expect($lines[GST])->toBe(['1800.0000', '0']);

        // The customer no longer owes the gross.
        expect($lines[AR])->toBe(['0', '11800.0000']);
    });

    it('handles a credit with no tax', function (): void {
        $draft = new CreditNotePosting(
            receivableAccountId: AR,
            salesReturnsAccountId: RETURNS,
            netLines: [['amount' => '500.0000']],
            taxes: [],
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-09-20'),
            documentId: 'cn-2',
            documentNumber: 'CN-000002',
        )->toDraft();

        $draft->assertBalanced();

        expect($draft->lines)->toHaveCount(2);
        expect(linesByAccount($draft)[AR][1])->toBeDecimal('500.0000');
    });
});

describe('§4.3 — a customer payment with no withholding', function (): void {
    it('debits the bank and credits the receivable, nothing else', function (): void {
        $draft = new CustomerPaymentPosting(
            bankAccountId: BANK,
            receivableAccountId: AR,
            withholdingAccountId: WHT,
            advancesAccountId: ADVANCES,
            fxAccountId: FX,
            bankAmount: '112100.0000',
            withholdingAmount: '0',
            receivableCleared: '112100.0000',
            receivableClearedBase: '112100.0000',
            unallocatedAmount: '0',
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-10-01'),
            paymentId: 'pay-1',
            paymentNumber: 'RCPT-000001',
        )->toDraft();

        $draft->assertBalanced();

        expect($draft->lines)->toHaveCount(2);

        $lines = linesByAccount($draft);

        expect($lines[BANK])->toBe(['112100.0000', '0'])
            ->and($lines[AR])->toBe(['0', '112100.0000']);
    });
});

describe('§4.2 — a customer payment with withholding', function (): void {
    it('produces the worked example exactly', function (): void {
        // Customer pays 112,100, withholding 4,000 and remitting 108,100.
        $draft = new CustomerPaymentPosting(
            bankAccountId: BANK,
            receivableAccountId: AR,
            withholdingAccountId: WHT,
            advancesAccountId: ADVANCES,
            fxAccountId: FX,
            bankAmount: '108100.0000',
            withholdingAmount: '4000.0000',
            receivableCleared: '112100.0000',
            receivableClearedBase: '112100.0000',
            unallocatedAmount: '0',
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-10-01'),
            paymentId: 'pay-2',
            paymentNumber: 'RCPT-000002',
        )->toDraft();

        $draft->assertBalanced();

        $lines = linesByAccount($draft);

        expect($lines[BANK])->toBe(['108100.0000', '0'])
            // An ASSET: advance income tax paid on our behalf, recoverable
            // against the annual return. Treating it as a discount or a
            // write-off would lose a real receivable from the tax authority.
            ->and($lines[WHT])->toBe(['4000.0000', '0'])
            // The invoice is settled in FULL, not short by the withholding.
            ->and($lines[AR])->toBe(['0', '112100.0000']);

        expect((string) $draft->totalDebit())->toBeDecimal('112100.0000');
    });
});

describe('§4.4 — an overpayment', function (): void {
    it('credits the unallocated portion to customer advances', function (): void {
        // 50,000 received against a 30,000 invoice.
        $draft = new CustomerPaymentPosting(
            bankAccountId: BANK,
            receivableAccountId: AR,
            withholdingAccountId: WHT,
            advancesAccountId: ADVANCES,
            fxAccountId: FX,
            bankAmount: '50000.0000',
            withholdingAmount: '0',
            receivableCleared: '30000.0000',
            receivableClearedBase: '30000.0000',
            unallocatedAmount: '20000.0000',
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-10-01'),
            paymentId: 'pay-3',
            paymentNumber: 'RCPT-000003',
        )->toDraft();

        $draft->assertBalanced();

        $lines = linesByAccount($draft);

        expect($lines[BANK])->toBe(['50000.0000', '0'])
            ->and($lines[AR])->toBe(['0', '30000.0000'])
            /*
             * A LIABILITY: we owe goods, services or a refund. Crediting the
             * receivable with the whole 50,000 would leave the customer's
             * account negative, which reads as "we owe them an invoice" rather
             * than "we hold their money".
             */
            ->and($lines[ADVANCES])->toBe(['0', '20000.0000']);
    });

    it('posts a pure advance, with nothing allocated at all', function (): void {
        $draft = new CustomerPaymentPosting(
            bankAccountId: BANK,
            receivableAccountId: AR,
            withholdingAccountId: WHT,
            advancesAccountId: ADVANCES,
            fxAccountId: FX,
            bankAmount: '25000.0000',
            withholdingAmount: '0',
            receivableCleared: '0',
            receivableClearedBase: '0',
            unallocatedAmount: '25000.0000',
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            date: Carbon::parse('2026-10-01'),
            paymentId: 'pay-4',
            paymentNumber: 'RCPT-000004',
        )->toDraft();

        $draft->assertBalanced();

        // No receivable line at all — there is nothing to clear.
        expect(linesByAccount($draft))->not->toHaveKey(AR);
        expect($draft->lines)->toHaveCount(2);
    });
});

describe('§4.11 — realised FX on settlement', function (): void {
    it('produces the worked example exactly', function (): void {
        /*
         * Invoiced USD 1,000 at 278.00 (PKR 278,000); settled at 280.50
         * (PKR 280,500). The receivable clears at the INVOICE rate, and the
         * 2,500 difference is the gain.
         */
        $draft = new CustomerPaymentPosting(
            bankAccountId: BANK,
            receivableAccountId: AR,
            withholdingAccountId: WHT,
            advancesAccountId: ADVANCES,
            fxAccountId: FX,
            bankAmount: '1000.0000',
            withholdingAmount: '0',
            receivableCleared: '1000.0000',
            receivableClearedBase: '278000.0000',
            unallocatedAmount: '0',
            currency: 'USD',
            baseCurrency: 'PKR',
            exchangeRate: '280.5000000000',
            date: Carbon::parse('2026-10-01'),
            paymentId: 'pay-5',
            paymentNumber: 'RCPT-000005',
        )->toDraft();

        $draft->assertBalanced();

        /*
         * The entry states PKR, exactly as the rule table does. A realised
         * difference exists only in base currency — in dollars the receipt and
         * the receivable are the same 1,000 — so a USD-denominated entry could
         * not carry it without a line of zero dollars, which I3 refuses.
         */
        expect($draft->currency)->toBe('PKR')
            ->and($draft->exchangeRate)->toBe('1');

        $lines = linesByAccount($draft);

        expect($lines[BANK])->toBe(['280500.0000', '0'])
            // Cleared at the rate on the invoice, never a re-derived one.
            ->and($lines[AR])->toBe(['0', '278000.0000'])
            ->and($lines[FX])->toBe(['0', '2500.0000']);

        // And the dollar figure survives in the memo, so the entry is
        // readable without opening the payment.
        expect($draft->lines[0]->memo)->toContain('1000.0000 USD at 280.5000000000');
    });

    it('posts a loss when the rate moved against us', function (): void {
        // Invoiced at 280.50, settled at 278.00: a 2,500 loss.
        $draft = new CustomerPaymentPosting(
            bankAccountId: BANK,
            receivableAccountId: AR,
            withholdingAccountId: WHT,
            advancesAccountId: ADVANCES,
            fxAccountId: FX,
            bankAmount: '1000.0000',
            withholdingAmount: '0',
            receivableCleared: '1000.0000',
            receivableClearedBase: '280500.0000',
            unallocatedAmount: '0',
            currency: 'USD',
            baseCurrency: 'PKR',
            exchangeRate: '278.0000000000',
            date: Carbon::parse('2026-10-01'),
            paymentId: 'pay-6',
            paymentNumber: 'RCPT-000006',
        )->toDraft();

        $draft->assertBalanced();

        expect(linesByAccount($draft)[FX])->toBe(['2500.0000', '0']);
    });

    it('posts no FX line when the rate has not moved', function (): void {
        $draft = new CustomerPaymentPosting(
            bankAccountId: BANK,
            receivableAccountId: AR,
            withholdingAccountId: WHT,
            advancesAccountId: ADVANCES,
            fxAccountId: FX,
            bankAmount: '1000.0000',
            withholdingAmount: '0',
            receivableCleared: '1000.0000',
            receivableClearedBase: '278000.0000',
            unallocatedAmount: '0',
            currency: 'USD',
            baseCurrency: 'PKR',
            exchangeRate: '278.0000000000',
            date: Carbon::parse('2026-10-01'),
            paymentId: 'pay-7',
            paymentNumber: 'RCPT-000007',
        )->toDraft();

        $draft->assertBalanced();

        expect(linesByAccount($draft))->not->toHaveKey(FX);
    });

    it('handles withholding and FX on the same receipt', function (): void {
        /*
         * The awkward combination: a foreign invoice, settled at a new rate,
         * with tax withheld. All three parts have to land in the right place
         * and the entry has to balance in both currencies.
         */
        $draft = new CustomerPaymentPosting(
            bankAccountId: BANK,
            receivableAccountId: AR,
            withholdingAccountId: WHT,
            advancesAccountId: ADVANCES,
            fxAccountId: FX,
            bankAmount: '960.0000',
            withholdingAmount: '40.0000',
            receivableCleared: '1000.0000',
            receivableClearedBase: '278000.0000',
            unallocatedAmount: '0',
            currency: 'USD',
            baseCurrency: 'PKR',
            exchangeRate: '280.5000000000',
            date: Carbon::parse('2026-10-01'),
            paymentId: 'pay-8',
            paymentNumber: 'RCPT-000008',
        )->toDraft();

        $draft->assertBalanced();

        $lines = linesByAccount($draft);

        // 960 and 40 dollars at 280.50; the receivable at 278.00. The gain
        // is the 2,500 the whole 1,000 dollars appreciated by.
        expect($lines[BANK][0])->toBeDecimal('269280.0000')
            ->and($lines[WHT][0])->toBeDecimal('11220.0000')
            ->and($lines[AR][1])->toBeDecimal('278000.0000')
            ->and($lines[FX][1])->toBeDecimal('2500.0000');
    });
});
