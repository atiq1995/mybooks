<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Catalog\Enums\ItemKind;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Expenses\Actions\ApproveExpense;
use App\Domain\Expenses\Actions\SaveExpense;
use App\Domain\Expenses\Actions\SubmitExpense;
use App\Domain\Expenses\Exceptions\ExpenseRefused;
use App\Domain\Inventory\Actions\ApproveInventoryAdjustment;
use App\Domain\Inventory\Actions\SaveInventoryAdjustment;
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
use App\Domain\Purchases\Actions\VoidPurchaseDocument;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Exceptions\PurchaseDocumentRefused;
use App\Domain\Sales\Actions\IssueSalesDocument;
use App\Domain\Sales\Actions\SaveSalesDocument;
use App\Domain\Sales\Actions\VoidSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|---------------------------------------------------------------------------
| Inventory — undoing things, and the things that must not be possible
|---------------------------------------------------------------------------
|
| Every test here was written against a defect that an adversarial review of
| Phase 8 found and confirmed. They share one shape, because the defects did:
| **the ledger moved and the shelf did not, or they moved by different
| figures.** None of them was visible in today's numbers; all of them broke
| I10 permanently from the day they happened.
|
| The check at the end of most of these is the same one the phase is judged
| by — `my-books:verify-ledger` exiting 0, which compares the stock ledger to
| the inventory control account on every date either side moved.
|
| @see ACCOUNTING_RULES.md I10, §4.6, §4.10, §4.16
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->inventoryAccount = ledgerAccount('1300');
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

    $this->purchaseNo = 0;

    /** Save and approve a bill; returns the document. */
    $this->buy = function (string $quantity, string $unitPrice, string $on) {
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

        return app(ApprovePurchaseDocument::class)->handle($bill, $this->actor);
    };

    /** Save and issue an invoice; returns the document. */
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

    /** I10, as the command states it. */
    $this->booksAgree = fn (): bool => $this->artisan('my-books:verify-ledger')->run() === 0;
});

describe('voiding a bill', function (): void {
    it('takes the goods back off the shelf, not just the money out of the account', function (): void {
        /*
         * The defect this replaces: voiding reversed the bill's entry —
         * crediting 1300 by the full cost — and left the stock sitting
         * there. The control account went to nil while the stock report
         * still showed the goods, and I10 was out by the whole bill from the
         * void date onwards, for ever.
         */
        $bill = ($this->buy)('100', '10.00', '2026-07-05');

        expect(($this->level)()->value)->toBeDecimal('1000.0000');

        app(VoidPurchaseDocument::class)->handle(
            document: $bill,
            actor: $this->actor,
            reason: 'Wrong vendor',
            date: Carbon::parse('2026-07-06'),
        );

        $level = ($this->level)();

        expect($level->quantity)->toBeDecimal('0.000000')
            ->and($level->value)->toBeDecimal('0.0000')
            ->and((string) $this->inventoryAccount->balance())->toBeDecimal('0.0000')
            ->and(($this->booksAgree)())->toBeTrue();
    });

    it('reverses at the value the receipt carried, not at the average since', function (): void {
        /*
         * Buy at 10, buy at 20, then void the first bill. The entry reversal
         * credits 1300 by 1000 — what that bill debited — so the shelf has
         * to give up 1000 too. Valuing the undo at the average of 15 would
         * take 1500 off and leave the two sides 500 apart.
         */
        $first = ($this->buy)('100', '10.00', '2026-07-05');
        ($this->buy)('100', '20.00', '2026-07-10');

        expect(($this->level)()->value)->toBeDecimal('3000.0000');

        app(VoidPurchaseDocument::class)->handle(
            document: $first,
            actor: $this->actor,
            date: Carbon::parse('2026-07-11'),
        );

        $level = ($this->level)();

        expect($level->value)->toBeDecimal('2000.0000')
            ->and($level->quantity)->toBeDecimal('100.000000')
            ->and($level->value)->toBeDecimal((string) $this->inventoryAccount->balance())
            ->and(($this->booksAgree)())->toBeTrue();
    });

    it('lands both halves on the same date when no date is given', function (): void {
        /*
         * The screen sends no date, so this is the path every real void
         * takes — and the one the tests above miss by passing one.
         *
         * Left to themselves the two halves defaulted differently: the entry
         * reversal to today, the stock reversal to the document's own issue
         * date. Everything looked right today and the books were wrong on
         * every date in between, which is the failure this whole phase is
         * about.
         */
        Carbon::setTestNow('2026-09-23');

        $bill = ($this->buy)('100', '10.00', '2026-07-05');

        app(VoidPurchaseDocument::class)->handle(document: $bill, actor: $this->actor);

        $valuation = app(StockValuation::class);

        expect($valuation->totalAsAt(Carbon::parse('2026-08-15')))->toBeDecimal('1000.0000')
            ->and($valuation->totalAsAt(Carbon::parse('2026-09-23')))->toBeDecimal('0.0000')
            ->and(($this->booksAgree)())->toBeTrue();

        Carbon::setTestNow();
    });

    it('refuses when the goods have already been sold', function (): void {
        /*
         * There is nothing dishonest about refusing here. The goods left;
         * pretending the bill never happened would mean taking value off a
         * shelf that no longer holds it, and the only states available are
         * "negative stock" and "a lie". A vendor credit is the document that
         * says what actually happened.
         */
        $bill = ($this->buy)('100', '10.00', '2026-07-05');
        ($this->sell)('100', '30.00', '2026-07-08');

        expect(fn () => app(VoidPurchaseDocument::class)->handle(
            document: $bill,
            actor: $this->actor,
            date: Carbon::parse('2026-07-09'),
        ))->toThrow(StockRefused::class);

        // And nothing was half-done: the refusal rolled the whole void back.
        expect($bill->refresh()->status->value)->not->toBe('void')
            ->and(($this->booksAgree)())->toBeTrue();
    });
});

