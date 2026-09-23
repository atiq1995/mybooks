<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Catalog\Enums\ItemKind;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Inventory\Actions\SaveInventoryAdjustment;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\WarehouseResolver;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Sales\Actions\SaveSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| The inventory screens
|---------------------------------------------------------------------------
|
| The accounting suite proves the arithmetic. These cover the HTTP layer, and
| the separation of duties it encodes:
|
|   reading stock needs `inventory.view`, which everybody has;
|
|   changing it needs `inventory.adjust`, which a BOOKKEEPER does not — they
|   can manage items and cannot move stock;
|
|   approving an adjustment needs `accounting.post` on top, because approving
|   it writes in the ledger.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-20');

    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->owner = actingAsMember($this->organization, Role::Owner->value);

    $this->warehouse = app(WarehouseResolver::class)->default();

    $this->widget = Item::query()->create([
        'kind' => ItemKind::Goods,
        'sku' => 'WIDGET',
        'name' => 'Widget',
        'unit' => 'each',
        'sale_price' => '500.00',
        'purchase_price' => '100.00',
        'sales_account_id' => ledgerAccount('4010')->id,
        'inventory_account_id' => ledgerAccount('1300')->id,
        'is_tracked' => true,
        'is_sold' => true,
        'is_purchased' => true,
    ]);

    $this->writeOffAccount = ledgerAccount('6300');

    $this->adjustmentPayload = fn (array $overrides = []): array => [
        'adjustment_date' => '2026-08-20',
        'warehouse_id' => $this->warehouse->id,
        'kind' => 'opening',
        'account_id' => ledgerAccount('3100')->id,
        'reason' => 'Opening stock from the old system',
        'lines' => [[
            'item_id' => $this->widget->id,
            'quantity_change' => '40',
            'unit_cost' => '25.00',
        ]],
        ...$overrides,
    ];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('serves every inventory screen', function (): void {
    foreach ([
        '/inventory/items' => 'Inventory/Items',
        '/inventory/valuation' => 'Inventory/Valuation',
        '/inventory/warehouses' => 'Inventory/Warehouses',
        '/inventory/adjustments' => 'Inventory/Adjustments',
        '/inventory/transfers' => 'Inventory/Transfers',
    ] as $path => $component) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    $this->get("/inventory/items/{$this->widget->id}/movements")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/Movements'));
});

it('404s on an inventory section that does not exist', function (): void {
    $this->get('/inventory/widgets')->assertNotFound();
});

describe('warehouses', function (): void {
    it('adds one, and keeps a single default', function (): void {
        $this->post('/inventory/warehouses', [
            'code' => 'north',
            'name' => 'North store',
            'is_default' => true,
        ])->assertSessionHasNoErrors();

        $added = Warehouse::query()->where('code', 'NORTH')->sole();

        expect($added->is_default)->toBeTrue()
            // The one created on demand gave up being the default.
            ->and(Warehouse::query()->where('is_default', true)->count())->toBe(1);
    });

    it('refuses to archive a warehouse that still holds stock', function (): void {
        /*
         * Archiving it would leave value in the inventory account with
         * nowhere to be — the warehouse is the only record of where the stock
         * is.
         */
        $this->post('/inventory/adjustments', ($this->adjustmentPayload)());

        $adjustment = InventoryAdjustment::query()->sole();

        $this->post("/inventory/adjustments/{$adjustment->id}/approve");

        $this->delete("/inventory/warehouses/{$this->warehouse->id}")
            ->assertSessionHas('error');

        expect(session('error'))->toContain('still holds')
            ->and($this->warehouse->fresh()?->archived_at)->toBeNull();
    });
});

