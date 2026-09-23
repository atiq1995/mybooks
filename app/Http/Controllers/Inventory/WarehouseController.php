<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Access\Enums\Permission;
use App\Domain\Inventory\Actions\SaveWarehouse;
use App\Domain\Inventory\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where stock sits.
 *
 * The figure on each row is the value the warehouse holds, summed from the
 * stock ledger rather than kept anywhere — there is no cached total on a
 * warehouse for the same reason there is none on a bank account.
 */
final class WarehouseController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SaveWarehouse $saveWarehouse,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::InventoryView->value);

        $organization = $this->tenant->organization();

        $totals = $this->valueByWarehouse();

        $warehouses = Warehouse::query()
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();

        return Inertia::render('Inventory/Warehouses', [
            'warehouses' => array_values($warehouses
                ->map(static fn (Warehouse $warehouse): array => [
                    'id' => $warehouse->id,
                    'code' => $warehouse->code,
                    'name' => $warehouse->name,
                    'label' => $warehouse->label(),
                    'address' => $warehouse->address,
                    'is_default' => $warehouse->is_default,
                    'is_active' => $warehouse->is_active,
                    'is_archived' => $warehouse->archived_at !== null,
                    'value' => $totals[$warehouse->id]['value'] ?? '0.0000',
                    'lines' => $totals[$warehouse->id]['lines'] ?? 0,
                ])
                ->all()),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::InventoryManageItems->value);

        $this->saveWarehouse->handle($this->validated($request), actor: $request->user());

        return back()->with('success', 'Warehouse added.');
    }

    public function update(Request $request, string $warehouse): RedirectResponse
    {
        $this->authorize(Permission::InventoryManageItems->value);

        $model = Warehouse::query()->findOrFail($warehouse);

        $this->saveWarehouse->handle($this->validated($request), $model, $request->user());

        return back()->with('success', "{$model->name} updated.");
    }

    public function archive(Request $request, string $warehouse): RedirectResponse
    {
        $this->authorize(Permission::InventoryManageItems->value);

        $model = Warehouse::query()->findOrFail($warehouse);

        try {
            $this->saveWarehouse->archive($model, $request->user());
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$model->name} archived. Its history is kept.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        return $validated;
    }

    /**
     * @return array<string, array{value: string, lines: int}>
     */
    private function valueByWarehouse(): array
    {
        /** @var list<object{warehouse_id: string, value: string, lines: int}> $rows */
        $rows = DB::table('stock_levels')
            ->where('organization_id', $this->tenant->organization()->getKey())
            ->groupBy('warehouse_id')
            ->select('warehouse_id')
            ->selectRaw('COALESCE(SUM(value), 0) AS value')
            ->selectRaw('COUNT(*) FILTER (WHERE quantity <> 0) AS lines')
            ->get()
            ->all();

        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->warehouse_id] = [
                'value' => (string) BigDecimal::of((string) $row->value)->toScale(4),
                'lines' => (int) $row->lines,
            ];
        }

        return $totals;
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
