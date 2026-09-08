<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales documents: estimates, sales orders, invoices and credit notes.
 *
 * ONE table with a `type`, not four. An estimate becomes a sales order
 * becomes an invoice, and the conversion is then a copy of rows rather than a
 * translation between four near-identical schemas. Numbering, totals, tax
 * breakdown, addresses and the line editor are all shared — which is the
 * whole reason the workflow feels continuous in a product like this.
 *
 * What differs by type is only what §6 says: whether the document posts, and
 * when. An estimate and a sales order never post; an invoice posts on issue;
 * a credit note posts on issue. `journal_entry_id` is therefore null for the
 * two commitment documents, always.
 *
 * Totals are STORED, not derived on read. They were computed once by the tax
 * engine at the precision the rules demand, and re-deriving them later — from
 * rates that may since have changed — is how a document silently stops
 * agreeing with the journal entry it produced.
 *
 * @see ACCOUNTING_RULES.md §4.1, §4.5, §5, §6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // 'estimate' | 'sales_order' | 'invoice' | 'credit_note'
            $table->string('type', 20);

            // Gap-free per type per organisation.
            $table->string('number', 30);

            $table->uuid('contact_id');

            $table->date('issue_date');
            // Invoices and credit notes only; a quote has an expiry instead.
            $table->date('due_date')->nullable();
            $table->date('expires_on')->nullable();

            $table->string('reference', 80)->nullable();

            /*
             * Where this document came from, so the estimate → order →
             * invoice chain is walkable in both directions without a join
             * table.
             */
            $table->uuid('converted_from_id')->nullable();

            // A credit note names the invoice it credits.
            $table->uuid('credits_document_id')->nullable();

            $table->string('status', 20)->default('draft');

            /*
             * Currency, and the rate used to reach base. Fixed at posting and
             * never recomputed — the same rule the ledger follows.
             */
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 19, 10)->default(1);

            /*
             * Whether the prices on this document include tax. Stored per
             * document because it changes what the entered numbers MEAN, and
             * a document has to remain readable after the default changes.
             */
            $table->boolean('prices_include_tax')->default(false);

            /*
             * A document-level discount, apportioned across lines pro rata by
             * net. Both forms are kept: 'percentage' with the rate, or
             * 'amount' with the figure, because re-deriving one from the other
             * loses what the user actually agreed.
             */
            $table->string('discount_type', 12)->nullable();
            $table->decimal('discount_value', 19, 4)->nullable();

            /*
             * Totals, in document currency and base currency.
             *
             * `subtotal` is gross of discount; `discount_total` is every
             * discount, line and document; `tax_total` is the sum of the
             * component amounts. Revenue is reported gross with discounts in
             * contra-revenue, so keeping them separate here is what makes
             * that possible at all.
             */
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            $table->decimal('subtotal_base', 19, 4)->default(0);
            $table->decimal('discount_total_base', 19, 4)->default(0);
            $table->decimal('tax_total_base', 19, 4)->default(0);
            $table->decimal('total_base', 19, 4)->default(0);

            /*
             * How much of the total has been settled. Maintained by the
             * payment actions rather than summed on read, because an aging
             * report reads every open invoice and an N+1 there is the
             * difference between a report and a timeout.
             */
            $table->decimal('amount_paid', 19, 4)->default(0);
            $table->decimal('amount_credited', 19, 4)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            /*
             * Addresses are COPIED onto the document, not referenced. A
             * customer who moves must not silently change where last year's
             * invoices were sent.
             */
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('shipping_address')->nullable();

            /*
             * The entry this document produced. Null until it posts, and null
             * for ever on an estimate or a sales order.
             */
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('void_journal_entry_id')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->uuid('issued_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('void_journal_entry_id')->references('id')->on('journal_entries');

            $table->unique(['organization_id', 'type', 'number']);

            // The list screens read by type and date; aging reads by contact.
            $table->index(['organization_id', 'type', 'status', 'issue_date']);
            $table->index(['organization_id', 'contact_id', 'type', 'status']);
            $table->index(['organization_id', 'type', 'due_date']);
        });

        /*
         * The self-references are added after the table exists. Declared
         * inside Schema::create they run before the primary key, and
         * PostgreSQL rejects them with "no unique constraint matching given
         * keys" — the same reason accounts.parent_id is done this way.
         */
        Schema::table('sales_documents', function (Blueprint $table): void {
            $table->foreign('converted_from_id')->references('id')->on('sales_documents')->nullOnDelete();
            $table->foreign('credits_document_id')->references('id')->on('sales_documents');
        });

        Schema::create('sales_document_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('sales_document_id');

            $table->unsignedSmallInteger('line_no');

            /*
             * The item is a reference for reporting; everything below is a
             * COPY taken when the line was added. That is what keeps a
             * two-year-old invoice readable after the item was renamed,
             * repriced or archived.
             */
            $table->uuid('item_id')->nullable();

            $table->string('description', 500);
            $table->string('unit', 20)->nullable();

            $table->decimal('quantity', 19, 6);
            $table->decimal('unit_price', 19, 4);

            // Line discount, in the form the user gave it.
            $table->string('discount_type', 12)->nullable();
            $table->decimal('discount_value', 19, 4)->nullable();

            $table->uuid('tax_id')->nullable();
            $table->uuid('revenue_account_id');

            /*
             * Computed by the tax engine and stored. `gross` is quantity ×
             * price; `net` is after the line discount; `taxable` is after the
             * document discount has been apportioned; `tax_total` is the sum
             * of this line's components.
             */
            $table->decimal('gross', 19, 4)->default(0);
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->decimal('net', 19, 4)->default(0);
            $table->decimal('document_discount_amount', 19, 4)->default(0);
            $table->decimal('taxable', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            // Dimensions, so the ledger can be sliced without a second model.
            $table->uuid('project_id')->nullable();
            $table->uuid('warehouse_id')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('sales_document_id')->references('id')->on('sales_documents')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes');
            $table->foreign('revenue_account_id')->references('id')->on('accounts');

            $table->unique(['sales_document_id', 'line_no']);
            $table->index(['organization_id', 'item_id']);
        });

        /*
         * The tax breakdown, one row per component per line.
         *
         * A jsonb column would have been shorter. It would also make "GST
         * collected this quarter, by component" a full scan and a JSON
         * traversal — and that query IS the sales tax return, so it gets a
         * real table with a real index.
         */
        Schema::create('sales_document_line_taxes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('sales_document_id');
            $table->uuid('sales_document_line_id');

            $table->uuid('tax_component_id');

            // Copied, so the return is readable after a rename or a rate change.
            $table->string('component_name', 80);
            $table->decimal('rate', 9, 6);
            $table->boolean('is_compound')->default(false);

            $table->decimal('taxable_amount', 19, 4);
            $table->decimal('tax_amount', 19, 4);
            $table->decimal('tax_amount_base', 19, 4);

            $table->uuid('account_id')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('sales_document_id')->references('id')->on('sales_documents')->cascadeOnDelete();
            $table->foreign('sales_document_line_id')->references('id')->on('sales_document_lines')->cascadeOnDelete();
            $table->foreign('tax_component_id')->references('id')->on('tax_components');
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->unique(['sales_document_line_id', 'tax_component_id']);

            // The shape the tax return reads in.
            $table->index(['organization_id', 'tax_component_id', 'sales_document_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE sales_documents
                ADD CONSTRAINT sales_documents_type_known
                    CHECK (type IN ('estimate','sales_order','invoice','credit_note')),
                ADD CONSTRAINT sales_documents_status_known
                    CHECK (status IN ('draft','sent','accepted','declined','expired',
                                      'open','partially_paid','paid','overdue',
                                      'closed','void')),
                ADD CONSTRAINT sales_documents_currency_format
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT sales_documents_rate_positive
                    CHECK (exchange_rate > 0),
                ADD CONSTRAINT sales_documents_totals_non_negative
                    CHECK (subtotal >= 0 AND tax_total >= 0 AND total >= 0
                       AND discount_total >= 0 AND amount_paid >= 0
                       AND amount_credited >= 0),
                ADD CONSTRAINT sales_documents_discount_type_known
                    CHECK (discount_type IS NULL OR discount_type IN ('percentage','amount')),
                -- A discount type with no value, or a value with no type, is
                -- half a decision.
                ADD CONSTRAINT sales_documents_discount_complete
                    CHECK ((discount_type IS NULL) = (discount_value IS NULL)),
                ADD CONSTRAINT sales_documents_percentage_range
                    CHECK (discount_type <> 'percentage' OR discount_value BETWEEN 0 AND 100),
                -- Due dates run forwards.
                ADD CONSTRAINT sales_documents_due_after_issue
                    CHECK (due_date IS NULL OR due_date >= issue_date),
                -- Only a credit note credits something, and it must say what.
                ADD CONSTRAINT sales_documents_credits_only_credit_notes
                    CHECK (credits_document_id IS NULL OR type = 'credit_note'),
                -- §6: an estimate and a sales order have no accounting effect,
                -- ever. This is the database refusing to let one acquire an
                -- entry however the code is later refactored.
                ADD CONSTRAINT sales_documents_commitments_never_post
                    CHECK (journal_entry_id IS NULL OR type IN ('invoice','credit_note'));

            ALTER TABLE sales_document_lines
                ADD CONSTRAINT sales_document_lines_quantity_positive
                    CHECK (quantity > 0),
                ADD CONSTRAINT sales_document_lines_price_non_negative
                    CHECK (unit_price >= 0),
                ADD CONSTRAINT sales_document_lines_description_not_blank
                    CHECK (btrim(description) <> ''),
                ADD CONSTRAINT sales_document_lines_discount_type_known
                    CHECK (discount_type IS NULL OR discount_type IN ('percentage','amount')),
                ADD CONSTRAINT sales_document_lines_discount_complete
                    CHECK ((discount_type IS NULL) = (discount_value IS NULL)),
                ADD CONSTRAINT sales_document_lines_percentage_range
                    CHECK (discount_type <> 'percentage' OR discount_value BETWEEN 0 AND 100),
                -- A discount cannot exceed what it discounts.
                ADD CONSTRAINT sales_document_lines_discount_within_gross
                    CHECK (discount_amount <= gross),
                ADD CONSTRAINT sales_document_lines_amounts_non_negative
                    CHECK (gross >= 0 AND net >= 0 AND taxable >= 0
                       AND tax_total >= 0 AND total >= 0);

            ALTER TABLE sales_document_line_taxes
                ADD CONSTRAINT sales_document_line_taxes_rate_range
                    CHECK (rate >= 0 AND rate <= 1),
                ADD CONSTRAINT sales_document_line_taxes_amounts_non_negative
                    CHECK (taxable_amount >= 0 AND tax_amount >= 0 AND tax_amount_base >= 0);
        SQL);

        /*
         * One credit note per invoice per number is not the rule — an invoice
         * can be credited more than once, partially. But a document must not
         * post twice, and the journal entry it produced is the proof, so that
         * link is unique.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX sales_documents_journal_entry_unique
                ON sales_documents (journal_entry_id)
                WHERE journal_entry_id IS NOT NULL
        SQL);

        foreach (['sales_documents', 'sales_document_lines', 'sales_document_line_taxes'] as $table) {
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
        Schema::dropIfExists('sales_document_line_taxes');
        Schema::dropIfExists('sales_document_lines');
        Schema::dropIfExists('sales_documents');
    }
};
