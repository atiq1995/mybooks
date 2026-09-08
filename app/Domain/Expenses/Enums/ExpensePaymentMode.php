<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Enums;

/**
 * Whose money was spent.
 *
 * This decides the credit side of §4.8's entry, and it is the one thing about
 * an expense that cannot be inferred from anything else:
 *
 *   Company     — paid from a company bank or cash account. Cr Bank.
 *   Reimbursable — somebody paid out of their own pocket. Cr Employee
 *                  Reimbursements, and we owe them until it is paid.
 *
 * Storing it as a column rather than inferring it from "was an account
 * chosen" is what lets a check constraint insist the two agree — so a
 * half-filled form cannot produce an expense the posting rule has to guess
 * about.
 *
 * @see ACCOUNTING_RULES.md §4.8
 */
enum ExpensePaymentMode: string
{
    case Company = 'company';
    case Reimbursable = 'reimbursable';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Paid by the company',
            self::Reimbursable => 'To be reimbursed',
        };
    }

    public function isReimbursable(): bool
    {
        return $this === self::Reimbursable;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
