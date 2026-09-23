<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory: warehouses, a movement sub-ledger, and the documents that write
 * to it.
 *
 * The shape of this schema follows from one requirement — **stock valuation
 * matches the inventory control account exactly, at every point in time** —
 * and that requirement rules out the obvious design.
 *
 * The obvious design keeps a quantity and a cost on the item and updates them
 * as things move. It cannot answer "what was this worth on 30 June", it loses
 * the history that explains a weighted average, and the first concurrent
 * shipment corrupts it silently.
 *
 * So inventory is kept the way the ledger is kept: an **append-only sub-ledger
 * of movements**, each one carrying the running quantity, value and average
 * cost that resulted from it. Valuation at a date is a lookup, the arithmetic
 * behind any average is visible, and a trigger refuses to let a movement be
 * edited after the fact. `stock_levels` alongside it is a projection of the
 * latest movement — and, more importantly, the row every writer locks, which
 * is what makes a weighted average correct when two shipments race.
 *
 * Value is per **(item, warehouse)**. A transfer then moves value with the
 * goods rather than leaving it behind, and a warehouse can be valued on its
 * own. A single average across warehouses would make both of those
 * meaningless.
 *
 * @see ACCOUNTING_RULES.md I10, §4.6, §4.10
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Where stock physically sits.
         *
         * Every organisation gets one by default — a business with a single
         * store still has a warehouse, it just never thinks about it, and
         * making the concept optional would mean every movement carrying a
         * nullable location and every report having to decide what null
         * means.
         */
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->string('code', 20);
            $table->string('name', 120);
            $table->text('address')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->uuid('created_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        /*
         * The inventory sub-ledger. Append-only, like the journal it mirrors.
         *
         * `quantity` and `value` are SIGNED — positive in, negative out — for
         * the same reason a statement line is: it is the direction, and a
         * separate flag would let the two disagree.
         *
         * The three `_after` columns make the weighted average auditable:
         * every movement shows the quantity, value and unit cost it produced,
         * so the arithmetic behind any average can be followed row by row.
         *
         * They are the state after N WRITES, not after a date. Back-dating is
         * supported — a bill entered late puts its stock where its ledger
         * entry is — so the `_after` chain is not in date order and must never
         * be used to answer "what was this worth on 30 June". That question is
         * `SUM(value) WHERE occurred_on <= '30 June'`, which is computed on
         * exactly the same basis as the ledger's own
         * `SUM(debit_base - credit_base)`, and is therefore the only form in
         * which I10 can be stated honestly.
         */
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->uuid('item_id');
            $table->uuid('warehouse_id');

            $table->date('occurred_on');

            /*
             * 'receipt'      bought in, at cost
             * 'shipment'     sold out, at weighted average
             * 'return_in'    a customer sent goods back
             * 'return_out'   we sent goods back to a vendor
             * 'adjustment'   a count, a write-off, a breakage
             * 'revaluation'  value changed, quantity did not
             * 'transfer_in'  / 'transfer_out' between warehouses
             * 'opening'      brought forward from a previous system
             */
            $table->string('kind', 16);

            $table->decimal('quantity', 19, 6);
            // Ten places, like an exchange rate: a unit cost is a ratio, and
            // rounding it to money precision would lose a fraction on every
            // unit of a thousand-unit receipt.
            $table->decimal('unit_cost', 19, 10)->default(0);
            $table->decimal('value', 19, 4);

            $table->decimal('quantity_after', 19, 6);
            $table->decimal('value_after', 19, 4);
            $table->decimal('unit_cost_after', 19, 10);

            /*
             * What caused it. A movement always has a cause — a bill, an
             * invoice, an adjustment — and being able to go from a valuation
             * back to the document is most of what makes a stock figure
             * defensible.
             */
            $table->string('source_type', 40);
            $table->uuid('source_id')->nullable();
            $table->uuid('source_line_id')->nullable();

            // The entry that carried the value, where the movement had an
            // accounting effect. A transfer between warehouses has none.
            $table->uuid('journal_entry_id')->nullable();

            /*
             * The inventory account this movement's value was posted to, as
             * it stood AT THE TIME.
             *
             * Not derivable from the item afterwards: `items.inventory_
             * account_id` is editable, and I10 asks what the books did, not
             * what the item master says today. Without this column, changing
             * an item's account silently re-attributes its whole history to
             * the new account — and clearing `is_tracked` drops the account
             * out of the check altogether, which switches the invariant off
             * with a checkbox.
             *
             * Null for a movement with no accounting effect (a transfer).
             */
            $table->uuid('inventory_account_id')->nullable();

            $table->string('memo', 255)->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('inventory_account_id')->references('id')->on('accounts');

            $table->index(['organization_id', 'item_id', 'warehouse_id', 'occurred_on']);
            $table->index(['organization_id', 'source_type', 'source_id']);
            $table->index(['organization_id', 'occurred_on']);
            // `verify-ledger` reconciles per account, per date, over this.
            $table->index(['organization_id', 'inventory_account_id', 'occurred_on']);
        });

        /*
         * Where each item stands, per warehouse.
         *
         * A projection of the last movement — and it would be a second source
         * of truth, which this codebase does not otherwise permit, were it not
         * for what it is actually for: **the row every writer locks**.
         *
         * A weighted average read-modify-writes the running value. Two
         * shipments of the same item at the same moment, with no lock, both
         * read the same average and both write a value computed from a state
         * that no longer exists — and the inventory account and the stock
         * silently part company. `SELECT … FOR UPDATE` on this row is what
         * serialises them, exactly as the document-sequence row serialises
         * numbering.
         *
         * `verify-ledger` checks it against the movements and against the
         * control account, so a projection that drifted cannot stay hidden.
         */
        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->uuid('item_id');
            $table->uuid('warehouse_id');

            $table->decimal('quantity', 19, 6)->default(0);
            $table->decimal('value', 19, 4)->default(0);
            $table->decimal('average_cost', 19, 10)->default(0);

            $table->uuid('last_movement_id')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();

            $table->unique(['item_id', 'warehouse_id']);
            $table->index(['organization_id', 'item_id']);
        });

        /*
         * A stock adjustment: a count that disagreed, a breakage, a write-off,
         * or opening stock brought in from a previous system.
         *
         * It is a DOCUMENT with an approval step rather than a direct edit,
         * because it is the one way stock changes without a sale or a
         * purchase behind it — which makes it the one way somebody could
         * quietly make a shortfall disappear. §8: it posts on approval.
         */
        Schema::create('inventory_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->string('number', 30);
            $table->date('adjustment_date');
            $table->uuid('warehouse_id');

            // 'quantity' | 'revaluation' | 'opening'
            $table->string('kind', 16)->default('quantity');

            // Where the other side goes: a write-off expense, a variance
            // account, or opening balance equity.
            $table->uuid('account_id');

            // Mandatory. An adjustment with no reason is an unexplained
            // change to the value of the business.
            $table->string('reason', 255);
            $table->text('notes')->nullable();

            // 'draft' | 'approved' | 'void'
            $table->string('status', 12)->default('draft');

            $table->decimal('total_value', 19, 4)->default(0);

            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('void_journal_entry_id')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('void_journal_entry_id')->references('id')->on('journal_entries');

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status', 'adjustment_date']);
        });

        Schema::create('inventory_adjustment_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('inventory_adjustment_id');

            $table->unsignedSmallInteger('line_no');

            $table->uuid('item_id');

            /*
             * What was counted, and what that means as a change.
             *
             * Both are kept. A stock count records the counted figure and the
             * system works out the difference; a write-off records the
             * difference directly. Storing only the difference would lose
             * what the person actually saw on the shelf, which is the part
             * anybody auditing the adjustment wants.
             */
            $table->decimal('counted_quantity', 19, 6)->nullable();
            $table->decimal('quantity_change', 19, 6)->default(0);

            // For a revaluation: the new total value, and the change it makes.
            $table->decimal('value_change', 19, 4)->default(0);
            $table->decimal('unit_cost', 19, 10)->nullable();

            $table->string('memo', 255)->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('inventory_adjustment_id')->references('id')->on('inventory_adjustments')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');

            $table->unique(['inventory_adjustment_id', 'line_no']);
            $table->index(['organization_id', 'item_id']);
        });

        /*
         * Moving stock between warehouses.
         *
         * It posts NOTHING where both warehouses share an inventory account,
         * which is the normal case: the business owns exactly what it owned
         * before, in a different place. Value travels with the goods at the
         * source warehouse's weighted average, so neither side is restated.
         */
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->string('number', 30);
            $table->date('transfer_date');

            $table->uuid('from_warehouse_id');
            $table->uuid('to_warehouse_id');

            // 'draft' | 'completed'
            $table->string('status', 12)->default('draft');

            $table->decimal('total_value', 19, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('from_warehouse_id')->references('id')->on('warehouses');
            $table->foreign('to_warehouse_id')->references('id')->on('warehouses');

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status', 'transfer_date']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('stock_transfer_id');

            $table->unsignedSmallInteger('line_no');

            $table->uuid('item_id');
            $table->decimal('quantity', 19, 6);
            // Filled in when the transfer completes: the source warehouse's
            // average at that moment, which is what the destination receives.
            $table->decimal('unit_cost', 19, 10)->nullable();
            $table->decimal('value', 19, 4)->default(0);

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('stock_transfer_id')->references('id')->on('stock_transfers')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items');

            $table->unique(['stock_transfer_id', 'line_no']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE warehouses
                ADD CONSTRAINT warehouses_code_not_blank
                    CHECK (btrim(code) <> ''),
                ADD CONSTRAINT warehouses_name_not_blank
                    CHECK (btrim(name) <> '');

            ALTER TABLE stock_movements
                ADD CONSTRAINT stock_movements_kind_known
                    CHECK (kind IN ('receipt','shipment','return_in','return_out',
                                    'adjustment','revaluation','transfer_in','transfer_out','opening')),
                /*
                 * A movement that changes neither quantity nor value is not a
                 * movement. Recording one would put a row in the history that
                 * explains nothing and a reader would go looking for what it
                 * meant.
                 */
                ADD CONSTRAINT stock_movements_changes_something
                    CHECK (quantity <> 0 OR value <> 0),
                /*
                 * The invariant that protects everything downstream:
                 * **stock never goes negative.**
                 *
                 * Shipping goods that are not there makes a weighted average
                 * undefined — there is no cost to assign — and the inventory
                 * account would carry a credit balance, which is not a thing
                 * a balance sheet can show. The refusal belongs here rather
                 * than only in PHP: a future import path or a stray write
                 * meets it too.
                 */
                ADD CONSTRAINT stock_movements_never_negative
                    CHECK (quantity_after >= 0 AND value_after >= 0),
                ADD CONSTRAINT stock_movements_unit_cost_non_negative
                    CHECK (unit_cost >= 0 AND unit_cost_after >= 0),
                /*
                 * Nothing on hand is worth nothing. The converse is not true:
                 * stock can legitimately sit at zero value after a full
                 * write-down while the units are still on the shelf.
                 */
                ADD CONSTRAINT stock_movements_empty_is_worthless
                    CHECK (quantity_after > 0 OR value_after = 0);

            ALTER TABLE stock_levels
                ADD CONSTRAINT stock_levels_never_negative
                    CHECK (quantity >= 0 AND value >= 0 AND average_cost >= 0),
                ADD CONSTRAINT stock_levels_empty_is_worthless
                    CHECK (quantity > 0 OR value = 0);

            ALTER TABLE inventory_adjustments
                ADD CONSTRAINT inventory_adjustments_kind_known
                    CHECK (kind IN ('quantity','revaluation','opening')),
                ADD CONSTRAINT inventory_adjustments_status_known
                    CHECK (status IN ('draft','approved','void')),
                -- An adjustment with no reason is an unexplained change to
                -- the value of the business.
                ADD CONSTRAINT inventory_adjustments_has_a_reason
                    CHECK (btrim(reason) <> ''),
                /*
                 * Approval is what posts an adjustment — but only where there
                 * is something to post.
                 *
                 * An adjustment CAN legitimately move quantity and no value:
                 * free samples received on a zero-priced bill line, or stock
                 * written off after it had already been written down to
                 * nothing. The ledger refuses a zero-value entry outright, so
                 * demanding an entry for every approval would make those
                 * adjustments impossible to approve at all.
                 *
                 * What must never happen is value moving with no entry behind
                 * it, or an entry with no approval. Both are forbidden here.
                 */
                ADD CONSTRAINT inventory_adjustments_posting_implies_approval
                    CHECK (journal_entry_id IS NULL OR approved_at IS NOT NULL),
                ADD CONSTRAINT inventory_adjustments_value_implies_posting
                    CHECK (approved_at IS NULL
                           OR journal_entry_id IS NOT NULL
                           OR total_value = 0);

            ALTER TABLE inventory_adjustment_lines
                ADD CONSTRAINT inventory_adjustment_lines_changes_something
                    CHECK (quantity_change <> 0 OR value_change <> 0),
                ADD CONSTRAINT inventory_adjustment_lines_counted_non_negative
                    CHECK (counted_quantity IS NULL OR counted_quantity >= 0);

            ALTER TABLE stock_transfers
                ADD CONSTRAINT stock_transfers_status_known
                    CHECK (status IN ('draft','completed')),
                /*
                 * Stock cannot move to where it already is. It looks harmless
                 * and would write a pair of movements that cancel, leaving a
                 * history nobody can read.
                 */
                ADD CONSTRAINT stock_transfers_warehouses_differ
                    CHECK (from_warehouse_id <> to_warehouse_id),
                ADD CONSTRAINT stock_transfers_completion_is_dated
                    CHECK ((status = 'completed') = (completed_at IS NOT NULL));

            ALTER TABLE stock_transfer_lines
                ADD CONSTRAINT stock_transfer_lines_quantity_positive
                    CHECK (quantity > 0);
        SQL);

        /*
         * One default warehouse per organisation.
         *
         * Two would each be "the" place stock goes when a document does not
         * say, and a form would pick whichever came back first.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX warehouses_one_default_per_organization
                ON warehouses (organization_id)
                WHERE is_default AND archived_at IS NULL
        SQL);

        // An adjustment posts once, and once more when voided.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX inventory_adjustments_journal_entry_unique
                ON inventory_adjustments (journal_entry_id)
                WHERE journal_entry_id IS NOT NULL
        SQL);

        /*
         * A document's stock effect happens ONCE.
         *
         * An invoice issued twice, a bill approved after a retry, a transfer
         * completed by two people at the same moment — each is a different
         * race, and a unique index on (source, line, kind) is the only thing
         * that can arbitrate between processes. Without it the ledger and the
         * stock part company in exactly the way I10 exists to catch, and the
         * difference is discovered a quarter later.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX stock_movements_one_per_source_line
                ON stock_movements (source_type, source_line_id, kind)
                WHERE source_line_id IS NOT NULL
        SQL);

        /*
         * The sub-ledger is append-only, exactly as the journal is.
         *
         * A weighted average is a chain: every movement's running balance is
         * computed from the one before it. Editing a movement in the middle
         * would leave every later figure derived from a state that no longer
         * exists, and nothing in the data would say so. Corrections are new
         * movements — an adjustment — which is the same answer the ledger
         * gives.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_are_append_only()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'The stock ledger is append-only: movement % cannot be % . Record an adjustment instead.',
                    OLD.id,
                    CASE TG_OP WHEN 'DELETE' THEN 'deleted' ELSE 'edited' END
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER stock_movements_append_only
                BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_are_append_only();
        SQL);

        foreach ([
            'warehouses',
            'stock_movements',
            'stock_levels',
            'inventory_adjustments',
            'inventory_adjustment_lines',
            'stock_transfers',
            'stock_transfer_lines',
        ] as $tenantTable) {
            DB::statement("ALTER TABLE {$tenantTable} ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$tenantTable}_tenant_isolation ON {$tenantTable}
                    USING (app_is_unscoped() OR organization_id = app_current_organization_id())
                    WITH CHECK (app_is_unscoped() OR organization_id = app_current_organization_id());
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stock_movements_append_only ON stock_movements;
            DROP FUNCTION IF EXISTS stock_movements_are_append_only();
        SQL);

        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('inventory_adjustment_lines');
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('warehouses');
    }
};
