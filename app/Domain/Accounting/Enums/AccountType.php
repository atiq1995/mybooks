<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * The five root account types.
 *
 * The normal balance here is the DEFAULT for the type. Contra accounts invert
 * it — trade discounts are an income account with a debit normal balance — so
 * each account row stores its own, and this is only the starting point.
 *
 * @see ACCOUNTING_RULES.md §3
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expense',
        };
    }

    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Income => NormalBalance::Credit,
        };
    }

    /** Which statement this type appears on. */
    public function statement(): string
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => 'balance_sheet',
            self::Income, self::Expense => 'profit_and_loss',
        };
    }

    /**
     * Whether a balance of this type survives a year-end close.
     *
     * Income and expense do not: they close to retained earnings, which is
     * what makes the next year start from zero.
     */
    public function carriesForward(): bool
    {
        return $this->statement() === 'balance_sheet';
    }

    /** Report order: assets, liabilities, equity, income, expense. */
    public function order(): int
    {
        return match ($this) {
            self::Asset => 1,
            self::Liability => 2,
            self::Equity => 3,
            self::Income => 4,
            self::Expense => 5,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
