<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What stock is worth, now and at any date in the past.
 *
 * "At any date" is the part that decides the shape of the query. Today's
 * figure is the projection, which is why `stock_levels` exists; a figure at a
 * past date is summed from the movements up to it, because the running
 * balances on the movements are in write order and write order is not date
 * order — see {@see asAt()}, where getting that wrong is the difference
 * between a balance sheet that ties to the inventory account and one that
 * does not.
 *
 * Nothing here writes. Every figure it returns was decided by
 * {@see StockLedger} under a lock.
 */
final readonly class StockValuation
{
    public function __construct(
        private TenantContext $tenant,
    ) {}

    /**
     * Stock as it stands now, from the projection.
     *
     * @return list<array<string, mixed>>
     */
    public function onHand(?string $warehouseId = null, bool $includeEmpty = false): array
    {
        $rows = DB::table('stock_levels as sl')
            ->join('items as i', 'i.id', '=', 'sl.item_id')
            ->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')
            ->where('sl.organization_id', $this->organizationId())
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('sl.warehouse_id', $warehouseId))
            ->when(! $includeEmpty, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('sl.quantity', '<>', 0)
                ->orWhere('sl.value', '<>', 0)))
            ->orderBy('i.name')
            ->orderBy('w.code')
            ->get([
                'sl.item_id',
                'sl.warehouse_id',
                'i.sku',
                'i.name as item_name',
                'i.unit',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'sl.quantity',
                'sl.value',
                'sl.average_cost',
            ])
            ->all();

        /** @var list<object{item_id: string, warehouse_id: string, sku: ?string, item_name: string, unit: ?string, warehouse_code: string, warehouse_name: string, quantity: string, value: string, average_cost: string}> $rows */
        return array_values(array_map(
            static fn (object $row): array => [
                'item_id' => $row->item_id,
                'warehouse_id' => $row->warehouse_id,
                'sku' => $row->sku,
                'item_name' => $row->item_name,
                'unit' => $row->unit,
                'warehouse' => $row->warehouse_code.' · '.$row->warehouse_name,
                'quantity' => (string) $row->quantity,
                'value' => (string) $row->value,
                'average_cost' => (string) $row->average_cost,
            ],
            $rows,
        ));
    }

    /**
     * Stock as it stood at a date.
     *
     * Summed from the movements up to that date, per item and warehouse — not
     * read off the last one's running balance.
     *
     * That distinction is the whole correctness of this method. `value_after`
     * records the state after N WRITES, and writes are not in date order: a
     * bill entered late is dated when it happened, so its movement carries a
     * running balance that includes everything written before it, including
     * movements dated after it. Reading the latest-by-date row therefore
     * returns a figure that was never true on that date, and the balance
     * sheet it feeds disagrees with the inventory account it is supposed to
     * equal.
     *
     * Summing signed values is order-independent, and it is the same
     * arithmetic the ledger does to reach the control account's balance —
     * which is what makes I10 a comparison of like with like rather than a
     * coincidence. The cost is a scan instead of a lookup; the index on
     * (organization, item, warehouse, occurred_on) covers it.
     *
     * An item with no movement by then has no row, which is the honest
     * answer: it did not exist on the shelf.
     *
     * @return list<array<string, mixed>>
     */
    public function asAt(Carbon $asOf, ?string $warehouseId = null): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT m.item_id,
                   m.warehouse_id,
                   i.sku,
                   i.name  AS item_name,
                   i.unit,
                   w.code  AS warehouse_code,
                   w.name  AS warehouse_name,
                   SUM(m.quantity) AS quantity,
                   SUM(m.value)    AS value,
                   CASE WHEN SUM(m.quantity) = 0
                        THEN 0
                        ELSE ROUND(SUM(m.value) / SUM(m.quantity), 10)
                   END AS average_cost
              FROM stock_movements m
              JOIN items i      ON i.id = m.item_id
              JOIN warehouses w ON w.id = m.warehouse_id
             WHERE m.organization_id = ?
               AND m.occurred_on <= ?
               -- Cast both sides: PostgreSQL cannot infer the type of a
               -- bare parameter in `? IS NULL`, and refuses the statement.
               AND (?::uuid IS NULL OR m.warehouse_id = ?::uuid)
             GROUP BY m.item_id,
                      m.warehouse_id,
                      i.sku,
                      i.name,
                      i.unit,
                      w.code,
                      w.name
        SQL, [
            $this->organizationId(),
            $asOf->toDateString(),
            $warehouseId,
            $warehouseId,
        ]);

        /** @var list<object{item_id: string, warehouse_id: string, sku: ?string, item_name: string, unit: ?string, warehouse_code: string, warehouse_name: string, quantity: string, value: string, average_cost: string}> $rows */
        $valued = [];

        foreach ($rows as $row) {
            // A shelf emptied before the date is not stock, it is history.
            if (BigDecimal::of((string) $row->quantity)->isZero()
                && BigDecimal::of((string) $row->value)->isZero()) {
                continue;
            }

            $valued[] = [
                'item_id' => $row->item_id,
                'warehouse_id' => $row->warehouse_id,
                'sku' => $row->sku,
                'item_name' => $row->item_name,
                'unit' => $row->unit,
                'warehouse' => $row->warehouse_code.' · '.$row->warehouse_name,
                'quantity' => (string) $row->quantity,
                'value' => (string) $row->value,
                'average_cost' => (string) $row->average_cost,
            ];
        }

        usort(
            $valued,
            static fn (array $a, array $b): int => [$a['item_name'], $a['warehouse']]
                <=> [$b['item_name'], $b['warehouse']],
        );

        return $valued;
    }

    /**
     * The total value of everything on hand at a date.
     */
    public function totalAsAt(Carbon $asOf): string
    {
        $total = BigDecimal::zero();

        foreach ($this->asAt($asOf) as $row) {
            $value = $row['value'];
            $total = $total->plus(BigDecimal::of(is_string($value) ? $value : '0'));
        }

        return (string) $total->toScale(4, RoundingMode::HalfUp);
    }

    /**
     * Everything that happened to one item, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function movementsFor(
        string $itemId,
        ?string $warehouseId = null,
        int $limit = 200,
    ): array {
        $rows = DB::table('stock_movements as m')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'm.journal_entry_id')
            ->where('m.organization_id', $this->organizationId())
            ->where('m.item_id', $itemId)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('m.warehouse_id', $warehouseId))
            ->orderByDesc('m.occurred_on')
            ->orderByDesc('m.created_at')
            ->limit($limit)
            ->get([
                'm.id',
                'm.occurred_on',
                'm.kind',
                'm.quantity',
                'm.unit_cost',
                'm.value',
                'm.quantity_after',
                'm.value_after',
                'm.unit_cost_after',
                'm.source_type',
                'm.source_id',
                'm.memo',
                'w.code as warehouse_code',
                'je.entry_no',
            ])
            ->all();

        /** @var list<object{id: string, occurred_on: string, kind: string, quantity: string, unit_cost: string, value: string, quantity_after: string, value_after: string, unit_cost_after: string, source_type: string, source_id: ?string, memo: ?string, warehouse_code: string, entry_no: ?string}> $rows */
        return array_values(array_map(
            static fn (object $row): array => [
                'id' => $row->id,
                'date' => Carbon::parse((string) $row->occurred_on)->toDateString(),
                'kind' => $row->kind,
                'warehouse' => $row->warehouse_code,
                'quantity' => (string) $row->quantity,
                'unit_cost' => (string) $row->unit_cost,
                'value' => (string) $row->value,
                'quantity_after' => (string) $row->quantity_after,
                'value_after' => (string) $row->value_after,
                'average_after' => (string) $row->unit_cost_after,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'entry_no' => $row->entry_no,
                'memo' => $row->memo,
            ],
            $rows,
        ));
    }

    private function organizationId(): string
    {
        return $this->tenant->organization()->id;
    }
}
