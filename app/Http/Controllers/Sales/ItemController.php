<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Catalog\Enums\ItemKind;
use App\Domain\Catalog\Models\Item;
use App\Domain\Tax\Models\Tax;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreItemRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Things you sell and buy.
 *
 * An item is a set of DEFAULTS for a document line, and the screen says so:
 * the price, account and tax here are what a new line starts from, not what
 * past lines are worth. Past lines carry their own copies, which is why
 * repricing an item never restates an invoice.
 */
final class ItemController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::InventoryView->value);

        $organization = $this->tenant->organization();
        $search = $request->string('search')->toString();
        $kind = $request->string('kind')->toString();

        $items = Item::query()
            ->when(
                $search !== '',
                fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('sku', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%");
                }),
            )
            ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
            ->when(! $request->boolean('archived'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Sales/Items/Index', [
            'items' => [
                'data' => array_values(array_map(
                    fn (Item $item): array => [
                        'id' => $item->id,
                        'kind' => $item->kind->value,
                        'kind_label' => $item->kind->label(),
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'description' => $item->description,
                        'unit' => $item->unit,
                        'sale_price' => $item->sale_price,
                        'purchase_price' => $item->purchase_price,
                        'currency' => $item->currency ?? $organization->base_currency,
                        'sales_account_id' => $item->sales_account_id,
                        'purchase_account_id' => $item->purchase_account_id,
                        'sales_tax_id' => $item->sales_tax_id,
                        'is_tracked' => $item->is_tracked,
                        'is_sold' => $item->is_sold,
                        'is_purchased' => $item->is_purchased,
                        'is_archived' => $item->archived_at !== null,
                    ],
                    $items->items(),
                )),
                'links' => $items->linkCollection()->toArray(),
                'total' => $items->total(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
            ],
            'filters' => [
                'search' => $search,
                'kind' => $kind,
                'archived' => $request->boolean('archived'),
            ],
            'baseCurrency' => $organization->base_currency,
            'options' => [
                'kinds' => array_map(
                    static fn (ItemKind $itemKind): array => [
                        'value' => $itemKind->value,
                        'label' => $itemKind->label(),
                    ],
                    ItemKind::cases(),
                ),
                'revenueAccounts' => $this->accountOptions('income'),
                'expenseAccounts' => $this->accountOptions('expense'),
                'taxes' => $this->taxOptions(),
            ],
            'can' => [
                'manage' => $request->user()?->can(Permission::InventoryManageItems->value) ?? false,
            ],
        ]);
    }

    public function store(StoreItemRequest $request): RedirectResponse
    {
        $this->authorize(Permission::InventoryManageItems->value);

        $item = DB::transaction(function () use ($request): Item {
            $item = Item::query()->create($request->validated());

            $this->audit->record(
                action: 'catalog.item_created',
                subject: $item,
                description: "Created {$item->kind->label()} {$item->name}",
                new: [
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'sale_price' => $item->sale_price,
                ],
                actor: $request->user(),
            );

            return $item;
        });

        return back()->with('success', "{$item->name} added.");
    }

    public function update(StoreItemRequest $request, Item $item): RedirectResponse
    {
        $this->authorize(Permission::InventoryManageItems->value);

        $this->guardBelongsToActiveOrganization($item);

        DB::transaction(function () use ($item, $request): void {
            $item->fill($request->validated())->save();

            if ($item->wasChanged()) {
                /*
                 * Audited, but it restates nothing already issued: every
                 * document line copied the price it was created with.
                 */
                $this->audit->recordChange(
                    action: 'catalog.item_updated',
                    subject: $item,
                    description: "Updated {$item->name}",
                    actor: $request->user(),
                );
            }
        });

        return back()->with('success', "{$item->name} updated.");
    }

    /**
     * Archive, never delete: document lines reference the item for reporting,
     * and removing it would orphan that reference on every past invoice.
     */
    public function archive(Request $request, Item $item): RedirectResponse
    {
        $this->authorize(Permission::InventoryManageItems->value);

        $this->guardBelongsToActiveOrganization($item);

        $item->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        $this->audit->record(
            action: 'catalog.item_archived',
            subject: $item,
            description: "Archived {$item->name}",
            actor: $request->user(),
        );

        return back()->with('success', "{$item->name} archived.");
    }

    public function restore(Request $request, Item $item): RedirectResponse
    {
        $this->authorize(Permission::InventoryManageItems->value);

        $this->guardBelongsToActiveOrganization($item);

        $item->forceFill(['archived_at' => null, 'is_active' => true])->save();

        return back()->with('success', "{$item->name} restored.");
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function accountOptions(string $type): array
    {
        return array_values(
            Account::query()
                ->postable()
                ->where('type', $type)
                ->orderBy('code')
                ->get()
                ->map(static fn (Account $account): array => [
                    'value' => $account->id,
                    'label' => "{$account->code} — {$account->name}",
                ])
                ->all(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function taxOptions(): array
    {
        return array_values(
            Tax::query()
                ->usable()
                ->orderBy('name')
                ->get()
                ->map(static fn (Tax $tax): array => [
                    'value' => $tax->id,
                    'label' => $tax->name,
                ])
                ->all(),
        );
    }

    private function guardBelongsToActiveOrganization(Item $item): void
    {
        abort_unless($item->organization_id === $this->tenant->organization()->id, 404);
    }
}
