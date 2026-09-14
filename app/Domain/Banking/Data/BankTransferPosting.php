<?php

declare(strict_types=1);

namespace App\Domain\Banking\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The §4.9 posting rule for a transfer, as a pure function.
 *
 *   Dr Destination account   what arrived
 *      Cr Source account     what left
 *
 * Never income, never expense. A business is no richer for having moved its
 * own money between its own accounts, and booking a transfer as revenue is
 * one of the classic ways a set of books overstates a year.
 *
 * **Same currency on both sides** posts in that currency: the two legs are
 * equal, so the entry balances in the transaction currency and in base.
 *
 * **Different currencies** posts in BASE currency, because there is no single
 * transaction currency to balance in — I2 asks for both, and "125,000 PKR
 * equals 450 USD" is not an identity, it is a rate. Each leg is converted at
 * its own rate, and whatever the two do not agree on is a realised FX gain or
 * loss, which is the honest answer: converting money costs something, and
 * absorbing that silently into one of the bank balances would leave neither
 * account agreeing with its statement.
 *
 * @see ACCOUNTING_RULES.md §4.9, I2
 */
final readonly class BankTransferPosting
{
    public function __construct(
        public string $fromAccountId,
        public string $toAccountId,
        /** FX gain and loss — only used when the two sides differ. */
        public string $fxAccountId,
        /** What left, in the source account's currency. */
        public string $amount,
        public string $currency,
        public string $exchangeRate,
        /** What arrived, in the destination account's currency. */
        public string $amountReceived,
        public string $destinationCurrency,
        public string $destinationExchangeRate,
        public string $baseCurrency,
        public Carbon $date,
        public string $transferId,
        public string $transferNumber,
        public ?string $reference = null,
    ) {}

    public function toDraft(): JournalDraft
    {
        return $this->currency === $this->destinationCurrency
            ? $this->sameCurrencyDraft()
            : $this->crossCurrencyDraft();
    }

    /**
     * The ordinary case: one currency, two equal legs.
     */
    private function sameCurrencyDraft(): JournalDraft
    {
        $amount = (string) BigDecimal::of($this->amount)->toScale(4, RoundingMode::HalfUp);

        return new JournalDraft(
            date: $this->date,
            currency: $this->currency,
            baseCurrency: $this->baseCurrency,
            exchangeRate: $this->exchangeRate,
            lines: [
                JournalLineDraft::debit(
                    accountId: $this->toAccountId,
                    amount: $amount,
                    memo: $this->memo(),
                ),
                JournalLineDraft::credit(
                    accountId: $this->fromAccountId,
                    amount: $amount,
                    memo: $this->memo(),
                ),
            ],
            source: ['bank_transfer', $this->transferId, 'issue'],
            memo: $this->memo(),
        );
    }

    /**
     * Two currencies, so the entry is kept in base.
     *
     * The rate on each side is the one that actually applied to that side —
     * never one rate applied to both, which would make the difference
     * disappear by construction and leave one of the two accounts wrong.
     */
    private function crossCurrencyDraft(): JournalDraft
    {
        $left = BigDecimal::of($this->amount)
            ->multipliedBy(BigDecimal::of($this->exchangeRate))
            ->toScale(4, RoundingMode::HalfUp);

        $arrived = BigDecimal::of($this->amountReceived)
            ->multipliedBy(BigDecimal::of($this->destinationExchangeRate))
            ->toScale(4, RoundingMode::HalfUp);

        $lines = [
            JournalLineDraft::debit(
                accountId: $this->toAccountId,
                amount: (string) $arrived,
                memo: $this->memo(),
            ),
            JournalLineDraft::credit(
                accountId: $this->fromAccountId,
                amount: (string) $left,
                memo: $this->memo(),
            ),
        ];

        $difference = $left->minus($arrived);

        if ($difference->isPositive()) {
            // Less arrived than left: the conversion cost us.
            $lines[] = JournalLineDraft::debit(
                accountId: $this->fxAccountId,
                amount: (string) $difference,
                memo: "FX on transfer {$this->transferNumber}",
            );
        } elseif ($difference->isNegative()) {
            $lines[] = JournalLineDraft::credit(
                accountId: $this->fxAccountId,
                amount: (string) $difference->abs(),
                memo: "FX on transfer {$this->transferNumber}",
            );
        }

        return JournalDraft::inBaseCurrency(
            date: $this->date,
            currency: $this->baseCurrency,
            lines: $lines,
            source: ['bank_transfer', $this->transferId, 'issue'],
            memo: $this->memo(),
        );
    }

    private function memo(): string
    {
        return $this->reference === null || trim($this->reference) === ''
            ? $this->transferNumber
            : "{$this->transferNumber} · {$this->reference}";
    }
}
