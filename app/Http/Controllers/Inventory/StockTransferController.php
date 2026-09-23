<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Access\Enums\Permission;
use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Actions\TransferStock;
use App\Domain\Inventory\Exceptions\StockRefused;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Moving stock between warehouses.
 *
 * It posts nothing — the business owns what it owned before, somewhere else —
 * so it needs `inventory.adjust` and not the permission to post. The screen
 * says so, because "this will not appear on your profit and loss" is the
 * first thing anybody wonders.
 */
final class StockTransferController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TransferStock $transfers,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::InventoryView->value);

        $organization = $this->tenant->organization();

        $transfers = StockTransfer::query()
            ->with(['fromWarehouse:id,code,name', 'toWarehouse:id,code,name', 'lines'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('number')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/Transfers', [
            'transfers' => [
                'data' => array_values(array_map(
                    static fn (StockTransfer $transfer): array => [
                        'id' => $transfer->id,
                        'number' => $transfer->number,
                        'date' => $transfer->transfer_date->toDateString(),
                        'from' => $transfer->fromWarehouse?->label(),
                        'to' => $transfer->toWarehouse?->label(),
                        'status' => $transfer->status,
                        'lines' => $transfer->lines->count(),
                        'total_value' => $transfer->total_value,
                        'is_completed' => $transfer->isCompleted(),
                    ],
                    $transfers->items(),
                )),
                'links' => $transfers->linkCollection()->toArray(),
                'total' => $transfers->total(),
            ],
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
            'today' => Carbon::now()->toDateString(),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::InventoryAdjust->value);

        $validated = $this->validated($request);

        try {
            $transfer = $this->transfers->save(
                attributes: $validated,
                lines: $this->linesFrom($validated),
                actor: $request->user(),
            );
        } catch (StockRefused $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', "Transfer {$transfer->number} saved. Complete it when the goods move.");
    }

    public function complete(Request $request, string $transfer): RedirectResponse
    {
        $this->authorize(Permission::InventoryAdjust->value);

        $model = StockTransfer::query()->findOrFail($transfer);

        try {
            $completed = $this->transfers->complete($model, $request->user());
        } catch (StockRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Transfer %s completed. %s moved, and nothing posted — the business owns what it owned before.',
            $completed->number,
            $completed->total_value,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'transfer_date' => ['required', 'date'],
            'from_warehouse_id' => ['required', 'uuid', 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private function linesFrom(array $validated): array
    {
        $lines = $validated['lines'] ?? [];

        if (! is_array($lines)) {
            return [];
        }

        $rows = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            /** @var array<string, mixed> $line */
            $rows[] = $line;
        }

        return $rows;
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

    /**
     * @return list<array<string, mixed>>
     */
    private function itemOptions(): array
    {
        $levels = StockLevel::query()->get()->groupBy('item_id');

        return array_values(Item::query()
            ->where('is_tracked', true)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(static function (Item $item) use ($levels): array {
                $onHand = $levels->get($item->id);

                return [
                    'value' => $item->id,
                    'label' => $item->sku === null ? $item->name : "{$item->sku} · {$item->name}",
                    'unit' => $item->unit,
                    'on_hand' => $onHand === null
                        ? []
                        : $onHand->mapWithKeys(static fn (StockLevel $level): array => [
                            $level->warehouse_id => $level->quantity,
                        ])->all(),
                ];
            })
            ->all());
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'transfer' => $user?->can(Permission::InventoryAdjust->value) ?? false,
        ];
    }
}
