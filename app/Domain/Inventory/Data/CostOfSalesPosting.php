<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The §4.10 posting rule for goods despatched, as a pure function.
 *
 *   Dr Cost of Goods Sold     what the goods cost us
 *      Cr Inventory           the same, taken off the shelf
 *
 * Two things about it are easy to get wrong and are fixed here.
 *
 * **It is not the sale.** The invoice posts revenue and receivables at the
 * price the customer pays; this posts cost at what the business paid, and the
 * difference between the two entries is the margin. They are separate entries
 * with separate purposes because §4.10 dates this one at SHIPMENT, which is
 * not always the invoice date.
 *
 * **The credit side is per inventory account.** Two items can share one, or
 * sit in different ones, and the entry follows the accounts rather than the
 * lines — the same grouping a bill's debit side uses.
 *
 * Nothing here decides what the goods cost. That is the weighted average's
 * job, computed under a lock by {@see App\Domain\Inventory\Services\StockLedger},
 * and passed in already decided.
 *
 * @see ACCOUNTING_RULES.md §4.10
 */
final readonly class CostOfSalesPosting
{
    /**
     * @param  array<string, string>  $costByInventoryAccount  account id => cost
     */
    public function __construct(
        public string $costOfSalesAccountId,
        public array $costByInventoryAccount,
        public string $currency,
        public Carbon $date,
        public string $documentId,
        public string $documentNumber,
        public ?string $contactId = null,
        /** 'shipment' for goods out, 'restock' for goods coming back. */
        public string $direction = 'shipment',
    ) {}

    public function toDraft(): JournalDraft
    {
        $total = BigDecimal::zero();
        $inventory = [];

        foreach ($this->costByInventoryAccount as $accountId => $cost) {
            $amount = BigDecimal::of($cost)->abs()->toScale(4, RoundingMode::HalfUp);

            if ($amount->isZero()) {
                continue;
            }

            $total = $total->plus($amount);
            $inventory[(string) $accountId] = $amount;
        }

        $lines = [];

        /*
         * A return reverses the direction rather than reversing the entry.
         * The goods are back on the shelf and the cost is no longer a cost —
         * but the original shipment happened, and a reversal would suggest it
         * did not. A credit note works the same way on the revenue side.
         */
        $goodsOut = $this->direction === 'shipment';

        $lines[] = $goodsOut
            ? JournalLineDraft::debit(
                accountId: $this->costOfSalesAccountId,
                amount: (string) $total,
                memo: $this->memo(),
                contactId: $this->contactId,
            )
            : JournalLineDraft::credit(
                accountId: $this->costOfSalesAccountId,
                amount: (string) $total,
                memo: $this->memo(),
                contactId: $this->contactId,
            );

        foreach ($inventory as $accountId => $amount) {
            $lines[] = $goodsOut
                ? JournalLineDraft::credit(
                    accountId: $accountId,
                    amount: (string) $amount,
                    memo: $this->memo(),
                )
                : JournalLineDraft::debit(
                    accountId: $accountId,
                    amount: (string) $amount,
                    memo: $this->memo(),
                );
        }

        return JournalDraft::inBaseCurrency(
            date: $this->date,
            currency: $this->currency,
            lines: $lines,
            /*
             * Its own PURPOSE on the same document.
             *
             * The ledger's idempotency key is (source type, source id,
             * purpose), and the invoice has already used 'issue'. Reusing it
             * would have the ledger refuse the cost entry as a double-post of
             * the sale — which is exactly the protection working, on the
             * wrong thing.
             */
            source: ['sales_document', $this->documentId, $goodsOut ? 'cogs' : 'cogs_reversal'],
            memo: $this->memo(),
        );
    }

    private function memo(): string
    {
        return $this->direction === 'shipment'
            ? "Cost of goods despatched — {$this->documentNumber}"
            : "Cost of goods returned — {$this->documentNumber}";
    }
}
