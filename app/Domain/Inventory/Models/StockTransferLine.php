<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Item;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item on a transfer.
 *
 * `unit_cost` and `value` are empty until the transfer completes: what the
 * goods are worth is the source warehouse's weighted average at the moment
 * they leave, and that is not known while the transfer is still a plan.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $stock_transfer_id
 * @property int $line_no
 * @property string $item_id
 * @property string $quantity
 * @property string|null $unit_cost
 * @property string $value
 */
final class StockTransferLine extends Model
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
            'quantity' => 'string',
            'unit_cost' => 'string',
            'value' => 'string',
        ];
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
