<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * One side of an unposted journal entry.
 *
 * A line is a debit or a credit — never both, never neither, never negative.
 * That is enforced here as well as by a database CHECK constraint, because
 * "negative debit" is a spelling of "credit" and permitting both spellings
 * makes every report ambiguous.
 *
 * @see ACCOUNTING_RULES.md I3
 */
final readonly class JournalLineDraft
{
    private function __construct(
        public string $accountId,
        /** Decimal string; zero when this is a credit line. */
        public string $debitAmount,
        /** Decimal string; zero when this is a debit line. */
        public string $creditAmount,
        public ?string $memo = null,
        public ?string $contactId = null,
        public ?string $itemId = null,
        public ?string $projectId = null,
        public ?string $warehouseId = null,
        public ?string $taxId = null,
        /**
         * Base-currency amount, when it cannot be derived by multiplying by
         * the entry's rate — a settlement at a different rate, for instance.
         * Null means "convert at the entry rate".
         */
        public ?string $baseAmountOverride = null,
    ) {}

    /**
     * $amount is a decimal string. Not typed `numeric-string`: most amounts
     * arrive from a numeric database column, which static analysis widens to
     * plain `string`, and annotating the ideal here only moves the noise to
     * every call site. {@see assertPositive()} rejects anything non-numeric at
     * runtime, which is where it matters.
     */
    public static function debit(
        string $accountId,
        string $amount,
        ?string $memo = null,
        ?string $contactId = null,
        ?string $itemId = null,
        ?string $taxId = null,
        ?string $baseAmountOverride = null,
    ): self {
        self::assertPositive($amount, 'debit');

        return new self(
            accountId: $accountId,
            debitAmount: (string) BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp),
            creditAmount: '0',
            memo: $memo,
            contactId: $contactId,
            itemId: $itemId,
            taxId: $taxId,
            baseAmountOverride: $baseAmountOverride,
        );
    }

    /**
     * $amount is a decimal string. See {@see debit()} for why it is not typed
     * `numeric-string`.
     */
    public static function credit(
        string $accountId,
        string $amount,
        ?string $memo = null,
        ?string $contactId = null,
        ?string $itemId = null,
        ?string $taxId = null,
        ?string $baseAmountOverride = null,
    ): self {
        self::assertPositive($amount, 'credit');

        return new self(
            accountId: $accountId,
            debitAmount: '0',
            creditAmount: (string) BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp),
            memo: $memo,
            contactId: $contactId,
            itemId: $itemId,
            taxId: $taxId,
            baseAmountOverride: $baseAmountOverride,
        );
    }

    public function isDebit(): bool
    {
        return BigDecimal::of($this->debitAmount)->isPositive();
    }

    public function debitValue(): BigDecimal
    {
        return BigDecimal::of($this->debitAmount);
    }

    public function creditValue(): BigDecimal
    {
        return BigDecimal::of($this->creditAmount);
    }

    public function debitBaseValue(string $exchangeRate): BigDecimal
    {
        return $this->isDebit() ? $this->convert($this->debitValue(), $exchangeRate) : BigDecimal::zero();
    }

    public function creditBaseValue(string $exchangeRate): BigDecimal
    {
        return $this->isDebit() ? BigDecimal::zero() : $this->convert($this->creditValue(), $exchangeRate);
    }

    /**
     * The reverse of this line — a debit becomes a credit of the same amount.
     * Used to build a reversing entry.
     */
    public function reversed(): self
    {
        return new self(
            accountId: $this->accountId,
            debitAmount: $this->creditAmount,
            creditAmount: $this->debitAmount,
            memo: $this->memo,
            contactId: $this->contactId,
            itemId: $this->itemId,
            projectId: $this->projectId,
            warehouseId: $this->warehouseId,
            taxId: $this->taxId,
            baseAmountOverride: $this->baseAmountOverride,
        );
    }

    private function convert(BigDecimal $amount, string $exchangeRate): BigDecimal
    {
        if ($this->baseAmountOverride !== null) {
            return BigDecimal::of($this->baseAmountOverride)->toScale(4, RoundingMode::HalfUp);
        }

        return $amount
            ->multipliedBy(BigDecimal::of($exchangeRate))
            ->toScale(4, RoundingMode::HalfUp);
    }

    private static function assertPositive(string $amount, string $side): void
    {
        $value = BigDecimal::of($amount);

        if ($value->isNegative()) {
            throw new InvalidArgumentException(
                "A {$side} cannot be negative ({$amount}). A negative debit is a credit — ".
                'use the other constructor. See ACCOUNTING_RULES.md I3.'
            );
        }

        if ($value->isZero()) {
            throw new InvalidArgumentException(
                "A {$side} of zero carries no information and would make the entry ambiguous."
            );
        }
    }
}
