<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Catalog\Enums\ItemKind;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Inventory\Actions\ShipSalesDocument;
use App\Domain\Inventory\Data\MovementRequest;
use App\Domain\Inventory\Enums\StockMovementKind;
use App\Domain\Inventory\Exceptions\StockRefused;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockValuation;
use App\Domain\Inventory\Services\WarehouseResolver;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Purchases\Actions\ApprovePurchaseDocument;
use App\Domain\Purchases\Actions\SavePurchaseDocument;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Sales\Actions\IssueSalesDocument;
use App\Domain\Sales\Actions\SaveSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| Inventory
|---------------------------------------------------------------------------
|
| Phase 8's exit criterion is a single sentence — **stock valuation matches
| the inventory control account exactly, at every point in time** — and
| everything here exists to hold it up:
|
|   buying stock DEBITS inventory rather than an expense, so the ledger and
|   the shelf are written from one figure;
|
|   selling it moves the cost out at the weighted average of that moment,
|   posting §4.10's entry at the shipment date rather than the invoice date;
|
|   the two can never drift, and `verify-ledger` says so for every date on
|   which either side moved — not only for today.
|
| @see ACCOUNTING_RULES.md I10, §4.6, §4.10
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->inventoryAccount = ledgerAccount('1300');
    $this->cogsAccount = ledgerAccount('5010');

    $this->warehouse = app(WarehouseResolver::class)->default();

    $this->widget = Item::query()->create([
        'kind' => ItemKind::Goods,
        'sku' => 'WIDGET',
        'name' => 'Widget',
        'unit' => 'each',
        'sale_price' => '500.00',
        'purchase_price' => '100.00',
        'sales_account_id' => ledgerAccount('4010')->id,
        'purchase_account_id' => ledgerAccount('5010')->id,
        'inventory_account_id' => $this->inventoryAccount->id,
        'is_tracked' => true,
        'is_sold' => true,
        'is_purchased' => true,
    ]);

    $this->vendor = Contact::query()->create([
        'kind' => ContactKind::Vendor,
        'display_name' => 'Widget Supplies',
    ]);

    $this->customer = Contact::query()->create([
        'kind' => ContactKind::Customer,
        'display_name' => 'Karachi Retail',
    ]);

    // A vendor refuses two bills with the same reference — that is how a
    // bill gets paid twice — so each purchase here gets its own.
    $this->purchaseNo = 0;

    /** Buy $quantity widgets at $unitPrice each, and approve the bill. */
    $this->buy = function (string $quantity, string $unitPrice, string $on): void {
        $bill = app(SavePurchaseDocument::class)->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => $on,
                'vendor_reference' => 'SUP-'.(++$this->purchaseNo),
            ],
            lines: [[
                'item_id' => $this->widget->id,
                'description' => 'Widgets',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]],
            actor: $this->actor,
        );

        app(ApprovePurchaseDocument::class)->handle($bill, $this->actor);
    };

    /** Sell $quantity widgets, and issue the invoice. */
    $this->sell = function (string $quantity, string $unitPrice, string $on) {
        $invoice = app(SaveSalesDocument::class)->handle(
            type: SalesDocumentType::Invoice,
            attributes: [
                'contact_id' => $this->customer->id,
                'issue_date' => $on,
            ],
            lines: [[
                'item_id' => $this->widget->id,
                'description' => 'Widgets',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]],
            actor: $this->actor,
        );

        return app(IssueSalesDocument::class)->handle($invoice, $this->actor);
    };

    $this->level = fn (): StockLevel => StockLevel::query()
        ->where('item_id', $this->widget->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->sole();
});

