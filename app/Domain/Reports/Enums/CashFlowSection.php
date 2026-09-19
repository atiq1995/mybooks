<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

/**
 * The three sections of a cash flow statement, plus two that are not sections.
 *
 * `Profit` is where the indirect method starts rather than a section of its
 * own, and `Cash` is the thing being explained — the statement's whole job is
 * to account for the movement in it, so it can never also be an adjustment to
 * itself.
 */
enum CashFlowSection: string
{
    case Profit = 'profit';
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Profit => 'Profit for the period',
            self::Operating => 'Operating activities',
            self::Investing => 'Investing activities',
            self::Financing => 'Financing activities',
            self::Cash => 'Cash and bank',
        };
    }
}
