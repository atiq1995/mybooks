<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Data;

use App\Domain\Inventory\Enums\StockMovementKind;
use Illuminate\Support\Carbon;

/**
 * A movement that has been costed but not yet written.
 *
 * This exists because of an ordering problem with only bad alternatives.
 *
 * A shipment's journal entry needs the COST, and the cost is not known until
 * the weighted average has been read under a lock. But the movement wants to
 * record WHICH entry carried its value — and the stock ledger is append-only,
 * so the row cannot be written first and updated afterwards.
 *
 * So costing and writing are two steps: {@see StockLedger::plan()} takes the
 * locks and works out every figure, the caller posts the entry from those
 * figures, and {@see StockLedger::commit()} writes the movements with the
 * entry's id on them. The locks are held across both, because both happen
 * inside the caller's transaction — which is the same reason the whole thing
 * is safe under concurrency.
 */
final readonly class PlannedMovement
{
    public function __construct(
        public string $itemId,
        public string $itemName,
        public string $warehouseId,
        public StockMovementKind $kind,
        public Carbon $occurredOn,
        /** Signed decimal string: positive in, negative out. */
        public string $quantity,
        /** Cost per unit applied to this movement. */
        public string $unitCost,
        /** Signed decimal string: the change in inventory value. */
        public string $value,
        public string $quantityAfter,
        public string $valueAfter,
        public string $unitCostAfter,
        public string $sourceType,
        public ?string $sourceId,
        public ?string $sourceLineId,
        /** Where the other side of the value goes, when there is one. */
        public ?string $inventoryAccountId,
        public ?string $memo = null,
    ) {}

    /** The value as a positive figure, which is what a journal line wants. */
    public function absoluteValue(): string
    {
        return ltrim($this->value, '-');
    }
}