describe('buying stock', function (): void {
    it('debits inventory rather than an expense', function (): void {
        /*
         * §4.6: "5xxx Expense **or** 1300 Inventory". Buying stock is not a
         * cost yet — it becomes one when the goods go out. Sending it to an
         * expense would charge the whole purchase against the month it was
         * bought in and leave the inventory account permanently understated.
         */
        ($this->buy)('100', '10.00', '2026-07-05');

        $entry = JournalEntry::query()->latest('entry_no')->sole();
        $lines = entryLines($entry);

        expect($lines['1300']['debit'])->toBeDecimal('1000.0000')
            ->and($lines)->not->toHaveKey('5010');
    });

    it('puts the same figure on the shelf as in the ledger', function (): void {
        ($this->buy)('100', '10.00', '2026-07-05');

        $level = ($this->level)();

        expect($level->quantity)->toBeDecimal('100.000000')
            ->and($level->value)->toBeDecimal('1000.0000')
            ->and($level->average_cost)->toBeDecimal('10.0000000000')
            ->and($level->value)->toBeDecimal((string) $this->inventoryAccount->balance());
    });

    it('averages two purchases at different prices', function (): void {
        /*
         * 100 at 10 then 100 at 14 is 200 worth 2,400 — an average of 12.
         * Not 10, not 14, and not the last price paid.
         */
        ($this->buy)('100', '10.00', '2026-07-05');
        ($this->buy)('100', '14.00', '2026-08-05');

        $level = ($this->level)();

        expect($level->quantity)->toBeDecimal('200.000000')
            ->and($level->value)->toBeDecimal('2400.0000')
            ->and($level->average_cost)->toBeDecimal('12.0000000000');
    });

    it('records a movement that can be traced back to the bill', function (): void {
        ($this->buy)('100', '10.00', '2026-07-05');

        $movement = StockMovement::query()->sole();

        expect($movement->kind->value)->toBe('receipt')
            ->and($movement->source_type)->toBe('purchase_document')
            ->and($movement->journal_entry_id)->not->toBeNull()
            ->and($movement->occurred_on->toDateString())->toBe('2026-07-05');
    });
});

