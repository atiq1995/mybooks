<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Data\MovementRequest;
use App\Domain\Inventory\Data\PlannedMovement;
use App\Domain\Inventory\Enums\StockMovementKind;
use App\Domain\Inventory\Exceptions\StockRefused;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only thing that writes to the stock ledger.
 *
 * Everything about inventory reduces to one piece of arithmetic done in one
 * place, and this is it. Nothing else in the application inserts a stock
 * movement or touches a stock level — the same rule the journal has, for the
 * same reason.
 *
 * ## The weighted average
 *
 * Stock coming IN carries the cost somebody else decided:
 *
 *     value' = value + cost of what arrived
 *     qty'   = qty + what arrived
 *     average' = value' / qty'
 *
 * Stock going OUT is valued at the average of the moment:
 *
 *     cost   = quantity × average
 *     value' = value − cost
 *     qty'   = qty − quantity
 *
 * with one deliberate exception. When the last unit leaves, the cost is the
 * whole remaining VALUE rather than quantity × average. Rounding a rate to
 * four places and multiplying leaves a fraction behind, and a shelf holding
 * nothing but 0.0002 of value is both meaningless and a permanent difference
 * between the stock report and the inventory account. Emptying the shelf
 * empties its value, exactly.
 *
 * ## Why two phases
 *
 * A shipment's journal entry needs the cost, which is not known until the
 * average has been read; the movement needs the entry's id, and the table is
 * append-only so it cannot be written and then updated. So {@see plan()}
 * takes the locks and computes every figure, the caller posts, and
 * {@see commit()} writes. Both run inside the caller's transaction, so the
 * locks taken in the first are still held during the second.
 *
 * ## Why a lock at all
 *
 * A weighted average is read-modify-write. Two shipments of the same item at
 * the same instant, without a lock, both read the same average and both write
 * a value computed from a state that no longer exists — and the inventory
 * account and the stock quietly part company by the difference. The
 * `stock_levels` row is locked `FOR UPDATE`, which serialises them, exactly as
 * the document-sequence row serialises numbering.
 *
 * @see ACCOUNTING_RULES.md I10, §4.10
 */
