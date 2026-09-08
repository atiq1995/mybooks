<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

/**
 * No rate exists for a pair on or before a date.
 *
 * Deliberately fatal rather than defaulted. A missing rate means somebody has
 * to say what the rate was; inventing one produces a figure that balances,
 * looks right, and is wrong by however far the currencies have moved.
 *
 * @see ACCOUNTING_RULES.md §8
 */
final class MissingExchangeRate extends RuntimeException
{
    public static function forPair(string $from, string $to, string $date): self
    {
        return new self(
            "No exchange rate for {$from} to {$to} on or before {$date}. ".
            'Record the rate for that date before posting — a rate cannot be guessed.'
        );
    }
}
