<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * Stock could not do what was asked of it.
 *
 * Every message names the item and the figures involved, because the person
 * reading it is usually holding a delivery note and needs to know which of
 * the two is wrong.
 */
final class StockRefused extends RuntimeException
{
    public static function notEnoughStock(
        string $item,
        string $warehouse,
        string $wanted,
        string $available,
    ): self {
        return new self(
            "There is not enough {$item} at {$warehouse}: {$wanted} is needed and {$available} ".
            'is on hand. Stock cannot go negative — goods that are not there have no cost to '.
            'assign, and the inventory account would carry a credit balance no balance sheet '.
            'can show. Receive the purchase first, or adjust the count if the shelf disagrees '.
            'with the system.'
        );
    }

    public static function itemIsNotTracked(string $item): self
    {
        return new self(
            "{$item} is not stock-tracked, so it has no quantity to move. Turn on tracking on ".
            'the item — it needs an inventory account — and record an opening balance.'
        );
    }

    public static function needsACost(string $item): self
    {
        return new self(
            "There is no {$item} on hand, so there is no average cost to value this by. Say ".
            'what a unit is worth.'
        );
    }

    /**
     * The ledger moved a value the shelf cannot move with it.
     *
     * Reached when undoing something: a vendor credit or a void that would
     * take more value off the shelf than is on it, or empty the shelf and
     * leave value behind. Both mean the goods have already moved on — sold,
     * or written down — and the document can no longer simply be unwound.
     */
    public static function valueDoesNotFit(
        string $item,
        string $warehouse,
        string $wanted,
        string $available,
    ): self {
        return new self(
            "Undoing this would take {$wanted} of value off {$item} at {$warehouse}, which holds ".
            "{$available}. The goods have moved on since — sold, written down, or transferred — ".
            'so the original cost is no longer sitting there to give back. Record a credit note '.
            'or a stock adjustment instead, which states what is happening now rather than '.
            'pretending the original document never happened.'
        );
    }

    public static function nothingToRevalue(string $item, string $warehouse): self
    {
        return new self(
            "There is no {$item} at {$warehouse} to revalue. Value with nothing under it is not ".
            'something the shelf can hold — receive or adjust the quantity in first.'
        );
    }

    public static function writeDownTooLarge(
        string $item,
        string $warehouse,
        string $wanted,
        string $available,
    ): self {
        return new self(
            "{$item} at {$warehouse} is worth {$available}, so it cannot be written down by ".
            "{$wanted}. The most that can be written off is what is there; anything more would ".
            'put the inventory account into credit.'
        );
    }

    public static function warehouseUnusable(string $warehouse): self
    {
        return new self("{$warehouse} is archived or inactive, so stock cannot move through it.");
    }

    public static function noWarehouse(): self
    {
        return new self(
            'This organisation has no warehouse to hold stock in. Add one before tracking '.
            'anything.'
        );
    }

    public static function alreadyShipped(string $document): self
    {
        return new self(
            "{$document} has already been despatched. Its cost of sales posted then, at the ".
            'weighted average of that moment, and posting it again would charge the cost twice.'
        );
    }

    public static function nothingToShip(string $document): self
    {
        return new self("{$document} has no stock-tracked lines, so there is nothing to despatch.");
    }

    public static function adjustmentNotEditable(string $number, string $status): self
    {
        return new self(
            "Adjustment {$number} is {$status} and can no longer be edited. Record another ".
            'adjustment — the stock ledger is append-only, exactly as the journal is.'
        );
    }

    public static function adjustmentAlreadyApproved(string $number): self
    {
        return new self("Adjustment {$number} has already been approved.");
    }

    public static function adjustmentChangesNothing(string $number): self
    {
        return new self(
            "Adjustment {$number} has no lines that change anything. An adjustment that moves ".
            'no quantity and no value is a note, not an adjustment.'
        );
    }

    public static function transferToSameWarehouse(): self
    {
        return new self(
            'A transfer needs two different warehouses. Moving stock to where it already is '.
            'writes a pair of movements that cancel and leaves a history nobody can read.'
        );
    }

    public static function transferAlreadyCompleted(string $number): self
    {
        return new self("Transfer {$number} has already been completed.");
    }

    public static function cannotVoidApproved(string $number): self
    {
        return new self(
            "Adjustment {$number} has posted. Void reverses it in the ledger and puts the ".
            'stock back — it does not delete anything.'
        );
    }
}
