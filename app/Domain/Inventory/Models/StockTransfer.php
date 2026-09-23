<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Stock moving between two of the organisation's own warehouses.
 *
 * It posts NOTHING where both warehouses share an inventory account, which is
 * the normal case: the business owns exactly what it owned before, in a
 * different place. Value travels with the goods at the source warehouse's
 * weighted average, so neither side is restated and the inventory account
 * does not move.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property Carbon $transfer_date
 * @property string $from_warehouse_id
 * @property string $to_warehouse_id
 * @property string $status
 * @property string $total_value
 * @property string|null $notes
 * @property Carbon|null $completed_at
 */
final class StockTransfer extends Model
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
            'transfer_date' => 'date',
            'completed_at' => 'datetime',
            'total_value' => 'string',
        ];
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
