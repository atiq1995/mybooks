<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Sales\Data\InvoicePosting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The §4.6 posting rule for a vendor bill, as a pure function.
 *
 * No database, no models, no tenant. Figures in, a {@see JournalDraft} out.
 *
 *   Dr Expense or Inventory       the cost, per account
 *   Dr GST Input Receivable       the tax we may actually reclaim
 *      Cr Accounts Payable        the gross the vendor is owed
 *
 * Two decisions here are the whole point of the rule, and both are places
 * where the obvious alternative is wrong.
 *
 * NON-CLAIMABLE INPUT TAX IS CAPITALISED, not posted to the receivable. §4.6
 * says so and the reason is not a technicality: tax we cannot reclaim is
 * money gone. Booking it as a receivable would overstate assets by its whole
 * value and understate the cost of whatever was bought by the same amount, so
 * both the balance sheet and the margin on that purchase would be wrong. This
 * is decided per LINE, because claimability is a fact about what was bought.
 *
 * DISCOUNTS ARE NETTED INTO THE COST, and this is deliberately the opposite
 * of {@see InvoicePosting}, where a discount given is
 * grossed up into contra-revenue.
 *
 * The asymmetry is real. "What did we sell, and what did we give away" is
 * management information that cannot be recovered from a net figure, so the
 * sales side keeps both. The cost of a purchase is not like that: the cost of
 * an asset simply IS what was paid for it, trade discount included — grossing
 * it up and crediting a discount account would state an inventory value the
 * business never paid, which is exactly what IAS 2 forbids. So a purchase
 * discount reduces the debit, and there is no contra account on this side.
 *
 * @see ACCOUNTING_RULES.md §4.6
 */
final readonly class BillPosting
{
    /**
     * @param  list<array{account_id: string, amount: string}>  $costLines
     *                                                                      one entry per line: where the cost lands, and the cost —
     *                                                                      already net of discount and already including any tax that
     *                                                                      could not be reclaimed
     * @param  list<array{account_id: string, amount: string}>  $claimableTaxes
     *                                                                           one entry per tax component, already summed across the
     *                                                                           claimable lines only
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
        public string $sourceType,
        public ?string $contactId = null,
        /**
         * The vendor's own number for this bill. It goes in the memo so the
         * ledger can be reconciled against the vendor's statement without
         * opening the document.
         */
        public ?string $vendorReference = null,
    ) {}

    /**
     * Build the entry.
     *
     * The payable is derived here rather than passed in, deliberately: it is
     * the sum of every debit, and computing it from the same figures that
     * produce the debits is what makes the entry balance by construction
     * rather than by luck.
     */
    public function toDraft(): JournalDraft
    {
        $debits = [];
        $total = BigDecimal::zero();

        /*
         * Cost, grouped by account. Two lines pointing at the same expense
         * account produce ONE journal line — a journal is a summary of a
         * document, not a copy of it.
         */
        $costByAccount = [];

        foreach ($this->costLines as $line) {
            $amount = BigDecimal::of($line['amount']);

            $costByAccount[$line['account_id']] = isset($costByAccount[$line['account_id']])
                ? $costByAccount[$line['account_id']]->plus($amount)
                : $amount;
        }

        foreach ($costByAccount as $accountId => $amount) {
            $amount = $amount->toScale(4, RoundingMode::HalfUp);

            // A line worth nothing carries no information, and the ledger
            // refuses a zero-amount line anyway.
            if ($amount->isZero()) {
                continue;
            }

            $total = $total->plus($amount);

            $debits[] = JournalLineDraft::debit(
                accountId: (string) $accountId,
                amount: (string) $amount,
                memo: $this->lineMemo(),
                contactId: $this->contactId,
            );
        }

        /*
         * Input tax, per component rather than as one total, because the tax
         * return is filed per component and a summed receivable cannot be
         * taken apart afterwards.
         */
        foreach ($this->claimableTaxes as $tax) {
            $amount = BigDecimal::of($tax['amount'])->toScale(4, RoundingMode::HalfUp);

            if ($amount->isZero()) {
                continue;
            }

            $total = $total->plus($amount);

            $debits[] = JournalLineDraft::debit(
                accountId: $tax['account_id'],
                amount: (string) $amount,
                memo: "Input tax — {$this->documentNumber}",
                contactId: $this->contactId,
            );
        }

        $credits = [JournalLineDraft::credit(
            accountId: $this->payableAccountId,
            amount: (string) $total->toScale(4, RoundingMode::HalfUp),
            memo: $this->lineMemo(),
            contactId: $this->contactId,
        )];

        return new JournalDraft(
            date: $this->date,
            currency: $this->currency,
            baseCurrency: $this->baseCurrency,
            exchangeRate: $this->exchangeRate,
            lines: [...$debits, ...$credits],
            // The document is the source, so a retried approval is refused by
            // the idempotency index rather than doubling the liability.
            source: [$this->sourceType, $this->documentId, 'issue'],
            memo: $this->lineMemo(),
        );
    }

    /**
     * Our number, and the vendor's if we have it.
     *
     * Both, because reconciling to a vendor statement means matching their
     * number, and finding the document again means matching ours.
     */
    private function lineMemo(): string
    {
        if ($this->vendorReference === null || trim($this->vendorReference) === '') {
            return $this->documentNumber;
        }

        return "{$this->documentNumber} · vendor ref {$this->vendorReference}";
    }
}
