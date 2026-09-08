<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase documents: purchase orders, bills and vendor credits.
 *
 * Deliberately a separate table from `sales_documents` rather than one
 * `documents` table with a direction.
 *
 * The two sides look alike on screen and are genuinely different underneath.
 * A sales line names the revenue account it credits; a purchase line names
 * the expense OR ASSET account it debits, and may capitalise its tax instead
 * of claiming it. Merging them would mean one nullable column per difference
 * and a check constraint for every combination — and the first person to add
 * a purchase-only column would weaken a sales-only guarantee. Separate tables
 * keep each side's constraints total.
 *
 * What IS shared is shared properly: the tax engine, the numbering sequence
 * table, the contacts, the items, and the ledger. Nothing about purchases
 * posts through a second path.
 *
 * §6's rule holds on this side too — a purchase order is a commitment and
 * never posts, whatever happens to it — and a constraint enforces it
 * independently of the enum.
 *
 * @see ACCOUNTING_RULES.md §4.6, §4.7, §5, §6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // 'purchase_order' | 'bill' | 'vendor_credit'
            $table->string('type', 20);

            // Our number, gap-free per type per organisation.
            $table->string('number', 30);

            $table->uuid('contact_id');

            $table->date('issue_date');
            $table->date('due_date')->nullable();
            // A purchase order can lapse; a bill cannot.
            $table->date('expires_on')->nullable();

            /*
             * The VENDOR's own number for this document — their invoice
             * number, not ours.
             *
             * Kept because it is what the vendor will quote when chasing
             * payment, what a remittance advice has to name, and the only
             * reliable way to notice the same bill entered twice. It is not
             * unique in the database: vendors do restart their numbering, and
             * a constraint that cannot be overridden would block a legitimate
             * entry with no way through. The duplicate is refused in the form
             * request instead, where the person entering it can be told which
             * bill it collides with.
             */
            $table->string('vendor_reference', 80)->nullable();

            // Free-text, ours: a PO number from a customer, a contract, a job.
            $table->string('reference', 80)->nullable();

            // The PO → bill chain, walkable in both directions.
            $table->uuid('converted_from_id')->nullable();

            // A vendor credit names the bill it credits.
            $table->uuid('credits_document_id')->nullable();

            $table->string('status', 20)->default('draft');

            $table->char('currency', 3);
            $table->decimal('exchange_rate', 19, 10)->default(1);

            /*
             * Whether the vendor's prices include tax. Far more common on this
             * side than on sales — a retail receipt is nearly always
             * tax-inclusive — so it matters that the figure entered keeps its
             * meaning after the organisation's default changes.
             */
            $table->boolean('prices_include_tax')->default(false);

            $table->string('discount_type', 12)->nullable();
            $table->decimal('discount_value', 19, 4)->nullable();

            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            /*
             * How much of `tax_total` is actually recoverable.
             *
             * The rest is capitalised into the expense by §4.6, so the two
             * figures are needed separately: `tax_total` is what the vendor
             * charged, `tax_claimable_total` is what goes to GST Input
             * Receivable. Storing only the first would make the input-tax
             * return a recomputation from rates that may since have changed.
             */
            $table->decimal('tax_claimable_total', 19, 4)->default(0);

            $table->decimal('subtotal_base', 19, 4)->default(0);
            $table->decimal('discount_total_base', 19, 4)->default(0);
            $table->decimal('tax_total_base', 19, 4)->default(0);
            $table->decimal('tax_claimable_total_base', 19, 4)->default(0);
            $table->decimal('total_base', 19, 4)->default(0);

            // Maintained by the payment actions, never summed on read: the
            // ageing report reads every open bill.
            $table->decimal('amount_paid', 19, 4)->default(0);
            $table->decimal('amount_credited', 19, 4)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            // Copied, not referenced: where we were billed from is a fact
            // about this document.
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('shipping_address')->nullable();

            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('void_journal_entry_id')->nullable();

            /*
             * A bill posts on APPROVAL, not on entry — §6 — so who approved
             * it is a separate fact from who typed it in. On the purchases
             * side that distinction is the control: the person who enters a
             * bill and the person who approves it are usually not the same,
             * and the roles are built so that they need not be.
             */
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('void_journal_entry_id')->references('id')->on('journal_entries');

            $table->unique(['organization_id', 'type', 'number']);

            $table->index(['organization_id', 'type', 'status', 'issue_date']);
            $table->index(['organization_id', 'contact_id', 'type', 'status']);
            $table->index(['organization_id', 'type', 'due_date']);
            // The duplicate-bill lookup.
            $table->index(['organization_id', 'contact_id', 'vendor_reference']);
        });

        // Self-references after the primary key exists; see the sales
        // migration for what PostgreSQL does otherwise.
        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->foreign('converted_from_id')->references('id')->on('purchase_documents')->nullOnDelete();
            $table->foreign('credits_document_id')->references('id')->on('purchase_documents');
        });

        Schema::create('purchase_document_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('purchase_document_id');

            $table->unsignedSmallInteger('line_no');

            // A reference for reporting; everything below is a copy taken
            // when the line was added.
            $table->uuid('item_id')->nullable();

            $table->string('description', 500);
            $table->string('unit', 20)->nullable();

            $table->decimal('quantity', 19, 6);
            $table->decimal('unit_price', 19, 4);

            $table->string('discount_type', 12)->nullable();
            $table->decimal('discount_value', 19, 4)->nullable();

            $table->uuid('tax_id')->nullable();

            /*
             * Where the cost lands. An expense account for most things, an
             * asset account for inventory or equipment — which is why this is
             * not called `expense_account_id`.
             */
            $table->uuid('debit_account_id');

            /*
             * Whether this line's input tax is recoverable.
             *
             * Per LINE, not per tax and not per document. Claimability is a
             * fact about what was bought and what it was bought for — the
             * same 18% GST is recoverable on raw material and blocked on
             * entertainment — so a document can legitimately have both on it.
             *
             * When false, §4.6 requires the tax to be capitalised into the
             * cost rather than posted to GST Input Receivable: it is a real
             * cost, not a receivable, and treating it as one overstates both
             * assets and profit.
             */
            $table->boolean('tax_is_claimable')->default(true);

            $table->decimal('gross', 19, 4)->default(0);
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->decimal('net', 19, 4)->default(0);
            $table->decimal('document_discount_amount', 19, 4)->default(0);
            $table->decimal('taxable', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            $table->uuid('project_id')->nullable();
            $table->uuid('warehouse_id')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('purchase_document_id')->references('id')->on('purchase_documents')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes');
            $table->foreign('debit_account_id')->references('id')->on('accounts');

            $table->unique(['purchase_document_id', 'line_no']);
            $table->index(['organization_id', 'item_id']);
        });

        /*
         * The input-tax breakdown, one row per component per line.
         *
         * A real table for the same reason as on the sales side: this query
         * IS the input half of the tax return. `is_claimable` is copied down
         * from the line so the return can be filed straight from these rows
         * without joining back to work out which of them count.
         */
        Schema::create('purchase_document_line_taxes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('purchase_document_id');
            $table->uuid('purchase_document_line_id');

            $table->uuid('tax_component_id');

            $table->string('component_name', 80);
            $table->decimal('rate', 9, 6);
            $table->boolean('is_compound')->default(false);
            $table->boolean('is_claimable')->default(true);

            $table->decimal('taxable_amount', 19, 4);
            $table->decimal('tax_amount', 19, 4);
            $table->decimal('tax_amount_base', 19, 4);

            $table->uuid('account_id')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('purchase_document_id')->references('id')->on('purchase_documents')->cascadeOnDelete();
            $table->foreign('purchase_document_line_id')->references('id')->on('purchase_document_lines')->cascadeOnDelete();
            $table->foreign('tax_component_id')->references('id')->on('tax_components');
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->unique(['purchase_document_line_id', 'tax_component_id']);

            $table->index(['organization_id', 'tax_component_id', 'purchase_document_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE purchase_documents
                ADD CONSTRAINT purchase_documents_type_known
                    CHECK (type IN ('purchase_order','bill','vendor_credit')),
                ADD CONSTRAINT purchase_documents_status_known
                    CHECK (status IN ('draft','sent','open','partially_paid','paid',
                                      'overdue','closed','void')),
                ADD CONSTRAINT purchase_documents_currency_format
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT purchase_documents_rate_positive
                    CHECK (exchange_rate > 0),
                ADD CONSTRAINT purchase_documents_totals_non_negative
                    CHECK (subtotal >= 0 AND tax_total >= 0 AND total >= 0
                       AND discount_total >= 0 AND tax_claimable_total >= 0
                       AND amount_paid >= 0 AND amount_credited >= 0),
                -- What is recoverable cannot exceed what was charged.
                ADD CONSTRAINT purchase_documents_claimable_within_tax
                    CHECK (tax_claimable_total <= tax_total),
                ADD CONSTRAINT purchase_documents_discount_type_known
                    CHECK (discount_type IS NULL OR discount_type IN ('percentage','amount')),
                ADD CONSTRAINT purchase_documents_discount_complete
                    CHECK ((discount_type IS NULL) = (discount_value IS NULL)),
                ADD CONSTRAINT purchase_documents_percentage_range
                    CHECK (discount_type <> 'percentage' OR discount_value BETWEEN 0 AND 100),
                ADD CONSTRAINT purchase_documents_due_after_issue
                    CHECK (due_date IS NULL OR due_date >= issue_date),
                ADD CONSTRAINT purchase_documents_credits_only_vendor_credits
                    CHECK (credits_document_id IS NULL OR type = 'vendor_credit'),
                -- §6: a purchase order is a commitment and never posts.
                ADD CONSTRAINT purchase_documents_commitments_never_post
                    CHECK (journal_entry_id IS NULL OR type IN ('bill','vendor_credit')),
                -- Approval is what posts a bill, so the two facts travel
                -- together. A posted bill with no approver, or an approver
                -- with no entry, means one of the two writes was lost.
                ADD CONSTRAINT purchase_documents_approval_matches_posting
                    CHECK (type <> 'bill'
                        OR (approved_at IS NULL) = (journal_entry_id IS NULL));

            ALTER TABLE purchase_document_lines
                ADD CONSTRAINT purchase_document_lines_quantity_positive
                    CHECK (quantity > 0),
                ADD CONSTRAINT purchase_document_lines_price_non_negative
                    CHECK (unit_price >= 0),
                ADD CONSTRAINT purchase_document_lines_description_not_blank
                    CHECK (btrim(description) <> ''),
                ADD CONSTRAINT purchase_document_lines_discount_type_known
                    CHECK (discount_type IS NULL OR discount_type IN ('percentage','amount')),
                ADD CONSTRAINT purchase_document_lines_discount_complete
                    CHECK ((discount_type IS NULL) = (discount_value IS NULL)),
                ADD CONSTRAINT purchase_document_lines_percentage_range
                    CHECK (discount_type <> 'percentage' OR discount_value BETWEEN 0 AND 100),
                ADD CONSTRAINT purchase_document_lines_discount_within_gross
                    CHECK (discount_amount <= gross),
                ADD CONSTRAINT purchase_document_lines_amounts_non_negative
                    CHECK (gross >= 0 AND net >= 0 AND taxable >= 0
                       AND tax_total >= 0 AND total >= 0);

            ALTER TABLE purchase_document_line_taxes
                ADD CONSTRAINT purchase_document_line_taxes_rate_range
                    CHECK (rate >= 0 AND rate <= 1),
                ADD CONSTRAINT purchase_document_line_taxes_amounts_non_negative
                    CHECK (taxable_amount >= 0 AND tax_amount >= 0 AND tax_amount_base >= 0);
        SQL);

        // A document must not post twice, and its entry is the proof.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX purchase_documents_journal_entry_unique
                ON purchase_documents (journal_entry_id)
                WHERE journal_entry_id IS NOT NULL
        SQL);

        foreach ([
            'purchase_documents',
            'purchase_document_lines',
            'purchase_document_line_taxes',
        ] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                    USING (app_is_unscoped() OR organization_id = app_current_organization_id())
                    WITH CHECK (app_is_unscoped() OR organization_id = app_current_organization_id());
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_document_line_taxes');
        Schema::dropIfExists('purchase_document_lines');
        Schema::dropIfExists('purchase_documents');
    }
};