describe('voiding an invoice', function (): void {
    it('puts the goods back and un-charges the cost', function (): void {
        /*
         * The sale has TWO entries — the invoice, and §4.10's cost entry
         * under its own purpose — and three facts, the third being the
         * goods. Voiding used to undo only the first: the cost stayed
         * charged, the units stayed off the shelf, and profit and inventory
         * were both understated for ever.
         */
        ($this->buy)('100', '10.00', '2026-07-05');
        $invoice = ($this->sell)('40', '30.00', '2026-07-08');

        expect(($this->level)()->quantity)->toBeDecimal('60.000000');

        app(VoidSalesDocument::class)->handle(
            document: $invoice,
            actor: $this->actor,
            reason: 'Raised against the wrong customer',
            date: Carbon::parse('2026-07-09'),
        );

        $level = ($this->level)();

        expect($level->quantity)->toBeDecimal('100.000000')
            ->and($level->value)->toBeDecimal('1000.0000')
            ->and($level->value)->toBeDecimal((string) $this->inventoryAccount->balance())
            ->and((string) ledgerAccount('5010')->balance())->toBeDecimal('0.0000')
            ->and(($this->booksAgree)())->toBeTrue();
    });

    it('reverses the cost entry rather than posting a fresh one', function (): void {
        ($this->buy)('100', '10.00', '2026-07-05');
        $invoice = ($this->sell)('40', '30.00', '2026-07-08');

        $cost = JournalEntry::query()
            ->where('source_type', 'sales_document')
            ->where('source_id', $invoice->id)
            ->where('source_purpose', 'cogs')
            ->sole();

        app(VoidSalesDocument::class)->handle(
            document: $invoice,
            actor: $this->actor,
            date: Carbon::parse('2026-07-09'),
        );

        expect($cost->refresh()->isReversed())->toBeTrue();
    });

    it('lands the sale, the cost and the goods on one date when none is given', function (): void {
        Carbon::setTestNow('2026-09-23');

        ($this->buy)('100', '10.00', '2026-07-05');
        $invoice = ($this->sell)('40', '30.00', '2026-07-08');

        app(VoidSalesDocument::class)->handle(document: $invoice, actor: $this->actor);

        $valuation = app(StockValuation::class);

        // 60 on the shelf while the void has not happened yet, 100 after.
        expect($valuation->totalAsAt(Carbon::parse('2026-08-15')))->toBeDecimal('600.0000')
            ->and($valuation->totalAsAt(Carbon::parse('2026-09-23')))->toBeDecimal('1000.0000')
            ->and(($this->booksAgree)())->toBeTrue();

        Carbon::setTestNow();
    });

    it('leaves an untracked sale alone', function (): void {
        // Nothing moved, so there is nothing to put back — and the void must
        // not invent a stock movement for a service.
        $service = Item::query()->create([
            'kind' => ItemKind::Service,
            'name' => 'Consultancy',
            'sale_price' => '1000.00',
            'sales_account_id' => ledgerAccount('4010')->id,
            'is_tracked' => false,
            'is_sold' => true,
        ]);

        $invoice = app(SaveSalesDocument::class)->handle(
            type: SalesDocumentType::Invoice,
            attributes: ['contact_id' => $this->customer->id, 'issue_date' => '2026-07-08'],
            lines: [[
                'item_id' => $service->id,
                'description' => 'Advice',
                'quantity' => '1',
                'unit_price' => '1000.00',
            ]],
            actor: $this->actor,
        );

        app(IssueSalesDocument::class)->handle($invoice, $this->actor);

        app(VoidSalesDocument::class)->handle(
            document: $invoice,
            actor: $this->actor,
            date: Carbon::parse('2026-07-09'),
        );

        expect(StockMovement::query()->count())->toBe(0)
            ->and(($this->booksAgree)())->toBeTrue();
    });
});

