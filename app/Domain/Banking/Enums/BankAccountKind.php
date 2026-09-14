<?php

declare(strict_types=1);

namespace App\Domain\Banking\Enums;

/**
 * What kind of account the money sits in.
 *
 * This is not a cosmetic label. A credit card is a LIABILITY — money owed,
 * not money held — so the sign of everything on it is the other way round,
 * and the chart of accounts has to agree. {@see requiredAccountType()} is
 * where that agreement is enforced.
 */
enum BankAccountKind: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case CreditCard = 'credit_card';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank account',
            self::Cash => 'Cash account',
            self::CreditCard => 'Credit card',
        };
    }

    /**
     * The ledger account type this kind must be attached to.
     *
     * Returned as the enum's backing string so callers can compare without
     * importing the accounting enum into a banking form.
     */
    public function requiredAccountType(): string
    {
        return match ($this) {
            self::Bank, self::Cash => 'asset',
            self::CreditCard => 'liability',
        };
    }

    /**
     * Whether a statement can be imported for this kind.
     *
     * Cash has no statement: nobody issues one for the tin in the drawer, and
     * offering an import for it would invite somebody to reconcile petty cash
     * against a file they typed themselves.
     */
    public function hasStatements(): bool
    {
        return $this !== self::Cash;
    }
}