final readonly class StockLedger
{
    /** Money is stored to four places; a unit cost is a rate, so ten. */
    private const MONEY_SCALE = 4;

    private const RATE_SCALE = 10;

    private const QUANTITY_SCALE = 6;

    public function __construct(
        private TenantContext $tenant,
    ) {}

    /**
     * Cost a set of movements, taking the locks that make the answer stable.
     *
     * MUST be called inside a transaction: the locks it takes are what make
     * the figures it returns still true by the time {@see commit()} writes
     * them, and they are released at commit.
     *
     * @param  list<MovementRequest>  $requests
     * @return list<PlannedMovement>
     */
    public function plan(array $requests): array
    {
        $planned = [];

        /*
         * Running state per (item, warehouse) WITHIN this plan.
         *
         * Two lines of one invoice can ship the same item from the same
         * warehouse — a kit, or simply a mistake in data entry — and the
         * second must be costed at the average the first one left behind, not
         * at the average before either. Re-reading the row would give the
         * stale figure, because nothing has been written yet.
         *
         * Seeded by locking every row the plan touches UP FRONT, in a fixed
         * order. Taking the locks lazily in line order means two documents
         * listing the same two items the other way round each hold the lock
         * the other is waiting for, and PostgreSQL resolves that by killing
         * one of them. Sorting the keys makes the order the same for every
         * caller, so they queue instead of deadlocking. The costing loop below
         * still runs in the caller's own order, because that order is what
         * decides the average.
         */
        $running = $this->lockLevels($requests);

        foreach ($requests as $request) {
            $item = $request->item;

            if (! $item->is_tracked) {
                throw StockRefused::itemIsNotTracked($item->name);
            }

            $key = $item->id.':'.$request->warehouseId;
            $state = $running[$key];

            $quantity = BigDecimal::of($request->quantity)
                ->toScale(self::QUANTITY_SCALE, RoundingMode::HalfUp);

            $onHand = BigDecimal::of($state['quantity']);
            $value = BigDecimal::of($state['value']);
            $average = BigDecimal::of($state['average_cost']);

            if ($quantity->isNegative()) {
                [$movementValue, $unitCost, $newQuantity, $newValue] = $this->costOutbound(
                    $request,
                    $quantity,
                    $onHand,
                    $value,
                    $average,
                );
            } elseif ($quantity->isZero()) {
                [$movementValue, $unitCost, $newQuantity, $newValue] = $this->revalue(
                    $request,
                    $onHand,
                    $value,
                );
            } else {
                [$movementValue, $unitCost, $newQuantity, $newValue] = $this->costInbound(
                    $request,
                    $quantity,
                    $onHand,
                    $value,
                    $average,
                );
            }

            $newAverage = $newQuantity->isPositive()
                ? $newValue->dividedBy($newQuantity, self::RATE_SCALE, RoundingMode::HalfUp)
                : BigDecimal::zero();

            $planned[] = new PlannedMovement(
                itemId: $item->id,
                itemName: $item->name,
                warehouseId: $request->warehouseId,
                kind: $request->kind,
                occurredOn: $request->occurredOn,
                quantity: (string) $quantity,
                unitCost: (string) $unitCost->toScale(self::RATE_SCALE, RoundingMode::HalfUp),
                value: (string) $movementValue->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                quantityAfter: (string) $newQuantity,
                valueAfter: (string) $newValue->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                unitCostAfter: (string) $newAverage,
                sourceType: $request->sourceType,
                sourceId: $request->sourceId,
                sourceLineId: $request->sourceLineId,
                // What the LEDGER used, where the caller knows it; the item's
                // own account only where it does not. See MovementRequest.
                inventoryAccountId: $request->inventoryAccountId ?? $item->inventory_account_id,
                memo: $request->memo,
            );

            $running[$key] = [
                'quantity' => (string) $newQuantity,
                'value' => (string) $newValue->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                'average_cost' => (string) $newAverage,
            ];
        }

        return $planned;
    }

    /**
     * Write the planned movements, and move each level to the state they left.
     *
     * @param  list<PlannedMovement>  $planned
     * @return list<StockMovement>
     */
    public function commit(array $planned, ?string $journalEntryId = null, ?User $actor = null): array
    {
        $organizationId = $this->tenant->organization()->id;
        $now = Carbon::now();

        $movements = [];

        foreach ($planned as $movement) {
            $id = (string) Str::uuid7();

            DB::table('stock_movements')->insert([
                'id' => $id,
                'organization_id' => $organizationId,
                'item_id' => $movement->itemId,
                'warehouse_id' => $movement->warehouseId,
                'occurred_on' => $movement->occurredOn->toDateString(),
                'kind' => $movement->kind->value,
                'quantity' => $movement->quantity,
                'unit_cost' => $movement->unitCost,
                'value' => $movement->value,
                'quantity_after' => $movement->quantityAfter,
                'value_after' => $movement->valueAfter,
                'unit_cost_after' => $movement->unitCostAfter,
                'source_type' => $movement->sourceType,
                'source_id' => $movement->sourceId,
                'source_line_id' => $movement->sourceLineId,
                'journal_entry_id' => $journalEntryId,
                /*
                 * Where this value went, as it stood at the time. Recorded
                 * rather than re-derived from the item, because the item's
                 * account can be changed afterwards and I10 asks what the
                 * books did, not what the item master says today.
                 */
                'inventory_account_id' => $movement->inventoryAccountId,
                'memo' => $movement->memo,
                'created_by' => $actor?->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('stock_levels')
                ->where('organization_id', $organizationId)
                ->where('item_id', $movement->itemId)
                ->where('warehouse_id', $movement->warehouseId)
                ->update([
                    'quantity' => $movement->quantityAfter,
                    'value' => $movement->valueAfter,
                    'average_cost' => $movement->unitCostAfter,
                    'last_movement_id' => $id,
                    'updated_at' => $now,
                ]);

            $movements[] = StockMovement::query()->findOrFail($id);
        }

        return $movements;
    }

    /**
     * The movements a document caused, oldest first.
     *
     * @return list<StockMovement>
     */
    public function movementsFor(string $sourceType, string $sourceId): array
    {
        return array_values(StockMovement::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->with('item')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Turn movements into requests that undo them, at the value they carried.
     *
     * **At the value they carried** is the whole of it. The ledger side of an
     * undo is a mirror reversal — `ReverseJournalEntry` copies the original
     * amounts and swaps the sides — so the stock side has to move the same
     * figures back, or the two halves of I10 end up on different numbers and
     * the difference is permanent.
     *
     * Valuing the return at today's average instead is the obvious mistake,
     * and it is wrong by however much the average has moved since: buy at 10,
     * buy at 20, void the first bill, and an average-valued undo takes 15 off
     * the shelf where the ledger credited 10.
     *
     * Where the goods have genuinely moved on, the undo does not fit and
     * {@see StockRefused::valueDoesNotFit()} says so rather than inventing a
     * position. That is the honest outcome: a document whose goods have been
     * sold cannot be made never to have happened.
     *
     * @param  list<StockMovement>  $movements
     * @return list<MovementRequest>
     */
    public function reversalRequests(
        array $movements,
        Carbon $occurredOn,
        string $sourceType,
        ?string $sourceId = null,
        ?string $memo = null,
    ): array {
        $requests = [];

        foreach ($movements as $movement) {
            $item = $movement->item;

            if ($item === null) {
                continue;
            }

            $quantity = BigDecimal::of($movement->quantity);
            $value = BigDecimal::of($movement->value);

            $requests[] = new MovementRequest(
                item: $item,
                warehouseId: $movement->warehouse_id,
                kind: $quantity->isNegative()
                    ? StockMovementKind::ReturnIn
                    : ($quantity->isZero()
                        ? StockMovementKind::Revaluation
                        : StockMovementKind::ReturnOut),
                quantity: (string) $quantity->negated(),
                occurredOn: $occurredOn,
                sourceType: $sourceType,
                sourceId: $sourceId,
                sourceLineId: $movement->source_line_id,
                // Signed for a revaluation, absolute either side of zero for
                // the rest — plan() reads the sign off the quantity.
                totalValue: $quantity->isZero()
                    ? (string) $value->negated()
                    : (string) $value->abs(),
                memo: $memo,
            );
        }

        return $requests;
    }

    /**
     * The total value a set of planned movements moves, by inventory account.
     *
     * Grouped, because a journal entry is per account rather than per line,
     * and because two items can share one inventory account.
     *
     * @param  list<PlannedMovement>  $planned
     * @return array<string, string> account id => absolute value, as a decimal string
     */
    public function valueByAccount(array $planned): array
    {
        $totals = [];

        foreach ($planned as $movement) {
            $accountId = $movement->inventoryAccountId;

            if ($accountId === null) {
                continue;
            }

            $totals[$accountId] = (string) BigDecimal::of($totals[$accountId] ?? '0')
                ->plus(BigDecimal::of($movement->value))
                ->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
        }

        return array_filter(
            $totals,
            static fn (string $value): bool => ! BigDecimal::of($value)->isZero(),
        );
    }

    /**
     * Lock every level row this plan touches, in a fixed order.
     *
     * The order is the point. Locks taken in the caller's line order let two
     * documents that name the same items in opposite orders each wait on the
     * other, and PostgreSQL breaks the tie by aborting one — an approval that
     * fails for reasons having nothing to do with the books. Sorting the keys
     * gives every caller the same order, so concurrent plans queue.
     *
     * @param  list<MovementRequest>  $requests
     * @return array<string, array{quantity: string, value: string, average_cost: string}>
     */
    private function lockLevels(array $requests): array
    {
        $keys = [];

        foreach ($requests as $request) {
            $keys[$request->item->id.':'.$request->warehouseId] = [
                $request->item->id,
                $request->warehouseId,
            ];
        }

        ksort($keys);

        $state = [];

        foreach ($keys as $key => [$itemId, $warehouseId]) {
            $state[$key] = $this->lockLevel($itemId, $warehouseId);
        }

        return $state;
    }

    /**
     * Read the level row and hold it until the transaction ends.
     *
     * Creates it on first use. `insertOrIgnore` rather than a check-then-insert,
     * because two first movements of the same item can race here too — and the
     * unique index is the only thing that can arbitrate.
     *
     * @return array{quantity: string, value: string, average_cost: string}
     */
    private function lockLevel(string $itemId, string $warehouseId): array
    {
        $organizationId = $this->tenant->organization()->id;

        DB::table('stock_levels')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'quantity' => '0',
            'value' => '0',
            'average_cost' => '0',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        /** @var object{quantity: string, value: string, average_cost: string} $row */
        $row = DB::table('stock_levels')
            ->where('organization_id', $organizationId)
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first(['quantity', 'value', 'average_cost']);

        return [
            'quantity' => (string) $row->quantity,
            'value' => (string) $row->value,
            'average_cost' => (string) $row->average_cost,
        ];
    }

    /**
     * Stock leaving, valued at the average of the moment — or at the figure
     * the ledger has already committed to.
     *
     * @return array{0: BigDecimal, 1: BigDecimal, 2: BigDecimal, 3: BigDecimal}
     */
    private function costOutbound(
        MovementRequest $request,
        BigDecimal $quantity,
        BigDecimal $onHand,
        BigDecimal $value,
        BigDecimal $average,
    ): array {
        $itemName = $request->item->name;
        $leaving = $quantity->abs();

        if ($leaving->isGreaterThan($onHand)) {
            throw StockRefused::notEnoughStock(
                $itemName,
                $this->warehouseName($request->warehouseId),
                (string) $leaving,
                (string) $onHand,
            );
        }

        $newQuantity = $onHand->minus($leaving);

        if ($request->totalValue !== null) {
            /*
             * The caller states the value because the ledger has already
             * moved it: a vendor credit crediting the inventory account by
             * the credit's own cost, or a void reversing exactly what the
             * original movement carried. The shelf follows the entry, because
             * the entry is the thing the balance sheet shows.
             *
             * It is refused rather than clamped when it does not fit. A shelf
             * left holding a value it cannot own — negative, or a value with
             * no units under it — is not a state worth reaching; the honest
             * response is to say the document cannot be undone this way.
             */
            $cost = BigDecimal::of($request->totalValue)->abs()
                ->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);

            $newValue = $value->minus($cost);

            if ($newValue->isNegative() || ($newQuantity->isZero() && ! $newValue->isZero())) {
                throw StockRefused::valueDoesNotFit(
                    $itemName,
                    $this->warehouseName($request->warehouseId),
                    (string) $cost,
                    (string) $value,
                );
            }

            $unitCost = $leaving->isZero()
                ? BigDecimal::zero()
                : $cost->dividedBy($leaving, self::RATE_SCALE, RoundingMode::HalfUp);

            return [$cost->negated(), $unitCost, $newQuantity, $newValue];
        }

        /*
         * The last unit takes the whole remaining value with it.
         *
         * quantity × a ten-place average, rounded to four, leaves a fraction
         * behind on almost every movement. Left alone it accumulates as value
         * against zero quantity — a shelf worth 0.0002 of nothing — and it is
         * a permanent difference between the stock report and the inventory
         * account. Emptying the shelf empties its value.
         */
        $cost = $newQuantity->isZero()
            ? $value
            : $leaving->multipliedBy($average)->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);

        $newValue = $value->minus($cost);

        $unitCost = $leaving->isZero()
            ? BigDecimal::zero()
            : $cost->dividedBy($leaving, self::RATE_SCALE, RoundingMode::HalfUp);

        return [$cost->negated(), $unitCost, $newQuantity, $newValue];
    }

    /**
     * Value changing while quantity does not — a write-down, a write-up.
     *
     * Both of its refusals exist because the alternative is a state the
     * database would reject anyway, as a 500 naming a constraint rather than
     * a sentence naming the problem:
     *
     * - Revaluing an empty shelf would leave value with no units under it.
     *   There is nothing there to be worth anything.
     * - Writing down further than the stock is worth would take the inventory
     *   account negative. An asset account in credit is not a thing a balance
     *   sheet can show, and the figure to write down to is at most what is
     *   there.
     *
     * @return array{0: BigDecimal, 1: BigDecimal, 2: BigDecimal, 3: BigDecimal}
     */
    private function revalue(
        MovementRequest $request,
        BigDecimal $onHand,
        BigDecimal $value,
    ): array {
        $change = BigDecimal::of($request->totalValue ?? '0')
            ->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);

        if (! $onHand->isPositive()) {
            throw StockRefused::nothingToRevalue(
                $request->item->name,
                $this->warehouseName($request->warehouseId),
            );
        }

        $newValue = $value->plus($change);

        if ($newValue->isNegative()) {
            throw StockRefused::writeDownTooLarge(
                $request->item->name,
                $this->warehouseName($request->warehouseId),
                (string) $change->abs(),
                (string) $value,
            );
        }

        return [$change, BigDecimal::zero(), $onHand, $newValue];
    }

    /**
     * Stock arriving, at the cost it came with.
     *
     * @return array{0: BigDecimal, 1: BigDecimal, 2: BigDecimal, 3: BigDecimal}
     */
    private function costInbound(
        MovementRequest $request,
        BigDecimal $quantity,
        BigDecimal $onHand,
        BigDecimal $value,
        BigDecimal $average,
    ): array {
        if ($request->totalValue !== null) {
            /*
             * The caller knows the value rather than the rate. A bill line's
             * capitalised cost already carries blocked tax and a share of any
             * document-level discount; dividing it back into a per-unit rate
             * and multiplying again would round twice and leave the stock a
             * cent away from what the ledger debited.
             */
            $movementValue = BigDecimal::of($request->totalValue)
                ->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);

            /*
             * Goods arriving cannot be worth less than nothing, and the
             * database says so with a CHECK on `unit_cost`. Without this the
             * refusal arrived as a 500 naming a constraint: an adjustment
             * form accepts a quantity and a value independently, so "10 units
             * in, worth −500" is two fields somebody can fill in.
             */
            if ($movementValue->isNegative()) {
                throw StockRefused::valueDoesNotFit(
                    $request->item->name,
                    $this->warehouseName($request->warehouseId),
                    (string) $movementValue,
                    (string) $value,
                );
            }
        } elseif ($request->unitCost !== null) {
            $movementValue = $quantity->multipliedBy(BigDecimal::of($request->unitCost))
                ->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
        } elseif ($average->isPositive()) {
            // A stocktake that found more of something already on the shelf
            // values them at what the rest of the shelf is worth.
            $movementValue = $quantity->multipliedBy($average)
                ->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
        } elseif ($request->kind === StockMovementKind::Adjustment && $quantity->isPositive()) {
            // Nothing on the shelf and no rate given: there is no honest
            // answer, so ask rather than invent one.
            throw StockRefused::needsACost($request->item->name);
        } else {
            $movementValue = BigDecimal::zero();
        }

        $newQuantity = $onHand->plus($quantity);
        $newValue = $value->plus($movementValue);

        $unitCost = $quantity->isZero()
            ? BigDecimal::zero()
            : $movementValue->dividedBy($quantity, self::RATE_SCALE, RoundingMode::HalfUp);

        return [$movementValue, $unitCost, $newQuantity, $newValue];
    }

    private function warehouseName(string $warehouseId): string
    {
        $warehouse = Warehouse::query()->find($warehouseId);

        return $warehouse === null ? 'the warehouse' : $warehouse->name;
    }
}
