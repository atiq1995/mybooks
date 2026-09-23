<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Why stock moved.
 *
 * The kind is not decoration: it decides where the cost of the movement comes
 * from. Stock coming IN carries a cost somebody else decided — what the bill
 * said — and stock going OUT is valued at the weighted average of what is
 * already there. Getting that backwards is how an inventory account and a
 * stock report come to disagree.
 */
enum StockMovementKind: string
{
    /** Bought in, at the cost the bill capitalised. */
    case Receipt = 'receipt';

    /** Sold and despatched, at the weighted average of the moment. */
    case Shipment = 'shipment';

    /** A customer sent goods back. */
    case ReturnIn = 'return_in';

    /** We sent goods back to a vendor. */
    case ReturnOut = 'return_out';

    /** A count, a breakage, a write-off. */
    case Adjustment = 'adjustment';

    /** The value changed; the quantity did not. */
    case Revaluation = 'revaluation';

    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    /** Brought forward from whatever the business used before. */
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Received',
            self::Shipment => 'Shipped',
            self::ReturnIn => 'Returned by a customer',
            self::ReturnOut => 'Returned to a vendor',
            self::Adjustment => 'Adjusted',
            self::Revaluation => 'Revalued',
            self::TransferIn => 'Transferred in',
            self::TransferOut => 'Transferred out',
            self::Opening => 'Opening stock',
        };
    }

    /**
     * Whether the movement brings its own cost with it.
     *
     * Inbound movements do: a receipt is worth what the bill said, and an
     * opening balance is worth what the previous system said. Outbound
     * movements do not — they are valued at what is already on the shelf,
     * which is the whole idea of a weighted average.
     */
    public function carriesItsOwnCost(): bool
    {
        return match ($this) {
            self::Receipt, self::ReturnIn, self::TransferIn, self::Opening => true,
            default => false,
        };
    }

    /**
     * Whether a movement of this kind must be TOLD its cost.
     *
     * An adjustment is the interesting case, and it is why this is a separate
     * question from the one above. Units found in a stocktake are valued at
     * what the rest of the shelf is worth — unless there is nothing on the
     * shelf, in which case there is no average to use and the person counting
     * has to say what they are worth. The ledger enforces that; the enum only
     * says which kinds can be asked.
     */
    public function acceptsAStatedCost(): bool
    {
        return $this !== self::Shipment
            && $this !== self::ReturnOut
            && $this !== self::TransferOut;
    }
}