describe('selling stock', function (): void {
    it('posts §4.10 at the weighted average, as its own entry', function (): void {
        ($this->buy)('100', '10.00', '2026-07-05');
        ($this->buy)('100', '14.00', '2026-08-05');

        $invoice = ($this->sell)('50', '30.00', '2026-09-05');

        /*
         * Two entries, not one: the invoice recognises revenue at the price
         * the customer pays, and the cost entry recognises what the goods
         * cost us. 50 at the average of 12 is 600.
         */
        $cogsEntry = JournalEntry::query()
            ->where('source_type', 'sales_document')
            ->where('source_purpose', 'cogs')
            ->sole();

        $lines = entryLines($cogsEntry);

        expect($lines['5010']['debit'])->toBeDecimal('600.0000')
            ->and($lines['1300']['credit'])->toBeDecimal('600.0000')
            ->and($cogsEntry->entry_date->toDateString())->toBe('2026-09-05')
            ->and($invoice->journal_entry_id)->not->toBe($cogsEntry->id);
    });

    it('leaves the shelf and the control account agreeing', function (): void {
        ($this->buy)('100', '10.00', '2026-07-05');
        ($this->buy)('100', '14.00', '2026-08-05');
        ($this->sell)('50', '30.00', '2026-09-05');

        $level = ($this->level)();

        expect($level->quantity)->toBeDecimal('150.000000')
            ->and($level->value)->toBeDecimal('1800.0000')
            // The average does not move when stock goes out.
            ->and($level->average_cost)->toBeDecimal('12.0000000000')
            ->and($level->value)->toBeDecimal((string) $this->inventoryAccount->balance());
    });

    it('empties the value exactly when the last unit leaves', function (): void {
        /*
         * Three units bought for 10 is an average of 3.3333333333. Selling
         * them one at a time at that rate leaves a fraction behind on every
         * movement, and a shelf holding nothing but 0.0001 of value is both
         * meaningless and a permanent difference from the ledger. The last
         * unit takes the whole remaining value with it.
         */
        ($this->buy)('3', '3.3333333333', '2026-07-05');

        ($this->sell)('1', '30.00', '2026-07-10');
        ($this->sell)('1', '30.00', '2026-07-11');
        ($this->sell)('1', '30.00', '2026-07-12');

        $level = ($this->level)();

        expect($level->quantity)->toBeDecimal('0.000000')
            ->and($level->value)->toBeDecimal('0.0000')
            ->and($level->average_cost)->toBeDecimal('0.0000000000')
            ->and((string) $this->inventoryAccount->balance())->toBeDecimal('0.0000');
    });

    it('refuses to ship stock that is not there', function (): void {
        /*
         * The invariant everything else rests on. Goods that are not on the
         * shelf have no cost to assign, and the inventory account would carry
         * a credit balance no balance sheet can show.
         */
        ($this->buy)('10', '10.00', '2026-07-05');

        expect(fn () => ($this->sell)('11', '30.00', '2026-07-10'))
            ->toThrow(StockRefused::class, 'not enough');

        // And nothing was half-written: the invoice rolled back with it.
        expect(($this->level)()->quantity)->toBeDecimal('10.000000');
    });

    it('posts no cost entry for stock that cost nothing', function (): void {
        /*
         * Free samples, or stock written down to nothing. A zero-value entry
         * is refused by the ledger outright, so the shipment records the
         * quantity leaving and posts nothing at all — which is right: no
         * value moved.
         */
        app(StockLedger::class)->commit(
            app(StockLedger::class)->plan([
                new MovementRequest(
                    item: $this->widget,
                    warehouseId: $this->warehouse->id,
                    kind: StockMovementKind::Opening,
                    quantity: '10',
                    occurredOn: Carbon::parse('2026-07-05'),
                    sourceType: 'test',
                    sourceId: (string) Str::uuid7(),
                    unitCost: '0',
                ),
            ]),
        );

        ($this->sell)('5', '30.00', '2026-07-10');

        expect(JournalEntry::query()->where('source_purpose', 'cogs')->count())->toBe(0)
            ->and(($this->level)()->quantity)->toBeDecimal('5.000000')
            ->and(($this->level)()->value)->toBeDecimal('0.0000');
    });

    it('does not despatch the same invoice twice', function (): void {
        ($this->buy)('100', '10.00', '2026-07-05');

        $invoice = ($this->sell)('10', '30.00', '2026-07-10');

        // Issuing is refused on its own terms, but the guard that matters is
        // the one on the stock side: the cost must not be charged twice.
        expect(app(ShipSalesDocument::class)->hasShipped($invoice))->toBeTrue()
            ->and(StockMovement::query()->where('kind', 'shipment')->count())->toBe(1);
    });
});

describe('valuation', function (): void {
    it('answers what stock was worth at a date, not only today', function (): void {
        ($this->buy)('100', '10.00', '2026-07-05');
        ($this->buy)('100', '14.00', '2026-08-05');
        ($this->sell)('50', '30.00', '2026-09-05');

        $valuation = app(StockValuation::class);

        expect($valuation->totalAsAt(Carbon::parse('2026-07-31')))->toBeDecimal('1000.0000')
            ->and($valuation->totalAsAt(Carbon::parse('2026-08-31')))->toBeDecimal('2400.0000')
            ->and($valuation->totalAsAt(Carbon::parse('2026-09-30')))->toBeDecimal('1800.0000')
            // Before anything happened there was nothing.
            ->and($valuation->totalAsAt(Carbon::parse('2026-07-01')))->toBeDecimal('0.0000');
    });

    it('matches the control account at every one of those dates', function (): void {
        ($this->buy)('100', '10.00', '2026-07-05');
        ($this->buy)('100', '14.00', '2026-08-05');
        ($this->sell)('50', '30.00', '2026-09-05');

        $valuation = app(StockValuation::class);

        foreach (['2026-07-31', '2026-08-31', '2026-09-30'] as $date) {
            $asOf = Carbon::parse($date);

            $ledger = Account::query()
                ->whereKey($this->inventoryAccount->id)
                ->sole()
                ->balance($asOf);

            expect($valuation->totalAsAt($asOf))->toBeDecimal((string) $ledger);
        }
    });
});
