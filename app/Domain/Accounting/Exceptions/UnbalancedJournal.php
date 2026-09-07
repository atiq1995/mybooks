<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

/**
 * A journal entry that does not satisfy the fundamental invariant.
 *
 * This is never a user error to be shown in a form — it means a posting rule
 * produced an entry that does not balance, which is a bug in the rule. The
 * messages are written for whoever has to fix it.
 *
 * @see ACCOUNTING_RULES.md I1, I2
 */
final class UnbalancedJournal extends RuntimeException
{
    public static function inTransactionCurrency(string $debits, string $credits, string $currency): self
    {
        return new self(
            "Journal does not balance in {$currency}: debits {$debits}, credits {$credits}. ".
            'Every posted entry must satisfy SUM(debit) = SUM(credit).'
        );
    }

    public static function inBaseCurrency(string $debits, string $credits, string $currency): self
    {
        return new self(
            "Journal does not balance in base currency {$currency}: debits {$debits}, ".
            "credits {$credits}. An entry must balance in BOTH the transaction currency and ".
            'the base currency, or the balance sheet will not balance once converted.'
        );
    }

    public static function empty(): self
    {
        return new self('A journal entry needs at least two lines. This one has none.');
    }

    public static function zeroValue(): self
    {
        return new self(
            'A journal entry totalling zero is not a transaction. It would balance trivially '.
            'while recording nothing.'
        );
    }
}
