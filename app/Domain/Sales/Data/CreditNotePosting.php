<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The §4.5 posting rule for a credit note, as a pure function.
 *
 *   Dr Sales Returns          the net credited, as CONTRA-revenue
 *   Dr each tax component     the liability, reduced
 *      Cr Accounts Receivable the gross the customer no longer owes
 *
 * The debit goes to SALES RETURNS, not to revenue. Reversing the original
 * revenue credit would balance identically and make gross sales fall — so a
 * month with heavy returns would look like a month with no sales, and the
 * return rate would be unreportable. Contra-revenue keeps both facts.
 *
 * The tax liability genuinely reduces: the supply was credited, so the output
 * tax on it is no longer owed. That is a real debit to the same component
 * account the invoice credited, which is why it is per component here too.
 *
 * @see ACCOUNTING_RULES.md §4.5
 */
final readonly class CreditNotePosting
{
    /**
     * @param  list<array{amount: string}>  $netLines  what is being credited,
     *                                                 before tax
     * @param  list<array{account_id: string, amount: string}>  $taxes
     */
    public function __construct(
        public string $receivableAccountId,
        public string $salesReturnsAccountId,
        public array $netLines,
        public array $taxes,
        public string $currency,
        public string $baseCurrency,
        public string $exchangeRate,
        public Carbon $date,
        public string $documentId,
        public string $documentNumber,
        public ?string $contactId = null,
    ) {}

    public function toDraft(): JournalDraft
    {
        $net = BigDecimal::zero();
        $taxTotal = BigDecimal::zero();

        foreach ($this->netLines as $line) {
            $net = $net->plus(BigDecimal::of($line['amount']));
        }

        $debits = [];

        if ($net->isPositive()) {
            $debits[] = JournalLineDraft::debit(
                accountId: $this->salesReturnsAccountId,
                amount: (string) $net->toScale(4, RoundingMode::HalfUp),
                memo: "Credited — {$this->documentNumber}",
                contactId: $this->contactId,
            );
        }

        foreach ($this->taxes as $tax) {
            $amount = BigDecimal::of($tax['amount']);

            if ($amount->isZero()) {
                continue;
            }

            $taxTotal = $taxTotal->plus($amount);

            $debits[] = JournalLineDraft::debit(
                accountId: $tax['account_id'],
                amount: (string) $amount->toScale(4, RoundingMode::HalfUp),
                memo: "Tax credited — {$this->documentNumber}",
                contactId: $this->contactId,
            );
        }

        // The gross no longer owed — the sum of the debits, so this balances
        // by construction.
        $gross = $net->plus($taxTotal);

        $credits = [
            JournalLineDraft::credit(
                accountId: $this->receivableAccountId,
                amount: (string) $gross->toScale(4, RoundingMode::HalfUp),
                memo: $this->documentNumber,
                contactId: $this->contactId,
            ),
        ];

        return new JournalDraft(
            date: $this->date,
            currency: $this->currency,
            baseCurrency: $this->baseCurrency,
            exchangeRate: $this->exchangeRate,
            lines: [...$debits, ...$credits],
            source: ['credit_note', $this->documentId, 'issue'],
            memo: $this->documentNumber,
        );
    }
}