describe('a vendor credit', function (): void {
    it('gives up the value the credit is worth, not the average', function (): void {
        /*
         * The credit's entry credits 1300 by the credit's own cost, because
         * that is what has to balance against the payable. So the shelf must
         * give up the same figure. Letting the weighted average decide had
         * the ledger credit 200 while the shelf gave up 300.
         */
        ($this->buy)('100', '10.00', '2026-07-05');
        ($this->buy)('100', '20.00', '2026-07-10');

        $before = (string) $this->inventoryAccount->balance();

        $credit = app(SavePurchaseDocument::class)->handle(
            type: PurchaseDocumentType::VendorCredit,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => '2026-07-12',
                'vendor_reference' => 'SUP-CN-1',
            ],
            lines: [[
                'item_id' => $this->widget->id,
                'description' => 'Returned faulty',
                'quantity' => '20',
                'unit_price' => '10.00',
            ]],
            actor: $this->actor,
        );

        app(ApprovePurchaseDocument::class)->handle($credit, $this->actor);

        $level = ($this->level)();

        expect($before)->toBeDecimal('3000.0000')
            ->and($level->value)->toBeDecimal('2800.0000')
            ->and($level->quantity)->toBeDecimal('180.000000')
            ->and($level->value)->toBeDecimal((string) $this->inventoryAccount->balance())
            ->and(($this->booksAgree)())->toBeTrue();
    });
});

