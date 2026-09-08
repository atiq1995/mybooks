<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The §4.7 and §4.11 posting rules for a payment made to a vendor — one pure
 * function, because they are one rule whose parts may be zero.
 *
 *   Dr Accounts Payable          what the allocated bills no longer owe
 *   Dr Vendor Advances           anything paid but not yet allocated
 *   Dr FX Gain/Loss              a realised loss
 *      Cr Bank                   what actually left the account
 *      Cr Withholding Payable    tax we deducted and now owe the authority
 *      Cr FX Gain/Loss           a realised gain
 *
 * POSTED IN BASE CURRENCY, always — including for a payment in another
 * currency, for the reason §4.11's own worked example is stated that way. A
 * realised FX difference exists only in base currency: in the transaction
 * currency the payment and the payable are the same thousand dollars, and the
 * difference is entirely in what those dollars now cost. An FX line
 * denominated in dollars would have to be zero dollars and non-zero rupees,
 * and invariant I3 refuses a line with no amount on either side — rightly,
 * because such a line cannot be read.
 *
 * Three decisions, each a place where the plausible alternative is wrong:
 *
 * WITHHOLDING IS A LIABILITY, and the vendor's account is settled IN FULL.
 * We deducted the tax on the authority's behalf and now owe it to them. The
 * tempting alternative — paying the vendor less and settling their account by
 * less — would leave the bill permanently part-paid, the vendor chasing a
 * balance they do not consider outstanding, and the tax we are holding
 * invisible until the return is due.
 *
 * THE UNALLOCATED REMAINDER IS AN ASSET. Debiting payables with the whole
 * payment would leave the vendor's account negative, which reads as "they owe
 * us a bill" rather than "we have paid them in advance".
 *
 * THE PAYABLE CLEARS AT THE RATE ON THE BILL, never a re-derived one — what
 * makes the difference a fact about this settlement rather than an artefact
 * of when the report was run.
 *
 * @see ACCOUNTING_RULES.md §4.7, §4.11
 */
final readonly class VendorPaymentPosting
{
    /**
     * Every amount below except `payableClearedBase` is in the PAYMENT's
     * currency and is converted at `exchangeRate`. The payable's base amount
     * is passed already converted, because it uses the bills' rates rather
     * than this one.
     *
     * @param  string  $bankAmount  what left the bank: settled less withholding
     * @param  string  $payableCleared  what the allocated bills no longer owe,
     *                                  in payment currency
     * @param  string  $payableClearedBase  the same, at the rates those bills
     *                                      were booked at
     */
    public function __construct(
        public string $bankAccountId,
        public string $payableAccountId,
        public string $withholdingAccountId,
        public string $advancesAccountId,
        public string $fxAccountId,
        public string $bankAmount,
        public string $withholdingAmount,
        public string $payableCleared,
        public string $payableClearedBase,
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

        // Everything moves at today's rate.
        $bank = $this->convert($this->bankAmount, $rate);
        $withheld = $this->convert($this->withholdingAmount, $rate);
        $unallocated = $this->convert($this->unallocatedAmount, $rate);

        // The payable leaves at the bill's.
        $cleared = $this->money(BigDecimal::of($this->payableClearedBase));

        $debits = [];
        $credits = [];

        if ($cleared->isPositive()) {
            $debits[] = JournalLineDraft::debit(
                accountId: $this->payableAccountId,
                amount: (string) $cleared,
                memo: "Settled — {$this->paymentNumber}",
                contactId: $this->contactId,
            );
        }

        if ($unallocated->isPositive()) {
            $debits[] = JournalLineDraft::debit(
                accountId: $this->advancesAccountId,
                amount: (string) $unallocated,
                memo: "Advance paid — {$this->paymentNumber}",
                contactId: $this->contactId,
            );
        }

        if ($bank->isPositive()) {
            $credits[] = JournalLineDraft::credit(
                accountId: $this->bankAccountId,
                amount: (string) $bank,
                memo: $this->paymentMemo(),
                contactId: $this->contactId,
            );
        }

        if ($withheld->isPositive()) {
            $credits[] = JournalLineDraft::credit(
                accountId: $this->withholdingAccountId,
                amount: (string) $withheld,
                memo: "Tax withheld — {$this->paymentNumber}",
                contactId: $this->contactId,
            );
        }

        /*
         * The realised difference is whatever the entry needs to balance.
         *
         * Computed that way rather than as "cleared at today's rate minus
         * cleared at the bill's" because the two are the same figure, and
         * only this one cannot drift from the lines by a rounding step.
         *
         * The sign reads from our side of the transaction: the debits are
         * what we are relieved of, the credits what we gave up. Needing more
         * debit than we have credit means the payable was carried at more
         * than it cost to settle — a gain.
         */
        $difference = $cleared->plus($unallocated)->minus($bank)->minus($withheld);

        if ($difference->isPositive()) {
            $credits[] = JournalLineDraft::credit(
                accountId: $this->fxAccountId,
                amount: (string) $difference,
                memo: "Realised FX gain — {$this->paymentNumber}",
            );
        } elseif ($difference->isNegative()) {
            $debits[] = JournalLineDraft::debit(
                accountId: $this->fxAccountId,
                amount: (string) $difference->abs(),
                memo: "Realised FX loss — {$this->paymentNumber}",
            );
        }

        return new JournalDraft(
            date: $this->date,
            // Base currency, with a rate of 1. See the class docblock.
            currency: $this->baseCurrency,
            baseCurrency: $this->baseCurrency,
            exchangeRate: '1',
            lines: [...$debits, ...$credits],
            source: ['payment_made', $this->paymentId, 'settle'],
            memo: $this->paymentNumber,
        );
    }

    /**
     * A foreign payment names the amount and rate, because the journal states
     * base currency and a reader needs to know that 280,500 rupees was a
     * thousand dollars.
     */
    private function paymentMemo(): string
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
