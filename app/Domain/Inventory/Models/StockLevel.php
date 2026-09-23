<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Item;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What is on hand, and what it is worth.
 *
 * A projection of the last movement — which would make it a second source of
 * truth, and this codebase does not permit those, were it not for what it is
 * actually for: **the row every writer locks**.
 *
 * A weighted average is read-modify-write. Two shipments of the same item at
 * the same instant, with no lock, both read the same average and both write a
 * value computed from a state that no longer exists; the inventory account
 * and the stock then differ by the overlap, silently and for ever. Locking
 * this row serialises them, exactly as the document-sequence row serialises
 * numbering.
 *
 * `verify-ledger` checks it against the movements and against the control
 * account, so a projection that drifted cannot stay hidden.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $item_id
 * @property string $warehouse_id
 * @property string $quantity
 * @property string $value
 * @property string $average_cost
 * @property string|null $last_movement_id
 */
final class StockLevel extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    // Written by the stock ledger only.
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'value' => 'string',
            'average_cost' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isEmpty(): bool
    {
        return BigDecimal::of($this->quantity)->isZero();
    }
}