describe('a foreign-currency bill', function (): void {
    it('puts base-currency value on the shelf', function (): void {
        /*
         * The stock ledger holds one currency — the base one — because that
         * is the only currency the control account is kept in. Recording the
         * document-currency figure had the shelf worth 1,000 against an
         * account debited 280,000, and every later sale posted a cost of
         * goods 280 times too small.
         */
        $bill = app(SavePurchaseDocument::class)->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => '2026-07-05',
                'vendor_reference' => 'SUP-USD-1',
                'currency' => 'USD',
                'exchange_rate' => '280',
            ],
            lines: [[
                'item_id' => $this->widget->id,
                'description' => 'Imported widgets',
                'quantity' => '100',
                'unit_price' => '10.00',
            ]],
            actor: $this->actor,
        );

        app(ApprovePurchaseDocument::class)->handle($bill, $this->actor);

        $level = ($this->level)();

        expect($level->value)->toBeDecimal('280000.0000')
            ->and($level->value)->toBeDecimal((string) $this->inventoryAccount->balance())
            ->and(($this->booksAgree)())->toBeTrue();
    });

    it('lands on the posted figure exactly when the rate does not divide evenly', function (): void {
        // Three lines sharing one account, at a rate that rounds. The entry
        // converts the total once; the shelf has to reach the same total.
        $bill = app(SavePurchaseDocument::class)->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => '2026-07-05',
                'vendor_reference' => 'SUP-USD-2',
                'currency' => 'USD',
                'exchange_rate' => '277.7777',
            ],
            lines: [
                ['item_id' => $this->widget->id, 'description' => 'A', 'quantity' => '3', 'unit_price' => '3.33'],
                ['item_id' => $this->widget->id, 'description' => 'B', 'quantity' => '3', 'unit_price' => '3.33'],
                ['item_id' => $this->widget->id, 'description' => 'C', 'quantity' => '3', 'unit_price' => '3.33'],
            ],
            actor: $this->actor,
        );

        app(ApprovePurchaseDocument::class)->handle($bill, $this->actor);

        expect(($this->level)()->value)
            ->toBeDecimal((string) $this->inventoryAccount->balance())
            ->and(($this->booksAgree)())->toBeTrue();
    });
});

describe('back-dating', function (): void {
    it('values the shelf at a past date from what had happened by then', function (): void {
        /*
         * A bill entered late is dated when it happened, so the movement
         * written SECOND is dated FIRST. Reading the running balance off the
         * latest movement by date therefore returned a figure that was never
         * true on that date — and `verify-ledger` reported a divergence on
         * books that were exactly right, permanently, with nothing to fix.
         */
        ($this->buy)('10', '10.00', '2026-08-05');
        ($this->buy)('5', '20.00', '2026-07-01');

        $valuation = app(StockValuation::class);

        expect($valuation->totalAsAt(Carbon::parse('2026-07-01')))->toBeDecimal('100.0000')
            ->and($valuation->totalAsAt(Carbon::parse('2026-08-05')))->toBeDecimal('200.0000')
            ->and(($this->level)()->value)->toBeDecimal('200.0000')
            ->and(($this->booksAgree)())->toBeTrue();
    });
});

describe('what may post to an inventory account', function (): void {
    it('costs a tracked line to the item stock account whatever was asked for', function (): void {
        /*
         * A tracked line posted to an expense account still puts goods on
         * the shelf. The stock report would then carry value the balance
         * sheet never received, and no amount of correcting afterwards makes
         * the two agree about the past.
         */
        $bill = app(SavePurchaseDocument::class)->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => '2026-07-05',
                'vendor_reference' => 'SUP-MISCODED',
            ],
            lines: [[
                'item_id' => $this->widget->id,
                'description' => 'Widgets',
                'quantity' => '100',
                'unit_price' => '10.00',
                'debit_account_id' => ledgerAccount('5010')->id,
            ]],
            actor: $this->actor,
        );

        expect($bill->lines()->sole()->debit_account_id)->toBe($this->inventoryAccount->id);

        app(ApprovePurchaseDocument::class)->handle($bill, $this->actor);

        expect(($this->booksAgree)())->toBeTrue();
    });

    it('refuses a stock account reached through the item default, not just the typed one', function (): void {
        /*
         * The commonest route to the problem, and the one the first guard
         * missed: nothing is typed on the line, so the account comes from
         * the item's own `purchase_account_id` — a field that legitimately
         * accepts an asset account. Freight pointed at 1300 then walked past
         * a check that was looking at a null.
         */
        $carriage = Item::query()->create([
            'kind' => ItemKind::Service,
            'name' => 'Carriage inwards',
            'purchase_price' => '250.00',
            'purchase_account_id' => $this->inventoryAccount->id,
            'is_tracked' => false,
            'is_purchased' => true,
        ]);

        expect(fn () => app(SavePurchaseDocument::class)->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => '2026-07-05',
                'vendor_reference' => 'SUP-CARRIAGE',
            ],
            lines: [[
                'item_id' => $carriage->id,
                'description' => 'Carriage inwards',
                'quantity' => '1',
                'unit_price' => '250.00',
            ]],
            actor: $this->actor,
        ))->toThrow(PurchaseDocumentRefused::class);
    });

    it('refuses to cost anything else to a stock account', function (): void {
        // Freight coded to 1300 debits the control account without putting
        // anything on a shelf. I10 would be out by the carriage.
        expect(fn () => app(SavePurchaseDocument::class)->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => '2026-07-05',
                'vendor_reference' => 'SUP-FREIGHT',
            ],
            lines: [[
                'description' => 'Carriage inwards',
                'quantity' => '1',
                'unit_price' => '250.00',
                'debit_account_id' => $this->inventoryAccount->id,
            ]],
            actor: $this->actor,
        ))->toThrow(PurchaseDocumentRefused::class);
    });
});

