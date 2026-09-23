<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Exceptions\StockRefused;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Models\InventoryAdjustmentLine;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\WarehouseResolver;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write down an adjustment. It changes nothing until it is approved.
 *
 * A line can be expressed either way round, and both are kept: a stocktake
 * records what was COUNTED and the difference is worked out here; a write-off
 * records the DIFFERENCE directly. Storing only the difference would lose the
 * part anybody auditing the adjustment actually wants — what was on the
 * shelf.
 *
 * Nothing is posted and no stock moves. That happens on approval, which is a
 * separate act by a person with the permission to make it.
 */
final readonly class SaveInventoryAdjustment
{
    public function __construct(
        private TenantContext $tenant,
        private WarehouseResolver $warehouses,
        private DocumentNumberGenerator $numbers,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function handle(
        array $attributes,
        array $lines,
        ?InventoryAdjustment $adjustment = null,
        ?User $actor = null,
    ): InventoryAdjustment {
        if ($adjustment !== null && ! $adjustment->isEditable()) {
            throw StockRefused::adjustmentNotEditable($adjustment->number, $adjustment->statusLabel());
        }

        $organization = $this->tenant->organization();

        $warehouse = isset($attributes['warehouse_id']) && is_string($attributes['warehouse_id'])
            ? $this->warehouses->forLine($attributes['warehouse_id'])
            : $this->warehouses->default();

        $date = Carbon::parse(self::text($attributes, 'adjustment_date') ?: Carbon::now()->toDateString());

        return DB::transaction(function () use (
            $attributes,
            $lines,
            $adjustment,
            $actor,
            $organization,
            $warehouse,
            $date,
        ): InventoryAdjustment {
            $isNew = $adjustment === null;

            if ($isNew) {
                $adjustment = new InventoryAdjustment;

                $adjustment->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->id,
                    'number' => $this->numbers->next('inventory_adjustment', $date),
                    'status' => 'draft',
                    'created_by' => $actor?->getKey(),
                ]);
            }

            /** @var InventoryAdjustment $adjustment */
            $adjustment->forceFill([
                'adjustment_date' => $date->toDateString(),
                'warehouse_id' => $warehouse->id,
                'kind' => self::text($attributes, 'kind') ?: 'quantity',
                'account_id' => self::text($attributes, 'account_id'),
                'reason' => self::text($attributes, 'reason'),
                'notes' => self::optionalText($attributes, 'notes'),
            ])->save();

            $this->replaceLines($adjustment, $lines, $warehouse->id);

            $this->audit->record(
                action: $isNew ? 'inventory.adjustment_created' : 'inventory.adjustment_updated',
                subject: $adjustment,
                description: sprintf(
                    '%s adjustment %s at %s — %s',
                    $isNew ? 'Created' : 'Updated',
                    $adjustment->number,
                    $warehouse->name,
                    $adjustment->reason,
                ),
                new: [
                    'number' => $adjustment->number,
                    'kind' => $adjustment->kind,
                    'lines' => count($lines),
                    'reason' => $adjustment->reason,
                ],
                actor: $actor,
            );

            return $adjustment->refresh();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(InventoryAdjustment $adjustment, array $lines, string $warehouseId): void
    {
        InventoryAdjustmentLine::query()
            ->where('inventory_adjustment_id', $adjustment->id)
            ->delete();

        $lineNo = 1;

        foreach ($lines as $input) {
            $itemId = self::optionalText($input, 'item_id');

            if ($itemId === null) {
                continue;
            }

            $item = Item::query()->find($itemId);

            if ($item === null || ! $item->is_tracked) {
                throw StockRefused::itemIsNotTracked($item === null ? 'That item' : $item->name);
            }

            $counted = self::optionalText($input, 'counted_quantity');

            /*
             * A counted figure beats a stated difference.
             *
             * Somebody who typed what is on the shelf has given the more
             * reliable of the two facts, and working the difference out from
             * it here means the difference cannot disagree with the count.
             */
            $change = $counted !== null
                ? BigDecimal::of($counted)->minus(BigDecimal::of($this->onHand($item->id, $warehouseId)))
                : BigDecimal::of(self::optionalText($input, 'quantity_change') ?? '0');

            $valueChange = BigDecimal::of(self::optionalText($input, 'value_change') ?? '0');

            if ($change->isZero() && $valueChange->isZero()) {
                continue;
            }

            $line = new InventoryAdjustmentLine;

            $line->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $adjustment->organization_id,
                'inventory_adjustment_id' => $adjustment->id,
                'line_no' => $lineNo++,
                'item_id' => $item->id,
                'counted_quantity' => $counted,
                'quantity_change' => (string) $change->toScale(6, RoundingMode::HalfUp),
                'value_change' => (string) $valueChange->toScale(4, RoundingMode::HalfUp),
                'unit_cost' => self::optionalText($input, 'unit_cost'),
                'memo' => self::optionalText($input, 'memo'),
            ])->save();
        }
    }

    private function onHand(string $itemId, string $warehouseId): string
    {
        $level = StockLevel::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        return $level === null ? '0' : $level->quantity;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function text(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function optionalText(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
