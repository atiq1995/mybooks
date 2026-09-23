<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Data;

use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Enums\StockMovementKind;
use Illuminate\Support\Carbon;

/**
 * "Move this much of this item, here, for this reason."
 *
 * What a caller asks for. What it normally does NOT say is what the movement
 * is worth when stock is going out — that is the weighted average's job, and
 * letting a caller state it would be letting a caller decide the cost of
 * sales. The two exceptions are spelled out on `$totalValue`, and both exist
 * because the ledger had already decided the figure.
 */
final readonly class MovementRequest
{
    public function __construct(
        public Item $item,
        public string $warehouseId,
        public StockMovementKind $kind,
        /** Signed decimal string: positive in, negative out. */
        public string $quantity,
        public Carbon $occurredOn,
        public string $sourceType,
        public ?string $sourceId = null,
        public ?string $sourceLineId = null,
        /**
         * Cost per unit, for movements that bring their own — a receipt, an
         * opening balance, a stocktake that found units where there were
         * none. Ignored for anything going out.
         */
        public ?string $unitCost = null,
        /**
         * Total value, where the caller knows the value rather than the rate:
         * a bill line capitalises a cost that already includes blocked tax
         * and a share of a document discount, and dividing it back out per
         * unit would round twice.
         *
         * It applies to stock going OUT as well, and that is not a loophole
         * in the rule above — it is the same rule. Where the LEDGER has
         * already decided the value of an outgoing movement, the shelf has to
         * move by that figure or I10 breaks: a vendor credit credits the
         * inventory account by the credit's own cost, and a void reverses the
         * exact value the original movement carried. Letting the average
         * decide in those two cases would put the two halves of the invariant
         * on different numbers. Everywhere else it is left null, and the
         * average decides.
         */
        public ?string $totalValue = null,
        /**
         * The inventory account the LEDGER used for this movement.
         *
         * Stated by the caller where the caller knows it, because the item's
         * own `inventory_account_id` is not always the account that was
         * debited. A bill line freezes its account when the bill is saved; if
         * the item's account is edited before the bill is approved, the entry
         * debits the frozen one and the item now names another. Re-deriving
         * it here would stamp the movement with an account the entry never
         * touched, and I10 would then show one account holding value with no
         * stock and another holding stock the ledger never debited.
         *
         * Null where the caller builds its entry FROM the movements — a
         * shipment or an adjustment — because there the item's account is by
         * definition the one that gets posted.
         */
        public ?string $inventoryAccountId = null,
        public ?string $memo = null,
    ) {}
}
