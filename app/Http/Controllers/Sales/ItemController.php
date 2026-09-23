<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Catalog\Enums\ItemKind;
use App\Domain\Catalog\Exceptions\ItemRefused;
use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Purchases\Models\PurchaseDocumentLine;
use App\Domain\Tax\Models\Tax;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreItemRequest;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
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

        try {
            $item = DB::transaction(function () use ($request): Item {
                $item = Item::query()->create($request->validated());

                $this->refuseADirtyInventoryAccount($item);

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
        } catch (ItemRefused $exception) {
            return back()->withErrors(['inventory_account_id' => $exception->getMessage()])
                ->withInput();
        }

        return back()->with('success', "{$item->name} added.");
    }

    public function update(StoreItemRequest $request, Item $item): RedirectResponse
    {
        $this->authorize(Permission::InventoryManageItems->value);

        $this->guardBelongsToActiveOrganization($item);

        try {
            DB::transaction(function () use ($item, $request): void {
                $item->fill($request->validated())->save();

                /*
                 * Stock accounting is not a property of the item master once
                 * the item has moved.
                 *
                 * `is_tracked` and `inventory_account_id` are what tie a
                 * movement to the account its value went to. Changing either
                 * after stock has moved splits the history: the old account
                 * keeps the value it was debited with and loses the stock
                 * behind it, while the new one receives stock it was never
                 * debited for. Untick tracking and the account drops out of
                 * the reconciliation altogether, which is a checkbox
                 * switching off an invariant.
                 *
                 * Refused inside the transaction, so the save goes with it.
                 * Moving stock between accounts is a decision that needs a
                 * journal entry behind it, not a form field.
                 */
                if ($item->wasChanged(['is_tracked', 'inventory_account_id'])
                    && $this->stockAccountingIsCommitted($item)) {
                    throw ItemRefused::stockHasAlreadyMoved($item->name);
                }

                if ($item->wasChanged('inventory_account_id')) {
                    $this->refuseADirtyInventoryAccount($item);
                }

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
        } catch (ItemRefused $exception) {
            return back()->withErrors(['is_tracked' => $exception->getMessage()])->withInput();
        }

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
     * An account only becomes an inventory account if it is clean.
     *
     * Naming an account here is what puts it inside I10: from that moment
     * `verify-ledger` reconciles its balance against the stock attributed to
     * it, on every date — **including everything posted to it before**, which
     * nothing in inventory can explain and no inventory document can correct.
     * An account carrying an unrelated balance would therefore start life as
     * a breach that can only be closed by a hand-written journal.
     *
     * This is the other end of the rule that stops an expense being costed to
     * an inventory account. That one refuses new postings to an account that
     * is already inventory; this one refuses an account becoming inventory
     * when it already has postings. Between them there is no order of
     * operations that gets a stray balance inside the invariant.
     */
    private function refuseADirtyInventoryAccount(Item $item): void
    {
        $accountId = $item->inventory_account_id;

        if ($accountId === null) {
            return;
        }

        $account = Account::query()->find($accountId);

        if ($account === null) {
            return;
        }

        $stock = StockMovement::query()
            ->where('inventory_account_id', $accountId)
            ->sum('value');

        $balance = BigDecimal::of((string) $account->balance())
            ->minus(BigDecimal::of((string) $stock));

        if ($balance->isZero()) {
            return;
        }

        throw ItemRefused::accountAlreadyHasOtherPostings(
            $account->code.' '.$account->name,
            (string) $balance,
        );
    }

    /**
     * Whether anything has already committed to this item's stock accounting.
     *
     * Movements are the obvious case. Purchase lines are the subtle one, and
     * the one that made "no movements yet" too weak a test: a bill line
     * freezes its account when the bill is SAVED, and the entry posted at
     * approval uses that frozen account. So a draft bill is already a
     * commitment — change the item underneath it and the entry debits one
     * account while the goods land against another, which is precisely the
     * split this guard exists to prevent.
     *
     * Sales lines do not count: a despatch builds its entry from the same
     * live account the movement records, so the two cannot come apart.
     */
    private function stockAccountingIsCommitted(Item $item): bool
    {
        return StockMovement::query()->where('item_id', $item->getKey())->exists()
            || PurchaseDocumentLine::query()->where('item_id', $item->getKey())->exists();
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