describe('adjustments', function (): void {
    it('saves a draft that changes nothing', function (): void {
        $this->post('/inventory/adjustments', ($this->adjustmentPayload)())
            ->assertSessionHasNoErrors();

        $adjustment = InventoryAdjustment::query()->sole();

        expect($adjustment->status)->toBe('draft')
            ->and($adjustment->journal_entry_id)->toBeNull()
            // Nothing on the shelf, and nothing in the ledger.
            ->and(StockLevel::query()->where('item_id', $this->widget->id)->exists())->toBeFalse()
            ->and((string) ledgerAccount('1300')->balance())->toBeDecimal('0.0000');
    });

    it('moves the stock and posts on approval', function (): void {
        $this->post('/inventory/adjustments', ($this->adjustmentPayload)());

        $adjustment = InventoryAdjustment::query()->sole();

        $this->post("/inventory/adjustments/{$adjustment->id}/approve")
            ->assertSessionHas('success');

        $level = StockLevel::query()->where('item_id', $this->widget->id)->sole();

        expect($adjustment->refresh()->status)->toBe('approved')
            ->and($level->quantity)->toBeDecimal('40.000000')
            ->and($level->value)->toBeDecimal('1000.0000')
            // The shelf and the control account, written from one figure.
            ->and((string) ledgerAccount('1300')->balance())->toBeDecimal('1000.0000');
    });

    it('insists on a reason', function (): void {
        // An adjustment with no reason is an unexplained change to the value
        // of the business.
        $this->post('/inventory/adjustments', ($this->adjustmentPayload)(['reason' => '']))
            ->assertSessionHasErrors('reason');
    });

    it('works the difference out from a count', function (): void {
        $this->post('/inventory/adjustments', ($this->adjustmentPayload)());
        $first = InventoryAdjustment::query()->sole();
        $this->post("/inventory/adjustments/{$first->id}/approve");

        // 40 on the shelf, 37 counted: the shortfall is worked out here
        // rather than typed, so the two cannot disagree.
        $this->post('/inventory/adjustments', ($this->adjustmentPayload)([
            'kind' => 'quantity',
            'account_id' => $this->writeOffAccount->id,
            'reason' => 'Stocktake, August',
            'lines' => [[
                'item_id' => $this->widget->id,
                'counted_quantity' => '37',
            ]],
        ]));

        $second = InventoryAdjustment::query()
            ->where('reason', 'Stocktake, August')
            ->sole();

        expect($second->lines()->sole()->quantity_change)->toBeDecimal('-3.000000');

        $this->post("/inventory/adjustments/{$second->id}/approve");

        $level = StockLevel::query()->where('item_id', $this->widget->id)->sole();

        expect($level->quantity)->toBeDecimal('37.000000')
            ->and($level->value)->toBeDecimal('925.0000')
            ->and((string) ledgerAccount('1300')->balance())->toBeDecimal('925.0000')
            // The other side landed where the adjustment said it should.
            ->and((string) $this->writeOffAccount->fresh()?->balance())->toBeDecimal('75.0000');
    });

    it('voids by reversal, putting the stock back', function (): void {
        $this->post('/inventory/adjustments', ($this->adjustmentPayload)());
        $adjustment = InventoryAdjustment::query()->sole();
        $this->post("/inventory/adjustments/{$adjustment->id}/approve");

        $this->post("/inventory/adjustments/{$adjustment->id}/void", ['reason' => 'Wrong warehouse'])
            ->assertSessionHas('success');

        $level = StockLevel::query()->where('item_id', $this->widget->id)->sole();

        expect($adjustment->refresh()->status)->toBe('void')
            ->and($adjustment->void_journal_entry_id)->not->toBeNull()
            ->and($level->quantity)->toBeDecimal('0.000000')
            ->and((string) ledgerAccount('1300')->balance())->toBeDecimal('0.0000');
    });
});

