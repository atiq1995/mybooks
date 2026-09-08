<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The §4.2, §4.3, §4.4 and §4.11 posting rules for a customer receipt — one
 * pure function, because they are one rule whose parts may be zero.
 *
 *   Dr Bank                      what actually arrived
 *   Dr Withholding Receivable    tax the customer withheld on our behalf
 *   Dr FX Gain/Loss              a realised loss
 *      Cr Accounts Receivable    what the allocated invoices no longer owe
 *      Cr Customer Advances      anything not yet allocated
 *      Cr FX Gain/Loss           a realised gain
 *
 * POSTED IN BASE CURRENCY, always — including for a receipt in another
 * currency. That is not a simplification, it is the only arrangement that
 * works, and §4.11's own worked example is stated in base currency for the
 * same reason:
 *
 * A realised FX difference exists ONLY in base currency. In the transaction
 * currency the receipt and the receivable are the same 1,000 dollars; the gain
 * is entirely in what those dollars are now worth. An FX line denominated in
 * dollars would therefore have to be zero dollars and non-zero rupees — and
 * invariant I3 refuses a line with no amount on either side, rightly, because
 * such a line cannot be read. So the settlement entry states rupees, and the
 * dollar view of the receipt lives on the payment document where it belongs.
 *
 * Three further decisions, each a place where a plausible alternative is wrong:
 *
 * WITHHOLDING is an asset, not a discount and not a shortfall. It is advance
 * income tax paid on our behalf, recoverable against the annual return, and
 * the invoice is settled in FULL. Treating it as a write-off loses a real
 * receivable from the tax authority.
 *
 * The UNALLOCATED remainder is a liability. Crediting the receivable with the
 * whole receipt would leave the customer's account negative, which reads as
 * "we owe them an invoice" rather than "we hold their money".
 *
 * The RECEIVABLE clears at the rate on the invoice, never a re-derived one.
 * That is what makes the gain a fact about this settlement rather than an
 * artefact of when the report was run.
 *
 * @see ACCOUNTING_RULES.md §4.2, §4.3, §4.4, §4.11
 */
final readonly class CustomerPaymentPosting
{
    /**
     * Every amount below except `receivableClearedBase` is in the PAYMENT's
     * currency, and is converted at `exchangeRate`. The receivable's base
     * amount is passed already converted, because it uses the invoice's rate
     * rather than this one.
     *
     * @param  string  $bankAmount  what arrived: settled less withholding
     * @param  string  $receivableCleared  what the allocated invoices no
     *                                     longer owe, in payment currency
     * @param  string  $receivableClearedBase  the same, at the rates those
     *                                         invoices were issued at
     */
    public function __construct(
        public string $bankAccountId,
        public string $receivableAccountId,
        public string $withholdingAccountId,
        public string $advancesAccountId,
        public string $fxAccountId,
        public string $bankAmount,
        public string $withholdingAmount,
        public string $receivableCleared,
        public string $receivableClearedBase,
        public string $unallocatedAmount,
        public string $currency,
        public string $baseCurrency,
        public string $exchangeRate,
        public Carbon $date,
        public string $paymentId,
        public string $paymentNumber,
        public ?string $contactId = null,
    ) {}

    public function toDraft(): JournalDraft
    {
        $rate = BigDecimal::of($this->exchangeRate);

        // Everything arrives at today's rate.
        $bank = $this->convert($this->bankAmount, $rate);
        $withheld = $this->convert($this->withholdingAmount, $rate);
        $unallocated = $this->convert($this->unallocatedAmount, $rate);

        // The receivable leaves at the invoice's.
        $cleared = $this->money(BigDecimal::of($this->receivableClearedBase));

        $debits = [];
        $credits = [];

        if ($bank->isPositive()) {
            $debits[] = JournalLineDraft::debit(
                accountId: $this->bankAccountId,
                amount: (string) $bank,
                memo: $this->receiptMemo(),
                contactId: $this->contactId,
            );
        }

        if ($withheld->isPositive()) {
            $debits[] = JournalLineDraft::debit(
                accountId: $this->withholdingAccountId,
                amount: (string) $withheld,
                memo: "Tax withheld — {$this->paymentNumber}",
                contactId: $this->contactId,
            );
        }

        if ($cleared->isPositive()) {
            $credits[] = JournalLineDraft::credit(
                accountId: $this->receivableAccountId,
                amount: (string) $cleared,
                memo: "Settled — {$this->paymentNumber}",
                contactId: $this->contactId,
            );
        }

        if ($unallocated->isPositive()) {
            $credits[] = JournalLineDraft::credit(
                accountId: $this->advancesAccountId,
                amount: (string) $unallocated,
                memo: "Advance held — {$this->paymentNumber}",
                contactId: $this->contactId,
            );
        }

        /*
         * The realised difference is whatever the entry needs to balance.
         *
         * Computing it that way rather than as "cleared at today's rate minus
         * cleared at the invoice's" is deliberate: the two are the same
         * figure, but only this one cannot drift from the lines by a rounding
         * step — and an entry that does not balance is refused outright.
         */
        $difference = $bank->plus($withheld)->minus($cleared)->minus($unallocated);

        if ($difference->isNegative()) {
            // Received less base currency than the receivable was carried at.
            $debits[] = JournalLineDraft::debit(
                accountId: $this->fxAccountId,
                amount: (string) $difference->abs(),
                memo: "Realised FX loss — {$this->paymentNumber}",
            );
        } elseif ($difference->isPositive()) {
            $credits[] = JournalLineDraft::credit(
                accountId: $this->fxAccountId,
                amount: (string) $difference,
                memo: "Realised FX gain — {$this->paymentNumber}",
            );
        }

        return new JournalDraft(
            date: $this->date,
            // Base currency, with a rate of 1. See the class docblock.
            currency: $this->baseCurrency,
            baseCurrency: $this->baseCurrency,
            exchangeRate: '1',
            lines: [...$debits, ...$credits],
            source: ['payment_received', $this->paymentId, 'settle'],
            memo: $this->paymentNumber,
        );
    }

    /**
     * What the entry's memo should say.
     *
     * A foreign receipt names the amount and rate, because the journal states
     * base currency and somebody reading it later needs to know that 280,500
     * rupees was a thousand dollars.
     */
    private function receiptMemo(): string
    {
        if ($this->currency === $this->baseCurrency) {
            return $this->paymentNumber;
        }

        return sprintf(
            '%s — %s %s at %s',
            $this->paymentNumber,
            $this->bankAmount,
            $this->currency,
            $this->exchangeRate,
        );
    }

    private function convert(string $amount, BigDecimal $rate): BigDecimal
    {
        return $this->money(BigDecimal::of($amount)->multipliedBy($rate));
    }

    private function money(BigDecimal $value): BigDecimal
    {
        return $value->toScale(4, RoundingMode::HalfUp);
    }
}
