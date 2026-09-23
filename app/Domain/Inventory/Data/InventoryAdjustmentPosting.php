<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The posting rule for a stock adjustment, as a pure function.
 *
 * Stock went up, so something else went down, and the adjustment says what:
 *
 *   Dr Inventory              Cr the account the adjustment names
 *   Cr Inventory              Dr the account the adjustment names
 *
 * The named account is the whole point of asking for one. A stocktake that
 * found less is a write-off and belongs against an expense; opening stock
 * brought in from a previous system belongs against opening balance equity;
 * a revaluation belongs against whichever account the business has decided
 * carries it. Defaulting it would put every one of those in the same place
 * and make the trial balance unreadable.
 *
 * @see ACCOUNTING_RULES.md §4.16
 */
final readonly class InventoryAdjustmentPosting
{
    /**
     * @param  array<string, string>  $valueByInventoryAccount  account id =>
     *                                                          SIGNED value change
     */
    public function __construct(
        public string $contraAccountId,
        public array $valueByInventoryAccount,
        public string $currency,
        public Carbon $date,
        public string $adjustmentId,
        public string $adjustmentNumber,
        public string $reason,
    ) {}

    public function toDraft(): JournalDraft
    {
        $lines = [];
        $net = BigDecimal::zero();

        foreach ($this->valueByInventoryAccount as $accountId => $change) {
            $amount = BigDecimal::of($change)->toScale(4, RoundingMode::HalfUp);

            if ($amount->isZero()) {
                continue;
            }

            $net = $net->plus($amount);

            $lines[] = $amount->isPositive()
                ? JournalLineDraft::debit(
                    accountId: (string) $accountId,
                    amount: (string) $amount,
                    memo: $this->memo(),
                )
                : JournalLineDraft::credit(
                    accountId: (string) $accountId,
                    amount: (string) $amount->abs(),
                    memo: $this->memo(),
                );
        }

        /*
         * The other side is ONE line for the net, not one per inventory
         * account. An adjustment that writes 500 off one account and 500 on
         * to another has no effect on the business at all, and posting two
         * contra lines that cancel would say it did.
         */
        if (! $net->isZero()) {
            $lines[] = $net->isPositive()
                ? JournalLineDraft::credit(
                    accountId: $this->contraAccountId,
                    amount: (string) $net,
                    memo: $this->memo(),
                )
                : JournalLineDraft::debit(
                    accountId: $this->contraAccountId,
                    amount: (string) $net->abs(),
                    memo: $this->memo(),
                );
        }

        return JournalDraft::inBaseCurrency(
            date: $this->date,
            currency: $this->currency,
            lines: $lines,
            source: ['inventory_adjustment', $this->adjustmentId, 'issue'],
            memo: $this->memo(),
        );
    }

    private function memo(): string
    {
        return "{$this->adjustmentNumber} · {$this->reason}";
    }
}