describe('transfers', function (): void {
    it('moves stock without posting anything', function (): void {
        $this->post('/inventory/adjustments', ($this->adjustmentPayload)());
        $adjustment = InventoryAdjustment::query()->sole();
        $this->post("/inventory/adjustments/{$adjustment->id}/approve");

        $this->post('/inventory/warehouses', ['code' => 'NORTH', 'name' => 'North store']);
        $north = Warehouse::query()->where('code', 'NORTH')->sole();

        $before = (string) ledgerAccount('1300')->balance();

        $this->post('/inventory/transfers', [
            'transfer_date' => '2026-08-20',
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $north->id,
            'lines' => [['item_id' => $this->widget->id, 'quantity' => '10']],
        ])->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->sole();

        $this->post("/inventory/transfers/{$transfer->id}/complete")
            ->assertSessionHas('success');

        $source = StockLevel::query()
            ->where('item_id', $this->widget->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sole();

        $destination = StockLevel::query()
            ->where('item_id', $this->widget->id)
            ->where('warehouse_id', $north->id)
            ->sole();

        expect($source->quantity)->toBeDecimal('30.000000')
            ->and($source->value)->toBeDecimal('750.0000')
            ->and($destination->quantity)->toBeDecimal('10.000000')
            ->and($destination->value)->toBeDecimal('250.0000')
            // The business owns exactly what it owned before.
            ->and((string) ledgerAccount('1300')->balance())->toBeDecimal($before);
    });

    it('refuses a transfer to the same warehouse at the form', function (): void {
        $this->post('/inventory/transfers', [
            'transfer_date' => '2026-08-20',
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $this->warehouse->id,
            'lines' => [['item_id' => $this->widget->id, 'quantity' => '1']],
        ])->assertSessionHasErrors('from_warehouse_id');
    });
});

describe('authorisation, by role', function (): void {
    it('lets a bookkeeper read stock and not move it', function (): void {
        /*
         * A bookkeeper has `inventory.manage_items` but NOT
         * `inventory.adjust`: they keep the catalogue, and moving stock is
         * the judgement somebody else makes.
         */
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->get('/inventory/items')->assertOk();
        $this->get('/inventory/adjustments')->assertOk();

        $this->post('/inventory/adjustments', ($this->adjustmentPayload)())->assertForbidden();

        $this->post('/inventory/transfers', [
            'transfer_date' => '2026-08-20',
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $this->warehouse->id,
            'lines' => [['item_id' => $this->widget->id, 'quantity' => '1']],
        ])->assertForbidden();

        expect(InventoryAdjustment::query()->count())->toBe(0);

        $this->get('/inventory/adjustments')
            ->assertInertia(fn (Assert $page) => $page->where('can.adjust', false));
    });

    it('shows every warehouse when the filter is not a real id', function (): void {
        /*
         * These ids are uuids, and PostgreSQL rejects a malformed one at the
         * type level rather than returning no rows — so a hand-edited URL or
         * a stale bookmark was a 500 on three screens. A filter that does not
         * name a warehouse means all of them.
         */
        $this->get('/inventory/items?warehouse=all')->assertOk();
        $this->get('/inventory/valuation?warehouse=not-a-uuid')->assertOk();
        $this->get('/inventory/warehouses?warehouse=%20')->assertOk();
    });

    it('explains a stock refusal on issue rather than failing', function (): void {
        /*
         * The commonest mistake in the module — invoicing ten when eight are
         * on the shelf — reached the screen as a 500 rather than as the
         * sentence `StockRefused` writes. A refusal nobody can read is a
         * refusal that gets logged as a bug.
         */
        $invoice = app(SaveSalesDocument::class)->handle(
            type: SalesDocumentType::Invoice,
            attributes: [
                'contact_id' => Contact::query()->create([
                    'kind' => ContactKind::Customer,
                    'display_name' => 'Karachi Retail',
                ])->id,
                'issue_date' => '2026-08-20',
            ],
            lines: [[
                'item_id' => $this->widget->id,
                'description' => 'Widgets',
                'quantity' => '10',
                'unit_price' => '500.00',
            ]],
            actor: $this->owner,
        );

        $this->post("/sales/invoices/{$invoice->number}/issue")
            ->assertRedirect()
            ->assertSessionHas('error');

        expect($invoice->refresh()->status->value)->toBe('draft');
    });

    it('lets an approver read and change nothing', function (): void {
        actingAsMember($this->organization, Role::Approver->value);

        $this->get('/inventory/valuation')->assertOk();

        $this->post('/inventory/warehouses', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->post('/inventory/adjustments', ($this->adjustmentPayload)())->assertForbidden();
    });

    it('404s on another organisation\'s adjustment', function (): void {
        $other = Organization::factory()->create();

        $theirs = app(TenantContext::class)->runAs($other, function () use ($other): InventoryAdjustment {
            withLedger($other);

            $warehouse = app(WarehouseResolver::class)->default();

            $item = Item::query()->create([
                'kind' => ItemKind::Goods,
                'name' => 'Theirs',
                'inventory_account_id' => ledgerAccount('1300')->id,
                'sales_account_id' => ledgerAccount('4010')->id,
                'is_tracked' => true,
                'is_sold' => true,
            ]);

            return app(SaveInventoryAdjustment::class)->handle(
                attributes: [
                    'adjustment_date' => '2026-08-20',
                    'warehouse_id' => $warehouse->id,
                    'account_id' => ledgerAccount('3100')->id,
                    'reason' => 'Theirs',
                ],
                lines: [['item_id' => $item->id, 'quantity_change' => '1', 'unit_cost' => '1']],
            );
        });

        actingAsMember($this->organization, Role::Owner->value);

        $this->get("/inventory/adjustments/{$theirs->id}")->assertNotFound();
        $this->post("/inventory/adjustments/{$theirs->id}/approve")->assertNotFound();
    });
});
