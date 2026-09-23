<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Data\MovementRequest;
use App\Domain\Inventory\Data\PlannedMovement;
use App\Domain\Inventory\Enums\StockMovementKind;
use App\Domain\Inventory\Exceptions\StockRefused;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\WarehouseResolver;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock moving between two of the organisation's own warehouses.
 *
 * It posts NOTHING where both warehouses feed the same inventory account,
 * which is the normal case: the business owns exactly what it owned before,
 * in a different place. Value travels with the goods at the SOURCE
 * warehouse's weighted average, so the destination receives them at that rate
 * and neither side is restated. The inventory account does not move, and I10
 * holds across the transfer without an entry being written at all.
 *
 * Completing is a two-step within one transaction: the goods leave, which
 * fixes their cost at the source average, and then arrive at exactly that
 * cost. Doing it the other way round — planning both at once — would value
 * the arrival at an average that has not happened yet.
 */
final readonly class TransferStock
{
    public function __construct(
        private TenantContext $tenant,
        private StockLedger $ledger,
        private WarehouseResolver $warehouses,
        private DocumentNumberGenerator $numbers,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function save(
        array $attributes,
        array $lines,
        ?StockTransfer $transfer = null,
        ?User $actor = null,
    ): StockTransfer {
        if ($transfer !== null && $transfer->isCompleted()) {
            throw StockRefused::transferAlreadyCompleted($transfer->number);
        }

        $from = $this->warehouses->forLine(self::optionalText($attributes, 'from_warehouse_id'));
        $to = $this->warehouses->forLine(self::optionalText($attributes, 'to_warehouse_id'));

        if ($from->getKey() === $to->getKey()) {
            throw StockRefused::transferToSameWarehouse();
        }

        $organization = $this->tenant->organization();
        $date = Carbon::parse(self::optionalText($attributes, 'transfer_date') ?? Carbon::now()->toDateString());

        return DB::transaction(function () use (
            $attributes,
            $lines,
            $transfer,
            $actor,
            $organization,
            $from,
            $to,
            $date,
        ): StockTransfer {
            $isNew = $transfer === null;

            if ($isNew) {
                $transfer = new StockTransfer;

                $transfer->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'number' => $this->numbers->next('stock_transfer', $date),
                    'status' => 'draft',
                    'created_by' => $actor?->getKey(),
                ]);
            }

            /** @var StockTransfer $transfer */
            $transfer->forceFill([
                'transfer_date' => $date->toDateString(),
                'from_warehouse_id' => $from->getKey(),
                'to_warehouse_id' => $to->getKey(),
                'notes' => self::optionalText($attributes, 'notes'),
            ])->save();

            StockTransferLine::query()->where('stock_transfer_id', $transfer->id)->delete();

            $lineNo = 1;

            foreach ($lines as $input) {
                $itemId = self::optionalText($input, 'item_id');
                $quantity = BigDecimal::of(self::optionalText($input, 'quantity') ?? '0');

                if ($itemId === null || ! $quantity->isPositive()) {
                    continue;
                }

                $item = Item::query()->find($itemId);

                if ($item === null || ! $item->is_tracked) {
                    throw StockRefused::itemIsNotTracked($item === null ? 'That item' : $item->name);
                }

                $line = new StockTransferLine;

                $line->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $transfer->organization_id,
                    'stock_transfer_id' => $transfer->id,
                    'line_no' => $lineNo++,
                    'item_id' => $item->id,
                    'quantity' => (string) $quantity->toScale(6, RoundingMode::HalfUp),
                    'value' => '0',
                ])->save();
            }

            $this->audit->record(
                action: $isNew ? 'inventory.transfer_created' : 'inventory.transfer_updated',
                subject: $transfer,
                description: sprintf(
                    '%s transfer %s — %s to %s',
                    $isNew ? 'Created' : 'Updated',
                    $transfer->number,
                    $from->name,
                    $to->name,
                ),
                new: ['number' => $transfer->number, 'lines' => count($lines)],
                actor: $actor,
            );

            return $transfer->refresh();
        });
    }

    /**
     * The goods actually move.
     */
    public function complete(StockTransfer $transfer, ?User $actor = null): StockTransfer
    {
        if ($transfer->isCompleted()) {
            throw StockRefused::transferAlreadyCompleted($transfer->number);
        }

        $lines = $transfer->lines()->with('item')->get();

        if ($lines->isEmpty()) {
            throw StockRefused::adjustmentChangesNothing($transfer->number);
        }

        $date = Carbon::parse($transfer->transfer_date->toDateString());

        return DB::transaction(function () use ($transfer, $lines, $actor, $date): StockTransfer {
            $out = [];

            foreach ($lines as $line) {
                /** @var StockTransferLine $line */
                $item = $line->item;

                if ($item === null) {
                    continue;
                }

                $out[] = new MovementRequest(
                    item: $item,
                    warehouseId: $transfer->from_warehouse_id,
                    kind: StockMovementKind::TransferOut,
                    quantity: (string) BigDecimal::of($line->quantity)->negated(),
                    occurredOn: $date,
                    sourceType: 'stock_transfer',
                    sourceId: $transfer->id,
                    sourceLineId: $line->id,
                    memo: $transfer->number,
                );
            }

            /*
             * Out first, and only then in.
             *
             * What the goods are worth is the source warehouse's average at
             * the moment they leave, and that is not known until the outbound
             * movement has been costed. Planning both together would value
             * the arrival against an average that has not happened yet.
             */
            $leaving = $this->ledger->plan($out);

            $arriving = [];
            $total = BigDecimal::zero();

            foreach ($lines as $index => $line) {
                /** @var StockTransferLine $line */
                $item = $line->item;
                $planned = $leaving[$index] ?? null;

                if ($item === null || ! $planned instanceof PlannedMovement) {
                    continue;
                }

                $value = BigDecimal::of($planned->value)->abs();
                $total = $total->plus($value);

                $arriving[] = new MovementRequest(
                    item: $item,
                    warehouseId: $transfer->to_warehouse_id,
                    kind: StockMovementKind::TransferIn,
                    quantity: $line->quantity,
                    occurredOn: $date,
                    sourceType: 'stock_transfer_in',
                    sourceId: $transfer->id,
                    sourceLineId: $line->id,
                    // Exactly what left, so the two sides cannot differ by a
                    // rounding step and the inventory account does not move.
                    totalValue: (string) $value,
                    memo: $transfer->number,
                );

                $line->forceFill([
                    'unit_cost' => $planned->unitCost,
                    'value' => (string) $value->toScale(4, RoundingMode::HalfUp),
                ])->save();
            }

            $this->ledger->commit($leaving, null, $actor);

            if ($arriving !== []) {
                $this->ledger->commit($this->ledger->plan($arriving), null, $actor);
            }

            $transfer->forceFill([
                'status' => 'completed',
                'total_value' => (string) $total->toScale(4, RoundingMode::HalfUp),
                'completed_at' => Carbon::now(),
                'completed_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'inventory.transfer_completed',
                subject: $transfer,
                description: sprintf(
                    'Completed transfer %s — %d %s worth %s, no accounting effect',
                    $transfer->number,
                    count($arriving),
                    count($arriving) === 1 ? 'line' : 'lines',
                    (string) $total->toScale(4, RoundingMode::HalfUp),
                ),
                new: [
                    'number' => $transfer->number,
                    'value' => (string) $total->toScale(4, RoundingMode::HalfUp),
                ],
                actor: $actor,
            );

            return $transfer->refresh();
        });
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
