<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Actions\ApproveInventoryAdjustment;
use App\Domain\Inventory\Actions\SaveInventoryAdjustment;
use App\Domain\Inventory\Exceptions\StockRefused;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Models\InventoryAdjustmentLine;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock adjustments — a count that disagreed, a breakage, a write-off.
 *
 * Writing one down needs `inventory.adjust`; so does approving it. That is
 * not the separation of duties bills have, and the reason is that an
 * adjustment is small and frequent — a stocktake is not a payment. What it
 * does have is a mandatory reason, an audit entry, and a posting that happens
 * only on approval, so nothing changes by accident.
 */
final class InventoryAdjustmentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SaveInventoryAdjustment $saveAdjustment,
        private readonly ApproveInventoryAdjustment $approveAdjustment,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::InventoryView->value);

        $organization = $this->tenant->organization();
        $status = $request->string('status')->toString();

        $adjustments = InventoryAdjustment::query()
            ->with(['warehouse:id,code,name', 'account:id,code,name'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('adjustment_date')
            ->orderByDesc('number')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/Adjustments', [
            'adjustments' => [
                'data' => array_values(array_map(
                    fn (InventoryAdjustment $adjustment): array => $this->summarise($adjustment),
                    $adjustments->items(),
                )),
                'links' => $adjustments->linkCollection()->toArray(),
                'total' => $adjustments->total(),
            ],
            'filters' => ['status' => $status],
            'warehouses' => $this->warehouseOptions(),
            'accounts' => $this->accountOptions(),
            'items' => $this->itemOptions(),
            'today' => Carbon::now()->toDateString(),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    public function show(Request $request, string $adjustment): Response
    {
        $this->authorize(Permission::InventoryView->value);

        $organization = $this->tenant->organization();

        $model = InventoryAdjustment::query()
            ->with(['lines.item:id,sku,name,unit', 'warehouse:id,code,name', 'account:id,code,name', 'journalEntry:id,entry_no'])
            ->findOrFail($adjustment);

        return Inertia::render('Inventory/Adjustment', [
            'adjustment' => [
                ...$this->summarise($model),
                'notes' => $model->notes,
                'entry_no' => $model->journalEntry?->entry_no,
                'lines' => array_values($model->lines
                    ->map(static fn (InventoryAdjustmentLine $line): array => [
                        'id' => $line->id,
                        'item_id' => $line->item_id,
                        'sku' => $line->item?->sku,
                        'item_name' => $line->item === null ? 'Unknown item' : $line->item->name,
                        'unit' => $line->item?->unit,
                        'counted_quantity' => $line->counted_quantity,
                        'quantity_change' => $line->quantity_change,
                        'value_change' => $line->value_change,
                        'unit_cost' => $line->unit_cost,
                        'memo' => $line->memo,
                    ])
                    ->all()),
            ],
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::InventoryAdjust->value);

        $validated = $this->validated($request);

        try {
            $adjustment = $this->saveAdjustment->handle(
                attributes: $validated,
                lines: $this->linesFrom($validated),
                actor: $request->user(),
            );
        } catch (StockRefused $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->to('/inventory/adjustments/'.$adjustment->id)
            ->with('success', "Adjustment {$adjustment->number} saved. It changes nothing until it is approved.");
    }

    public function update(Request $request, string $adjustment): RedirectResponse
    {
        $this->authorize(Permission::InventoryAdjust->value);

        $model = InventoryAdjustment::query()->findOrFail($adjustment);

        $validated = $this->validated($request);

        try {
            $model = $this->saveAdjustment->handle(
                attributes: $validated,
                lines: $this->linesFrom($validated),
                adjustment: $model,
                actor: $request->user(),
            );
        } catch (StockRefused $exception) {
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', "Adjustment {$model->number} updated.");
    }

    public function approve(Request $request, string $adjustment): RedirectResponse
    {
        $this->authorize(Permission::InventoryAdjust->value);

        // Approving posts, so it needs the permission to post as well. An
        // adjustment is the one place stock changes without a document behind
        // it, and it writes to the ledger like any other document does.
        $this->authorize(Permission::AccountingPost->value);

        $model = InventoryAdjustment::query()->findOrFail($adjustment);

        try {
            $approved = $this->approveAdjustment->handle($model, $request->user());
        } catch (StockRefused|PostingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Adjustment %s approved%s.',
            $approved->number,
            $approved->journal_entry_id === null
                ? ' — no value moved, so nothing posted'
                : '',
        ));
    }

    public function void(Request $request, string $adjustment): RedirectResponse
    {
        $this->authorize(Permission::InventoryAdjust->value);
        $this->authorize(Permission::AccountingReverse->value);

        $model = InventoryAdjustment::query()->findOrFail($adjustment);

        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $voided = $this->approveAdjustment->void(
                adjustment: $model,
                actor: $request->user(),
                reason: $request->string('reason')->toString() ?: null,
            );
        } catch (StockRefused|PostingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Adjustment {$voided->number} voided by reversal.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'adjustment_date' => ['required', 'date'],
            'warehouse_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', 'in:quantity,revaluation,opening'],
            'account_id' => ['required', 'uuid'],
            // Mandatory: an adjustment with no reason is an unexplained
            // change to the value of the business.
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quantity_change' => ['nullable', 'numeric'],
            'lines.*.value_change' => ['nullable', 'numeric'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
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
     * @return array<string, mixed>
     */
    private function summarise(InventoryAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'number' => $adjustment->number,
            'date' => $adjustment->adjustment_date->toDateString(),
            'warehouse' => $adjustment->warehouse?->label(),
            'warehouse_id' => $adjustment->warehouse_id,
            'kind' => $adjustment->kind,
            'kind_label' => $adjustment->kindLabel(),
            'account' => $adjustment->account === null
                ? null
                : $adjustment->account->code.' · '.$adjustment->account->name,
            'account_id' => $adjustment->account_id,
            'reason' => $adjustment->reason,
            'status' => $adjustment->status,
            'status_label' => $adjustment->statusLabel(),
            'total_value' => $adjustment->total_value,
            'is_editable' => $adjustment->isEditable(),
            'is_approved' => $adjustment->isApproved(),
            'is_voided' => $adjustment->isVoided(),
        ];
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
     * Where the other side of an adjustment goes.
     *
     * Expenses for a write-off, equity for opening stock. Every postable
     * account is offered rather than a short list, because a business that
     * has made an account for stock variances should be able to use it.
     *
     * @return list<array{value: string, label: string}>
     */
    private function accountOptions(): array
    {
        return array_values(Account::query()
            ->postable()
            ->whereIn('type', ['expense', 'income', 'equity'])
            ->orderBy('code')
            ->get()
            ->map(static fn (Account $account): array => [
                'value' => $account->id,
                'label' => $account->code.' · '.$account->name,
            ])
            ->all());
    }

    /**
     * Tracked items only. Adjusting something with no stock is meaningless.
     *
     * @return list<array<string, mixed>>
     */
    private function itemOptions(): array
    {
        $levels = StockLevel::query()
            ->get()
            ->groupBy('item_id');

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

        $mayAdjust = $user?->can(Permission::InventoryAdjust->value) ?? false;

        return [
            'adjust' => $mayAdjust,
            // The button follows the same rule the route enforces, so a
            // control that would only ever be refused is absent instead.
            'approve' => $mayAdjust && ($user?->can(Permission::AccountingPost->value) ?? false),
            'void' => $mayAdjust && ($user?->can(Permission::AccountingReverse->value) ?? false),
        ];
    }
}