describe('an expense', function (): void {
    it('cannot be costed to an inventory account', function (): void {
        /*
         * The same rule bills follow, and it has to exist on both doors or
         * it exists on neither. An expense has no stock path at all, so one
         * debiting 1300 puts value in the control account that the stock
         * ledger will never account for — and nothing inside inventory can
         * close it afterwards.
         */
        expect(fn () => app(SaveExpense::class)->handle(
            attributes: [
                'expense_date' => '2026-07-10',
                'merchant' => 'Cash and carry',
                'payment_mode' => 'company',
                'paid_through_account_id' => ledgerAccount('1010')->id,
            ],
            lines: [[
                'description' => 'Stock top-up',
                'quantity' => '1',
                'unit_price' => '500.00',
                'debit_account_id' => $this->inventoryAccount->id,
            ]],
            actor: $this->actor,
        ))->toThrow(ExpenseRefused::class);
    });

    it('is refused at approval when the account became a stock account in between', function (): void {
        /*
         * The save-time guard is a snapshot of the world at save. Approval is
         * what posts, and it can be weeks and a submission later — by which
         * time somebody may have pointed a tracked item at the very account
         * this expense was coded to. Checking only at save leaves an expense
         * that cannot be fixed (a submitted expense is not editable) and
         * cannot be approved without breaking I10.
         */
        $spare = Account::query()->where('code', '1500')->sole();

        $expense = app(SaveExpense::class)->handle(
            attributes: [
                'expense_date' => '2026-07-10',
                'merchant' => 'Cash and carry',
                'payment_mode' => 'company',
                'paid_through_account_id' => ledgerAccount('1010')->id,
            ],
            lines: [[
                'description' => 'Sundries',
                'quantity' => '1',
                'unit_price' => '500.00',
                'debit_account_id' => $spare->id,
            ]],
            actor: $this->actor,
        );

        // Only now does that account become an inventory account.
        Item::query()->create([
            'kind' => ItemKind::Goods,
            'sku' => 'FILM',
            'name' => 'Film',
            'purchase_price' => '10.00',
            'purchase_account_id' => ledgerAccount('5010')->id,
            'inventory_account_id' => $spare->id,
            'is_tracked' => true,
            'is_purchased' => true,
        ]);

        app(SubmitExpense::class)->handle($expense, $this->actor);

        expect(fn () => app(ApproveExpense::class)->handle(
            expense: $expense->refresh(),
            actor: User::factory()->create(),
        ))->toThrow(ExpenseRefused::class);

        expect(($this->booksAgree)())->toBeTrue();
    });
});

