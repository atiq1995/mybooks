<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\StockRefused;
use App\Domain\Inventory\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Which warehouse a movement belongs to.
 *
 * Document lines have carried a nullable `warehouse_id` since Phase 3, long
 * before there were any warehouses — it is written straight from input, has
 * no foreign key, and over HTTP it has always been null because no form
 * request ever validated it. So the value on a line is a HINT and not a
 * guarantee: it may name a warehouse, name something that no longer exists,
 * or be nothing at all.
 *
 * This resolves all three to a real warehouse, and creates the organisation's
 * first one on demand. A business that has just turned tracking on for an
 * item should not have to know that warehouses exist before it can sell
 * anything.
 */
final readonly class WarehouseResolver
{
    public function __construct(
        private TenantContext $tenant,
    ) {}

    /**
     * The warehouse a document line means.
     */
    public function forLine(?string $warehouseId): Warehouse
    {
        if ($warehouseId !== null && Str::isUuid($warehouseId)) {
            $named = Warehouse::query()->find($warehouseId);

            if ($named !== null) {
                if (! $named->isUsable()) {
                    throw StockRefused::warehouseUnusable($named->name);
                }

                return $named;
            }
        }

        return $this->default();
    }

    /**
     * The organisation's default warehouse, created if it has none.
     *
     * Created rather than refused: the alternative is a business being told
     * to go and configure a warehouse in the middle of issuing an invoice,
     * for a concept it has never needed to think about. The one it gets is
     * named plainly so it is obvious what happened.
     */
    public function default(): Warehouse
    {
        $default = Warehouse::query()
            ->usable()
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->first();

        if ($default !== null) {
            return $default;
        }

        $organization = $this->tenant->organization();

        $warehouse = new Warehouse;

        $warehouse->forceFill([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->getKey(),
            'code' => 'MAIN',
            'name' => 'Main',
            'is_default' => true,
            'is_active' => true,
        ])->save();

        return $warehouse;
    }

    /**
     * The default, without creating one.
     *
     * For read paths — a report has no business writing a row.
     */
    public function existingDefault(): ?Warehouse
    {
        return Warehouse::query()
            ->usable()
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->first();
    }

    public function requireDefault(): Warehouse
    {
        return $this->existingDefault() ?? throw StockRefused::noWarehouse();
    }
}
