<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Enums\StockMovementKind;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One movement of stock, and the position it left behind.
 *
 * Append-only, exactly as a journal line is, and for a stronger reason: a
 * weighted average is a CHAIN. Every movement's running balance is computed
 * from the one before it, so editing one in the middle would leave every
 * later figure derived from a state that no longer exists — and nothing in
 * the data would say so. A database trigger refuses it; this model refuses it
 * earlier, so the message is a sentence rather than a constraint name.
 *
 * Corrections are new movements. That is an inventory adjustment.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $item_id
 * @property string $warehouse_id
 * @property Carbon $occurred_on
 * @property StockMovementKind $kind
 * @property string $quantity
 * @property string $unit_cost
 * @property string $value
 * @property string $quantity_after
 * @property string $value_after
 * @property string $unit_cost_after
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $source_line_id
 * @property string|null $journal_entry_id
 * @property string|null $inventory_account_id
 * @property string|null $memo
 */
final class StockMovement extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    // Written by the stock ledger only.
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException(
            'The stock ledger is append-only: a movement cannot be edited. Record an '.
            'adjustment instead — the running average of every later movement was computed '.
            'from this one.'
        ));

        self::deleting(fn (): never => throw new LogicException(
            'The stock ledger is append-only: a movement cannot be deleted. Record an '.
            'adjustment instead.'
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'kind' => StockMovementKind::class,
            // Decimal strings, like every other quantity and money column.
            'quantity' => 'string',
            'unit_cost' => 'string',
            'value' => 'string',
            'quantity_after' => 'string',
            'value_after' => 'string',
            'unit_cost_after' => 'string',
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

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isInbound(): bool
    {
        return BigDecimal::of($this->quantity)->isPositive();
    }

    /** The value as a positive figure, which is how a report reads it. */
    public function absoluteValue(): string
    {
        return (string) BigDecimal::of($this->value)->abs();
    }
}
