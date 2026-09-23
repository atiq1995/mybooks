<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Access\Enums\Permission;
use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\StockValuation;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is on the shelf, and what it was worth on a date.
 *
 * Two screens from one controller, because they are the same question asked
 * at two moments. The valuation defaults to today and takes a date, and the
 * figure it shows is the one the inventory control account carries — the
 * screen says so, and `verify-ledger` proves it.
 */
final class StockController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StockValuation $valuation,
    ) {}

    /**
     * Items, with what is on hand against each.
     */
    public function items(Request $request): Response
    {
        $this->authorize(Permission::InventoryView->value);

        $organization = $this->tenant->organization();

        $warehouseId = $this->warehouseFilter($request);
        $search = trim($request->string('search')->toString());

        $rows = $this->valuation->onHand($warehouseId, includeEmpty: true);

        if ($search !== '') {
            $needle = mb_strtolower($search);

            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($needle): bool {
                    $name = $row['item_name'] ?? '';
                    $sku = $row['sku'] ?? '';

                    return (is_string($name) && str_contains(mb_strtolower($name), $needle))
                        || (is_string($sku) && str_contains(mb_strtolower($sku), $needle));
                },
            ));
        }

        $total = BigDecimal::zero();

        foreach ($rows as $row) {
            $total = $total->plus(self::decimal($row['value']));
        }

        return Inertia::render('Inventory/Items', [
            'rows' => $rows,
            'total' => (string) $total->toScale(4),
            'warehouses' => $this->warehouseOptions(),
            'filters' => ['warehouse' => $warehouseId ?? '', 'search' => $search],
            /*
             * Items that are tracked but have never moved do not appear in
             * the stock ledger at all, so they are counted separately rather
             * than silently missing.
             */
            'untracked' => Item::query()->where('is_tracked', false)->count(),
            'tracked' => Item::query()->where('is_tracked', true)->count(),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    /**
     * The valuation, as at a date.
     */
    public function valuation(Request $request): Response
    {
        $this->authorize(Permission::InventoryView->value);

        $organization = $this->tenant->organization();

        $asOf = $this->date($request->query('as_of')) ?? Carbon::now();
        $warehouseId = $this->warehouseFilter($request);

        $rows = $this->valuation->asAt($asOf, $warehouseId);

        $total = BigDecimal::zero();
        $quantity = BigDecimal::zero();

        foreach ($rows as $row) {
            $total = $total->plus(self::decimal($row['value']));
            $quantity = $quantity->plus(self::decimal($row['quantity']));
        }

        return Inertia::render('Inventory/Valuation', [
            'rows' => $rows,
            'total' => (string) $total->toScale(4),
            'quantity' => (string) $quantity->toScale(6),
            'warehouses' => $this->warehouseOptions(),
            'filters' => ['as_of' => $asOf->toDateString(), 'warehouse' => $warehouseId ?? ''],
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    /**
     * Everything that ever happened to one item.
     */
    public function movements(Request $request, string $item): Response
    {
        $this->authorize(Permission::InventoryView->value);

        $organization = $this->tenant->organization();

        $model = Item::query()->findOrFail($item);
        $warehouseId = $this->warehouseFilter($request);

        return Inertia::render('Inventory/Movements', [
            'item' => [
                'id' => $model->id,
                'sku' => $model->sku,
                'name' => $model->name,
                'unit' => $model->unit,
                'is_tracked' => $model->is_tracked,
            ],
            'movements' => $this->valuation->movementsFor($model->id, $warehouseId),
            'warehouses' => $this->warehouseOptions(),
            'filters' => ['warehouse' => $warehouseId ?? ''],
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    /**
     * A figure out of a report row, which the array type widens to mixed.
     */
    private static function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of(is_string($value) || is_int($value) ? $value : '0');
    }

    private function warehouseFilter(Request $request): ?string
    {
        $requested = $request->string('warehouse')->toString();

        /*
         * Shape first, existence second.
         *
         * These ids are uuids, and PostgreSQL rejects a malformed one at the
         * type level rather than returning no rows — so asking "does this
         * exist" about `?warehouse=all` is a 500, not a false. A hand-edited
         * URL or a stale bookmark is a perfectly ordinary thing to receive,
         * and the right answer to it is every warehouse.
         */
        if ($requested === '' || ! Str::isUuid($requested)) {
            return null;
        }

        return Warehouse::query()->whereKey($requested)->exists() ? $requested : null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function warehouseOptions(): array
    {
        return array_values(Warehouse::query()
            ->usable()
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get()
            ->map(static fn (Warehouse $warehouse): array => [
                'value' => $warehouse->id,
                'label' => $warehouse->label(),
            ])
            ->all());
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->endOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'manage' => $user?->can(Permission::InventoryManageItems->value) ?? false,
            'adjust' => $user?->can(Permission::InventoryAdjust->value) ?? false,
        ];
    }
}
