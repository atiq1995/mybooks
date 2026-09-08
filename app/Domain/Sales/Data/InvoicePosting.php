<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The §4.1 posting rule for a sales invoice, as a pure function.
 *
 * No database, no models, no tenant. Figures in, a {@see JournalDraft} out —
 * which means the most consequential accounting decision in the sales module
 * can be asserted line by line in microseconds, and is provably free of side
 * effects.
 *
 * The rule, and why each part of it is not negotiable:
 *
 *   Dr Accounts Receivable    the gross the customer owes
 *   Dr Trade Discounts        every discount given, as CONTRA-revenue
 *      Cr Revenue             the GROSS value of what was sold
 *      Cr each tax component  the liability, per component
 *
 * Revenue is credited GROSS and the discount debited separately. Netting the
 * discount into revenue would balance perfectly and destroy the answer to
 * "what did we sell, and what did we give away" — permanently, because
 * neither figure can be recovered from the other afterwards.
 *
 * Tax is credited per COMPONENT, not as one total, because the sales tax
 * return is filed per component and a summed liability cannot be taken apart.
 *
 * @see ACCOUNTING_RULES.md §4.1
 */
final readonly class InvoicePosting
{
    /**
     * @param  list<array{account_id: string, gross: string, discount: string}>  $revenueLines
     *                                                                                          one entry per line: where the revenue lands, its gross value,
     *                                                                                          and every discount attributed to it
     * @param  list<array{account_id: string, amount: string}>  $taxes
     *                                                                  one entry per tax component, already summed across lines
     */
    public function __construct(
        public string $receivableAccountId,
        public string $discountAccountId,
        public array $revenueLines,
        public array $taxes,
        public string $currency,
        public string $baseCurrency,
        public string $exchangeRate,
        public Carbon $date,
        public string $documentId,
        public string $documentNumber,
        public string $sourceType,
        public ?string $contactId = null,
    ) {}

    /**
     * Build the entry.
     *
     * The receivable is derived here rather than taken as a parameter, and
     * deliberately so: it is the sum of everything else, and computing it
     * from the same figures that produce the credits is what guarantees the
     * entry balances by construction rather than by luck.
     */
    public function toDraft(): JournalDraft
    {
        $revenueTotal = BigDecimal::zero();
        $discountTotal = BigDecimal::zero();
        $taxTotal = BigDecimal::zero();

        $credits = [];

        /*
         * Revenue, grouped by account. Two lines pointing at the same revenue
         * account produce ONE journal line — a journal is a summary of a
         * document, not a copy of it, and one line per invoice row makes a
         * fifty-line invoice unreadable in the ledger.
         */
        $revenueByAccount = [];
        $discountByLine = BigDecimal::zero();

        foreach ($this->revenueLines as $line) {
            $gross = BigDecimal::of($line['gross']);
            $discount = BigDecimal::of($line['discount']);

            $revenueByAccount[$line['account_id']] = isset($revenueByAccount[$line['account_id']])
                ? $revenueByAccount[$line['account_id']]->plus($gross)
                : $gross;

            $discountByLine = $discountByLine->plus($discount);
            $revenueTotal = $revenueTotal->plus($gross);
        }

        $discountTotal = $discountByLine;

        foreach ($revenueByAccount as $accountId => $amount) {
            // A line worth nothing carries no information, and the ledger
            // refuses a zero-amount line anyway.
            if ($amount->isZero()) {
                continue;
            }

            $credits[] = JournalLineDraft::credit(
                accountId: (string) $accountId,
                amount: (string) $amount->toScale(4, RoundingMode::HalfUp),
                memo: "Revenue — {$this->documentNumber}",
                contactId: $this->contactId,
            );
        }

        foreach ($this->taxes as $tax) {
            $amount = BigDecimal::of($tax['amount']);

            if ($amount->isZero()) {
                continue;
            }

            $taxTotal = $taxTotal->plus($amount);

            $credits[] = JournalLineDraft::credit(
                accountId: $tax['account_id'],
                amount: (string) $amount->toScale(4, RoundingMode::HalfUp),
                memo: "Tax — {$this->documentNumber}",
                contactId: $this->contactId,
            );
        }

        $debits = [];

        /*
         * The receivable: revenue plus tax, less the discount given. That is
         * exactly what the customer owes, and it is the sum of the credits
         * minus the contra-revenue debit — so the entry balances by
         * construction.
         */
        $receivable = $revenueTotal->plus($taxTotal)->minus($discountTotal);

        $debits[] = JournalLineDraft::debit(
            accountId: $this->receivableAccountId,
            amount: (string) $receivable->toScale(4, RoundingMode::HalfUp),
            memo: $this->documentNumber,
            contactId: $this->contactId,
        );

        if ($discountTotal->isPositive()) {
            $debits[] = JournalLineDraft::debit(
                accountId: $this->discountAccountId,
                amount: (string) $discountTotal->toScale(4, RoundingMode::HalfUp),
                memo: "Discount given — {$this->documentNumber}",
                contactId: $this->contactId,
            );
        }

        return new JournalDraft(
            date: $this->date,
            currency: $this->currency,
            baseCurrency: $this->baseCurrency,
            exchangeRate: $this->exchangeRate,
            lines: [...$debits, ...$credits],
            // The document is the source, so a retried issue is refused by
            // the idempotency index rather than doubling the revenue.
            source: [$this->sourceType, $this->documentId, 'issue'],
            memo: "{$this->documentNumber}",
        );
    }
}
