<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/**
 * An item could not be changed the way it was asked to be.
 *
 * Most of an item is freely editable — a price, a description, a tax — and
 * deliberately so: document lines copy what they were created with, so an
 * edit restates nothing already issued. The exceptions are the fields that
 * past postings still depend on.
 */
final class ItemRefused extends RuntimeException
{
    /**
     * The account already carries value that no stock accounts for.
     *
     * The moment an item names an account, `verify-ledger` starts reconciling
     * that account against the stock attributed to it — including everything
     * posted to it BEFORE, which nothing in inventory can now explain. So the
     * account has to be clean at the moment it becomes an inventory account,
     * or it starts its life as an invariant breach nobody can close.
     *
     * @see ACCOUNTING_RULES.md I10
     */
    public static function accountAlreadyHasOtherPostings(string $account, string $balance): self
    {
        return new self(
            "{$account} already carries {$balance} that no stock movement accounts for, so it "
            .'cannot become an inventory account. From the moment an item names it, that '
            .'balance is checked against the goods on the shelf on every date — and there are '
            .'no goods behind this. Use an account of its own for stock, or move the existing '
            .'balance out with a journal entry first.'
        );
    }

    /**
     * Its stock accounting cannot be rewritten under the movements.
     *
     * @see ACCOUNTING_RULES.md I10
     */
    public static function stockHasAlreadyMoved(string $item): self
    {
        return new self(
            "{$item} is already committed to an inventory account — it has moved stock, or it ".
            'sits on a bill that froze the account when it was saved — so its inventory '.
            'account and whether it is tracked can no longer be changed. Those two decide '.
            'which account the goods on the shelf are the other side of; changing them now '.
            'would leave one account holding value with no stock behind it and another '.
            'holding stock it was never debited for, and the stock valuation would stop '.
            'agreeing with the balance sheet. Archive this item and add a new one, or move '.
            'the value with a journal entry that says what is happening.'
        );
    }
}