describe('an account becoming an inventory account', function (): void {
    it('is refused when it already carries value no stock explains', function (): void {
        /*
         * The other end of the expense rule, and the two together leave no
         * order of operations that gets a stray balance inside I10.
         *
         * Naming an account here is what puts it inside the invariant: from
         * that moment `verify-ledger` reconciles its whole history against
         * the goods on the shelf, including what was posted before any item
         * existed. An account with an unrelated balance would start life as a
         * breach only a hand-written journal could close.
         */
        $spare = Account::query()->where('code', '1500')->sole();

        $expense = app(SaveExpense::class)->handle(
            attributes: [
                'expense_date' => '2026-07-10',
                'merchant' => 'Cash and carry',
                'payment_mode' => 'company',
                'paid_through_account_id' => ledgerAccount('1010')->id,
            ],
            lines: [[
                'description' => 'Prepaid rent',
                'quantity' => '1',
                'unit_price' => '500.00',
                'debit_account_id' => $spare->id,
            ]],
            actor: $this->actor,
        );

        app(SubmitExpense::class)->handle($expense, $this->actor);
        app(ApproveExpense::class)->handle($expense->refresh(), User::factory()->create());

        actingAsMember($this->organization, Role::Owner->value);

        $this->from('/sales/items')
            ->post('/sales/items', [
                'kind' => ItemKind::Goods->value,
                'name' => 'Film',
                'sale_price' => '50.00',
                'purchase_price' => '10.00',
                'sales_account_id' => ledgerAccount('4010')->id,
                'purchase_account_id' => ledgerAccount('5010')->id,
                'inventory_account_id' => $spare->id,
                'is_tracked' => true,
                'is_sold' => true,
                'is_purchased' => true,
            ])
            ->assertSessionHasErrors('inventory_account_id');

        expect(Item::query()->where('name', 'Film')->exists())->toBeFalse()
            ->and(($this->booksAgree)())->toBeTrue();
    });
});

describe('the item master', function (): void {
    it('refuses to move an account under movements that have already posted', function (): void {
        /*
         * Both halves of I10 hang off this field. Changing it would leave
         * the old account holding value with no stock and the new one
         * holding stock it was never debited for; clearing `is_tracked`
         * would drop the account out of the reconciliation altogether, which
         * is a checkbox switching off an invariant.
         */
        ($this->buy)('100', '10.00', '2026-07-05');

        $other = Account::query()->where('code', '1200')->sole();

        // Through the screen, because that is where the change arrives from
        // and where it has to be stopped.
        actingAsMember($this->organization, Role::Owner->value);

        $response = $this->from('/sales/items')
            ->patch('/sales/items/'.$this->widget->id, [
                'kind' => ItemKind::Goods->value,
                'name' => 'Widget',
                'sale_price' => '500.00',
                'purchase_price' => '100.00',
                'sales_account_id' => ledgerAccount('4010')->id,
                'purchase_account_id' => ledgerAccount('5010')->id,
                'inventory_account_id' => $other->id,
                'is_tracked' => true,
                'is_sold' => true,
                'is_purchased' => true,
            ]);

        $response->assertSessionHasErrors('is_tracked');

        expect($this->widget->refresh()->inventory_account_id)->toBe($this->inventoryAccount->id)
            ->and(($this->booksAgree)())->toBeTrue();
    });
});

