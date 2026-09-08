<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The posting rule for a vendor credit — §4.6 run backwards.
 *
 *   Dr Accounts Payable        the gross we no longer owe
 *      Cr Expense or Inventory the cost, reversed, per account
 *      Cr GST Input Receivable the input tax we may no longer claim
 *
 * The credit goes back to the SAME account the bill debited, and here that is
 * right — which is the mirror image of the sales side, where a credit note
 * deliberately does NOT reverse revenue but debits Sales Returns instead.
 *
 * The difference is what each figure is for. Gross sales is a headline number
 * that must not fall when goods come back, so returns are kept beside it.
 * An expense account has no such headline: what it has to state is what the
 * period actually cost, and a purchase returned did not cost anything. A
 * "Purchase Returns" contra-expense would leave the expense overstated and
 * the reader with two figures to net in their head to answer the only
 * question the account exists to answer.
 *
 * Where the goods went to inventory this matters more than presentation: the
 * stock value has to come back down, and only crediting the inventory account
 * itself does that.
 *
 * @see ACCOUNTING_RULES.md §4.6
 */
final readonly class VendorCreditPosting
{
    /**
     * @param  list<array{account_id: string, amount: string}>  $costLines
     *                                                                      one entry per line: which account is being credited back,
     *                                                                      and how much — net of discount, plus any tax that could not
     *                                                                      be reclaimed and was therefore capitalised into it
     * @param  list<array{account_id: string, amount: string}>  $claimableTaxes
     */
    public function __construct(
        public string $payableAccountId,
        public array $costLines,
        public array $claimableTaxes,
        public string $currency,
        public string $baseCurrency,
        public string $exchangeRate,
        public Carbon $date,
        public string $documentId,
        public string $documentNumber,
        public ?string $contactId = null,
        public ?string $vendorReference = null,
    ) {}

    public function toDraft(): JournalDraft
    {
        $credits = [];
        $total = BigDecimal::zero();

        $costByAccount = [];

        foreach ($this->costLines as $line) {
            $amount = BigDecimal::of($line['amount']);

            $costByAccount[$line['account_id']] = isset($costByAccount[$line['account_id']])
                ? $costByAccount[$line['account_id']]->plus($amount)
                : $amount;
        }

        foreach ($costByAccount as $accountId => $amount) {
            $amount = $amount->toScale(4, RoundingMode::HalfUp);

            if ($amount->isZero()) {
                continue;
            }

            $total = $total->plus($amount);

            $credits[] = JournalLineDraft::credit(
                accountId: (string) $accountId,
                amount: (string) $amount,
                memo: "Credited — {$this->documentNumber}",
                contactId: $this->contactId,
            );
        }

        foreach ($this->claimableTaxes as $tax) {
            $amount = BigDecimal::of($tax['amount'])->toScale(4, RoundingMode::HalfUp);

            if ($amount->isZero()) {
                continue;
            }

            $total = $total->plus($amount);

            $credits[] = JournalLineDraft::credit(
                accountId: $tax['account_id'],
                amount: (string) $amount,
                memo: "Input tax credited — {$this->documentNumber}",
                contactId: $this->contactId,
            );
        }

        // The gross no longer owed — the sum of the credits, so this balances
        // by construction.
        $debits = [JournalLineDraft::debit(
            accountId: $this->payableAccountId,
            amount: (string) $total->toScale(4, RoundingMode::HalfUp),
            memo: $this->memo(),
            contactId: $this->contactId,
        )];

        return new JournalDraft(
            date: $this->date,
            currency: $this->currency,
            baseCurrency: $this->baseCurrency,
            exchangeRate: $this->exchangeRate,
            lines: [...$debits, ...$credits],
            source: ['vendor_credit', $this->documentId, 'issue'],
            memo: $this->memo(),
        );
    }

    private function memo(): string
    {
        if ($this->vendorReference === null || trim($this->vendorReference) === '') {
            return $this->documentNumber;
        }

        return "{$this->documentNumber} · vendor ref {$this->vendorReference}";
    }
}
