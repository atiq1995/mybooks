<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Enums\ItemKind;
use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Actions\ApproveInventoryAdjustment;
use App\Domain\Inventory\Actions\SaveInventoryAdjustment;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\WarehouseResolver;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| The third balance check
|---------------------------------------------------------------------------
|
| The verifier re-derives every invariant from raw journal lines. Its value is
| entirely in what it catches, so each test here deliberately corrupts the
| ledger — with triggers disabled, the way a hand-written SQL fix at 2am would
| — and asserts the command notices and exits non-zero.
|
| A verifier that has never been shown a corrupt ledger is not a verifier.
|
| @see ACCOUNTING_RULES.md §1, §10
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization);
    $this->actor = User::factory()->create();

    $ar = ledgerAccount(SystemAccount::AccountsReceivable);
    $revenue = Account::query()->where('type', 'income')->where('is_header', false)
        ->whereNull('system_role')->orderBy('code')->firstOrFail();

    $this->entry = app(PostJournalEntry::class)->handle(
        JournalDraft::inBaseCurrency(
            date: Carbon::parse($this->year->starts_on->toDateString())->addMonth(),
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit($ar->id, '50000.0000'),
                JournalLineDraft::credit($revenue->id, '50000.0000'),
            ],
            source: ['invoice', null, 'issue'],
        ),
        $this->actor,
    );
});

/**
 * Corrupt the ledger the way a direct SQL fix would: triggers off, one value
 * changed, triggers back on. Nothing in the application can do this — which is
 * exactly why the verifier has to.
 */
function withTriggersDisabled(Closure $callback): void
{
    /*
     * The balance check is a deferred constraint trigger, so the entry posted
     * in beforeEach leaves a pending trigger event and PostgreSQL refuses to
     * ALTER a table that has one. Forcing constraints immediate settles them
     * — and incidentally proves the posted entry was sound before we corrupt
     * it.
     */
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    DB::unprepared('ALTER TABLE journal_lines DISABLE TRIGGER USER');
    DB::unprepared('ALTER TABLE journal_entries DISABLE TRIGGER USER');

    try {
        $callback();
    } finally {
        DB::unprepared('ALTER TABLE journal_lines ENABLE TRIGGER USER');
        DB::unprepared('ALTER TABLE journal_entries ENABLE TRIGGER USER');
    }
}

it('passes on a ledger posted entirely through the domain', function (): void {
    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('Every invariant holds')
        ->assertExitCode(0);
});

it('passes when there is nothing posted at all', function (): void {
    withTriggersDisabled(function (): void {
        DB::table('journal_lines')->delete();
        DB::table('journal_entries')->delete();
    });

    $this->artisan('my-books:verify-ledger')->assertExitCode(0);
});