describe('an adjustment across two inventory accounts', function (): void {
    it('posts, even though the two sides net to zero', function (): void {
        /*
         * 500 off one inventory account and 500 on to another nets to zero.
         * Treating that as "no value moved" posted nothing while the stock
         * moved on both, leaving each account permanently out by 500 — and
         * the adjustment showing a value of zero, so nothing looked wrong.
         * The entry for this case is fine; it just has no contra line,
         * because the two inventory lines balance each other.
         */
        // A second stock account, so the two sides of the adjustment land on
        // different accounts and can net each other out.
        $second = Account::query()->where('code', '1500')->sole();

        $gadget = Item::query()->create([
            'kind' => ItemKind::Goods,
            'sku' => 'GADGET',
            'name' => 'Gadget',
            'sale_price' => '500.00',
            'purchase_price' => '100.00',
            'sales_account_id' => ledgerAccount('4010')->id,
            'purchase_account_id' => ledgerAccount('5010')->id,
            'inventory_account_id' => $second->id,
            'is_tracked' => true,
            'is_sold' => true,
            'is_purchased' => true,
        ]);

        ($this->buy)('100', '10.00', '2026-07-05');

        // Bought through a bill as well, so the ledger carries it too.
        $gadgetBill = app(SavePurchaseDocument::class)->handle(
            type: PurchaseDocumentType::Bill,
            attributes: [
                'contact_id' => $this->vendor->id,
                'issue_date' => '2026-07-05',
                'vendor_reference' => 'SUP-GADGET',
            ],
            lines: [[
                'item_id' => $gadget->id,
                'description' => 'Gadgets',
                'quantity' => '100',
                'unit_price' => '10.00',
            ]],
            actor: $this->actor,
        );

        app(ApprovePurchaseDocument::class)->handle($gadgetBill, $this->actor);

        $adjustment = app(SaveInventoryAdjustment::class)->handle(
            attributes: [
                'adjustment_date' => '2026-07-06',
                'warehouse_id' => $this->warehouse->id,
                'kind' => 'revaluation',
                'account_id' => ledgerAccount('5010')->id,
                'reason' => 'Reclassified between stock accounts',
            ],
            lines: [
                ['item_id' => $this->widget->id, 'quantity_change' => '0', 'value_change' => '-500'],
                ['item_id' => $gadget->id, 'quantity_change' => '0', 'value_change' => '500'],
            ],
            actor: $this->actor,
        );

        app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->actor);

        expect($adjustment->refresh()->journal_entry_id)->not->toBeNull()
            ->and((string) $this->inventoryAccount->balance())->toBeDecimal('500.0000')
            ->and((string) $second->balance())->toBeDecimal('1500.0000')
            ->and(($this->booksAgree)())->toBeTrue();
    });
});

describe('refusals that used to be 500s', function (): void {
    it('refuses goods arriving at a negative value', function (): void {
        // Two fields somebody can fill in independently on the adjustment
        // form — quantity in, value negative — and it used to reach the
        // database as a 500 naming a CHECK on unit_cost.
        expect(refused(fn () => app(StockLedger::class)->plan([
            new MovementRequest(
                item: $this->widget,
                warehouseId: $this->warehouse->id,
                kind: StockMovementKind::Adjustment,
                quantity: '10',
                occurredOn: Carbon::parse('2026-07-06'),
                sourceType: 'test',
                totalValue: '-500.00',
            ),
        ])))->toThrow(StockRefused::class);
    });

    it('refuses to revalue a shelf with nothing on it', function (): void {
        // Value with no units under it is a state the database rejects. The
        // user should get a sentence, not a constraint name.
        expect(refused(fn () => app(StockLedger::class)->plan([
            new MovementRequest(
                item: $this->widget,
                warehouseId: $this->warehouse->id,
                kind: StockMovementKind::Revaluation,
                quantity: '0',
                occurredOn: Carbon::parse('2026-07-06'),
                sourceType: 'test',
                totalValue: '250.00',
            ),
        ])))->toThrow(StockRefused::class);
    });

    it('refuses a write-down larger than the stock is worth', function (): void {
        ($this->buy)('10', '10.00', '2026-07-05');

        expect(refused(fn () => app(StockLedger::class)->plan([
            new MovementRequest(
                item: $this->widget,
                warehouseId: $this->warehouse->id,
                kind: StockMovementKind::Revaluation,
                quantity: '0',
                occurredOn: Carbon::parse('2026-07-06'),
                sourceType: 'test',
                totalValue: '-500.00',
            ),
        ])))->toThrow(StockRefused::class);
    });

    it('approves an adjustment that moves quantity but no value', function (): void {
        /*
         * Free samples, or stock already written down to nothing. The ledger
         * refuses a zero-value entry — correctly — so approval posts nothing
         * and the constraint has to allow that, or these can never be
         * approved at all.
         */
        // Brought in at no cost — a zero-total bill cannot be approved, so
        // the stock ledger is driven directly, which is also how a migration
        // from another system would bring in fully written-down goods.
        $ledger = app(StockLedger::class);

        DB::transaction(fn () => $ledger->commit($ledger->plan([
            new MovementRequest(
                item: $this->widget,
                warehouseId: $this->warehouse->id,
                kind: StockMovementKind::Opening,
                quantity: '10',
                occurredOn: Carbon::parse('2026-07-05'),
                sourceType: 'opening',
                unitCost: '0',
            ),
        ])));

        $adjustment = app(SaveInventoryAdjustment::class)->handle(
            attributes: [
                'adjustment_date' => '2026-07-06',
                'warehouse_id' => $this->warehouse->id,
                'kind' => 'quantity',
                'account_id' => ledgerAccount('5010')->id,
                'reason' => 'Damaged in the rain',
            ],
            lines: [[
                'item_id' => $this->widget->id,
                'quantity_change' => '-4',
            ]],
            actor: $this->actor,
        );

        app(ApproveInventoryAdjustment::class)
            ->handle($adjustment, $this->actor);

        expect($adjustment->refresh()->status)->toBe('approved')
            ->and($adjustment->journal_entry_id)->toBeNull()
            ->and(($this->level)()->quantity)->toBeDecimal('6.000000')
            ->and(($this->booksAgree)())->toBeTrue();
    });
});

