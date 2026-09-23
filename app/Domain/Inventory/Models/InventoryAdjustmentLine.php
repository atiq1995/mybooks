<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Item;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item on an adjustment.
 *
 * Both the counted figure and the change are kept. A stocktake records what
 * somebody saw on the shelf and the system works out the difference; a
 * write-off records the difference directly. Storing only the difference
 * would lose the part anybody auditing the adjustment actually wants — what
 * was counted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $inventory_adjustment_id
 * @property int $line_no
 * @property string $item_id
 * @property string|null $counted_quantity
 * @property string $quantity_change
 * @property string $value_change
 * @property string|null $unit_cost
 * @property string|null $memo
 */
final class InventoryAdjustmentLine extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'counted_quantity' => 'string',
            'quantity_change' => 'string',
            'value_change' => 'string',
            'unit_cost' => 'string',
        ];
    }

    /**
     * @return BelongsTo<InventoryAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'inventory_adjustment_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
