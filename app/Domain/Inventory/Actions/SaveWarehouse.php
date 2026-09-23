<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\Warehouse;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Add or change a warehouse.
 *
 * Archiving rather than deleting, and refused outright while anything is
 * still on the shelf: a warehouse holding stock is the only record of where
 * that stock is, and removing it would leave value in the inventory account
 * with nowhere to be.
 */
final readonly class SaveWarehouse
{
    public function __construct(
        private TenantContext $tenant,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        array $attributes,
        ?Warehouse $warehouse = null,
        ?User $actor = null,
    ): Warehouse {
        $organization = $this->tenant->organization();

        return DB::transaction(function () use ($attributes, $warehouse, $actor, $organization): Warehouse {
            $isNew = $warehouse === null;

            if ($isNew) {
                $warehouse = new Warehouse;

                $warehouse->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'created_by' => $actor?->getKey(),
                ]);
            }

            /** @var Warehouse $warehouse */
            $wantsDefault = (bool) ($attributes['is_default'] ?? false);

            /*
             * Stand the others down BEFORE saving, not after.
             *
             * One default per organisation is a partial unique index, so a
             * second row claiming it is refused by the database at the moment
             * of insert — clearing the old one afterwards would never get the
             * chance to run.
             */
            if ($wantsDefault) {
                Warehouse::query()
                    ->when(
                        $warehouse->exists,
                        fn ($query) => $query->whereKeyNot($warehouse->getKey()),
                    )
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $warehouse->forceFill([
                'code' => mb_strtoupper(trim(self::text($attributes, 'code'))),
                'name' => trim(self::text($attributes, 'name')),
                'address' => self::optionalText($attributes, 'address'),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'is_default' => $wantsDefault,
            ])->save();

            $this->audit->record(
                action: $isNew ? 'inventory.warehouse_created' : 'inventory.warehouse_updated',
                subject: $warehouse,
                description: sprintf(
                    '%s warehouse %s',
                    $isNew ? 'Added' : 'Updated',
                    $warehouse->label(),
                ),
                new: [
                    'code' => $warehouse->code,
                    'name' => $warehouse->name,
                    'is_default' => $warehouse->is_default,
                    'is_active' => $warehouse->is_active,
                ],
                actor: $actor,
            );

            return $warehouse->refresh();
        });
    }

    /**
     * Archive a warehouse that no longer holds anything.
     */
    public function archive(Warehouse $warehouse, ?User $actor = null): Warehouse
    {
        $onHand = StockLevel::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->get()
            ->reduce(
                static fn (BigDecimal $carry, StockLevel $level): BigDecimal => $carry->plus(BigDecimal::of($level->quantity)->abs()),
                BigDecimal::zero(),
            );

        if ($onHand->isPositive()) {
            throw new \RuntimeException(
                "{$warehouse->name} still holds {$onHand} units. Transfer them somewhere else or ".
                'write them off first — archiving it now would leave value in the inventory '.
                'account with nowhere to be.'
            );
        }

        return DB::transaction(function () use ($warehouse, $actor): Warehouse {
            $warehouse->forceFill([
                'is_active' => false,
                'is_default' => false,
                'archived_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'inventory.warehouse_archived',
                subject: $warehouse,
                description: "Archived warehouse {$warehouse->label()}",
                actor: $actor,
            );

            return $warehouse->refresh();
        });
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

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