describe('voiding an adjustment', function (): void {
    it('restores the value it removed, not what the shelf is worth today', function (): void {
        /*
         * The ledger side of this void is a mirror reversal of the original
         * entry. Costing the stock side at the current average instead put
         * the two halves on different numbers whenever the average had moved
         * — and the difference never closed.
         */
        ($this->buy)('100', '10.00', '2026-07-05');

        $adjustment = app(SaveInventoryAdjustment::class)->handle(
            attributes: [
                'adjustment_date' => '2026-07-06',
                'warehouse_id' => $this->warehouse->id,
                'kind' => 'quantity',
                'account_id' => ledgerAccount('5010')->id,
                'reason' => 'Stocktake, July',
            ],
            lines: [[
                'item_id' => $this->widget->id,
                'quantity_change' => '-10',
            ]],
            actor: $this->actor,
        );

        app(ApproveInventoryAdjustment::class)
            ->handle($adjustment, $this->actor);

        expect(($this->level)()->value)->toBeDecimal('900.0000');

        // The average moves a long way before the void.
        ($this->buy)('100', '20.00', '2026-07-10');

        Carbon::setTestNow('2026-09-23');

        app(ApproveInventoryAdjustment::class)
            ->void($adjustment->refresh(), $this->actor, 'Counted again');

        $level = ($this->level)();

        expect($level->quantity)->toBeDecimal('200.000000')
            ->and($level->value)->toBeDecimal('3000.0000')
            ->and($level->value)->toBeDecimal((string) $this->inventoryAccount->balance())
            ->and(($this->booksAgree)())->toBeTrue();

        Carbon::setTestNow();
    });

    it('undoes on the void date, in an open period, with both halves together', function (): void {
        /*
         * §4.15: a reversal is dated in an open period. Dating the undo back
         * at the adjustment would rewrite the period being corrected, and
         * would make the void impossible exactly when it is most needed — a
         * stocktake error found after the month closed.
         *
         * What matters for I10 is that the two halves take the SAME date,
         * whatever it is.
         */
        ($this->buy)('100', '10.00', '2026-07-05');

        $adjustment = app(SaveInventoryAdjustment::class)->handle(
            attributes: [
                'adjustment_date' => '2026-07-06',
                'warehouse_id' => $this->warehouse->id,
                'kind' => 'quantity',
                'account_id' => ledgerAccount('5010')->id,
                'reason' => 'Breakage',
            ],
            lines: [['item_id' => $this->widget->id, 'quantity_change' => '-10']],
            actor: $this->actor,
        );

        app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->actor);

        Carbon::setTestNow('2026-09-23');

        app(ApproveInventoryAdjustment::class)->void($adjustment->refresh(), $this->actor);

        $valuation = app(StockValuation::class);

        // Still written off in August; back on the shelf from the void date.
        expect($valuation->totalAsAt(Carbon::parse('2026-08-15')))->toBeDecimal('900.0000')
            ->and($valuation->totalAsAt(Carbon::parse('2026-09-23')))->toBeDecimal('1000.0000')
            ->and(($this->booksAgree)())->toBeTrue();

        Carbon::setTestNow();
    });
});