it('catches an entry whose lines no longer balance', function (): void {
    withTriggersDisabled(function (): void {
        DB::table('journal_lines')
            ->where('journal_entry_id', $this->entry->id)
            ->where('debit', '>', 0)
            ->update(['debit' => '49999.0000', 'debit_base' => '49999.0000']);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('entry_balances')
        ->assertExitCode(1);
});

it('catches a header that disagrees with its lines', function (): void {
    withTriggersDisabled(function (): void {
        DB::table('journal_entries')->where('id', $this->entry->id)->update([
            'total_debit' => '60000.0000',
            'total_credit' => '60000.0000',
        ]);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('header_matches_lines')
        ->assertExitCode(1);
});

it('catches a line with a debit and a credit at once', function (): void {
    withTriggersDisabled(function (): void {
        DB::unprepared(
            'ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_one_side_only'
        );

        DB::table('journal_lines')
            ->where('journal_entry_id', $this->entry->id)
            ->where('debit', '>', 0)
            ->update(['credit' => '50000.0000']);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('line_sides')
        ->assertExitCode(1);
});

it('catches a trial balance that does not balance', function (): void {
    /*
     * The single most important number in the system. A one-sided line makes
     * the whole ledger's debits and credits disagree, which is the state that
     * stops a balance sheet from balancing.
     */
    withTriggersDisabled(function (): void {
        DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->getKey(),
            'journal_entry_id' => $this->entry->id,
            'line_no' => 99,
            'account_id' => ledgerAccount(SystemAccount::AccountsReceivable)->id,
            'debit' => '1.0000',
            'credit' => '0.0000',
            'debit_base' => '1.0000',
            'credit_base' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('trial_balance')
        ->assertExitCode(1);
});

it('catches a line whose organisation differs from its entry', function (): void {
    $other = Organization::factory()->create();

    withTriggersDisabled(function () use ($other): void {
        DB::table('journal_lines')
            ->where('journal_entry_id', $this->entry->id)
            ->limit(1)
            ->update(['organization_id' => $other->getKey()]);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('cross_tenant_lines')
        ->assertExitCode(1);
});

it('reports machine-readable output when asked', function (): void {
    $this->artisan('my-books:verify-ledger --json')->assertExitCode(0);

    withTriggersDisabled(function (): void {
        DB::table('journal_entries')->where('id', $this->entry->id)->update([
            'total_debit' => '1.0000',
            'total_credit' => '1.0000',
        ]);
    });

    $this->artisan('my-books:verify-ledger --json')
        ->expectsOutputToContain('"status": "discrepancies"')
        ->assertExitCode(1);
});

it('can verify a single organisation by slug', function (): void {
    $untouched = Organization::factory()->create();

    withTriggersDisabled(function (): void {
        DB::table('journal_entries')->where('id', $this->entry->id)->update([
            'total_debit' => '1.0000',
            'total_credit' => '1.0000',
        ]);
    });

    // The clean organisation passes on its own...
    $this->artisan('my-books:verify-ledger', ['--organization' => $untouched->slug])
        ->assertExitCode(0);

    // ...while the corrupted one still fails.
    $this->artisan('my-books:verify-ledger', ['--organization' => $this->organization->slug])
        ->assertExitCode(1);
});

/*
|---------------------------------------------------------------------------
| I10 — stock valuation equals the inventory control account
|---------------------------------------------------------------------------
|
| Phase 8's exit criterion, and the checks that hold it up. Both halves are
| here, because they fail in different ways: the projection can drift from the
| movements behind it, and the movements can drift from the ledger.
*/

it('passes on stock bought and sold entirely through the domain', function (): void {
    [$item] = trackedItemWithStock();

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('Every invariant holds')
        ->assertExitCode(0);

    expect($item->is_tracked)->toBeTrue();
});

it('catches a stock level that no longer agrees with its movements', function (): void {
    /*
     * `stock_levels` is a cache of the last movement's running balance, kept
     * because it is the row every writer locks. This is what stops the cache
     * being a second source of truth.
     */
    [, $level] = trackedItemWithStock();

    DB::table('stock_levels')->where('id', $level->id)->update(['quantity' => '999']);

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('the movements say')
        ->assertExitCode(1);
});

it('catches movements with no level row behind them at all', function (): void {
    /*
     * The shape a half-restored backup leaves: `stock_movements` from one
     * dump, `stock_levels` from an earlier one.
     *
     * A check anchored on `stock_levels` can only ever ask "is this level
     * right", so it cannot see a pair that has movements and no level — which
     * is the case it most needs to see. Working from the union of pairs in
     * both tables is what closes it.
     */
    [, $level] = trackedItemWithStock();

    DB::table('stock_levels')->where('id', $level->id)->delete();

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('the level says 0')
        ->assertExitCode(1);
});

it('catches a level pointing at a movement that is not its own', function (): void {
    /*
     * A different fault from drift: the figures can be right while the
     * pointer names somebody else's row, or nothing at all. Its own check,
     * because reporting it through the drift message would mean printing
     * movement totals nobody measured.
     *
     * The pointer is corrupted rather than the movement, because the
     * append-only trigger refuses to let a movement be updated or deleted.
     */
    [, $level] = trackedItemWithStock();

    DB::table('stock_levels')
        ->where('id', $level->id)
        ->update(['last_movement_id' => (string) Str::uuid7()]);

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('names a last movement')
        ->assertExitCode(1);
});

it('catches stock that no longer agrees with the inventory account', function (): void {
    trackedItemWithStock();

    // A movement inserted behind the application's back — the shelf now says
    // more than the ledger ever debited.
    $movement = DB::table('stock_movements')->orderByDesc('created_at')->first();

    DB::table('stock_movements')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->organization->getKey(),
        'item_id' => $movement->item_id,
        'warehouse_id' => $movement->warehouse_id,
        'occurred_on' => '2026-08-01',
        'kind' => 'adjustment',
        'quantity' => '5',
        'unit_cost' => '10',
        'value' => '50.0000',
        'quantity_after' => bcadd((string) $movement->quantity_after, '5', 6),
        'value_after' => bcadd((string) $movement->value_after, '50', 4),
        'unit_cost_after' => '10',
        'source_type' => 'smuggled',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('stock_levels')
        ->where('item_id', $movement->item_id)
        ->update([
            'quantity' => bcadd((string) $movement->quantity_after, '5', 6),
            'value' => bcadd((string) $movement->value_after, '50', 4),
        ]);

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('but stock is worth')
        ->assertExitCode(1);
});

it('reports the FIRST date the two sides diverged, not just today', function (): void {
    /*
     * "At every point in time" is the exit criterion's own phrasing, and it
     * is there because a check of today's figures passes on books that were
     * wrong for six months and were accidentally corrected.
     */
    [, $level] = trackedItemWithStock();

    $movement = DB::table('stock_movements')->orderBy('occurred_on')->first();

    DB::table('stock_movements')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->organization->getKey(),
        'item_id' => $movement->item_id,
        'warehouse_id' => $movement->warehouse_id,
        // Dated BEFORE everything else, and reversed out by nothing.
        'occurred_on' => '2026-07-01',
        'kind' => 'adjustment',
        'quantity' => '1',
        'unit_cost' => '10',
        'value' => '10.0000',
        'quantity_after' => '1',
        'value_after' => '10.0000',
        'unit_cost_after' => '10',
        'source_type' => 'smuggled',
        'created_at' => now()->subYear(),
        'updated_at' => now(),
    ]);

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('As at 2026-07-01')
        ->assertExitCode(1);

    expect($level->quantity)->not->toBeNull();
});

/**
 * A tracked item with stock bought in and some of it sold, all through the
 * domain — so anything the verifier then reports is a corruption this test
 * introduced rather than a defect in the actions.
 *
 * @return array{0: Item, 1: StockLevel}
 */
function trackedItemWithStock(): array
{
    $item = Item::query()->create([
        'kind' => ItemKind::Goods,
        'name' => 'Widget',
        'sales_account_id' => ledgerAccount('4010')->id,
        'inventory_account_id' => ledgerAccount('1300')->id,
        'is_tracked' => true,
        'is_sold' => true,
    ]);

    $warehouse = app(WarehouseResolver::class)->default();

    $adjustment = app(SaveInventoryAdjustment::class)->handle(
        attributes: [
            'adjustment_date' => '2026-08-05',
            'warehouse_id' => $warehouse->id,
            'kind' => 'opening',
            'account_id' => ledgerAccount('3100')->id,
            'reason' => 'Opening stock',
        ],
        lines: [['item_id' => $item->id, 'quantity_change' => '20', 'unit_cost' => '10']],
    );

    app(ApproveInventoryAdjustment::class)->handle($adjustment);

    $level = StockLevel::query()
        ->where('item_id', $item->id)
        ->sole();

    return [$item, $level];
}
