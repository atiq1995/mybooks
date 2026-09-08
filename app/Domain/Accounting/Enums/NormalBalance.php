<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Which side of an account increases it.
 *
 * An asset has a debit normal balance and grows when debited. A contra-revenue
 * account such as trade discounts sits under income but has a DEBIT normal
 * balance — which is exactly why this is stored per account rather than
 * inferred from the type.
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Credit',
        };
    }

    /**
     * The signed balance of an account, from its summed debits and credits.
     *
     * Positive means the account holds what its type expects. A debit-normal
     * account with more credits than debits returns a negative balance, which
     * is how an overdrawn bank account or a customer in credit shows up —
     * rather than being hidden behind an absolute value.
     *
     * Decimal strings in, decimal string out. No floats anywhere.
     *
     * Always at the money scale, whatever the inputs looked like. An empty
     * account sums to a bare `0` in PostgreSQL while a used one sums to
     * `0.0000`, and returning both would mean a caller comparing two balances
     * as strings — which is the only safe way to compare money — sees them
     * differ when they are the same figure.
     */
    public function signedBalance(string $debits, string $credits): string
    {
        $debit = BigDecimal::of($debits);
        $credit = BigDecimal::of($credits);

        $balance = match ($this) {
            self::Debit => $debit->minus($credit),
            self::Credit => $credit->minus($debit),
        };

        return (string) $balance->toScale(4, RoundingMode::HalfUp);
    }

    public function opposite(): self
    {
        return match ($this) {
            self::Debit => self::Credit,
            self::Credit => self::Debit,
        };
    }
}
