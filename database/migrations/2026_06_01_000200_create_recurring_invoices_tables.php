<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice templates that generate on a schedule.
 *
 * A template is NOT an invoice. It has no number, it never posts, and it has
 * no balance — it is a standing instruction that produces invoices, each of
 * which is an ordinary document with its own number, its own date and its own
 * journal entry. §6 says as much: a recurring invoice posts "on each
 * generated invoice, at its own date".
 *
 * That distinction is why this is its own table rather than a flag on
 * `sales_documents`. A flagged invoice would need a status that means "this
 * one is a pattern, not a debt", every list and total would have to exclude
 * it, and the first report that forgot would overstate receivables by the
 * value of every template in the system.
 *
 * THE RUNS TABLE IS THE IDEMPOTENCY GUARD. A scheduler that fires twice, a
 * worker retried after a timeout, or somebody pressing "generate now" while
 * the nightly run is in flight must not bill a customer twice. One row per
 * template per scheduled date, unique, written in the same transaction as the
 * invoice — so the second attempt collides instead of duplicating.
 *
 * @see ACCOUNTING_RULES.md §6, §9
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // What a human calls it: "Acme — monthly retainer".
            $table->string('name', 120);

            $table->uuid('contact_id');

            /*
             * The schedule.
             *
             * A frequency and an interval rather than a cron expression:
             * "every 2 months" is what people mean, and a cron string would
             * let them express "every Tuesday in March", which no invoicing
             * arrangement has ever needed and which nothing downstream could
             * present back to them.
             */
            $table->string('frequency', 12);
            $table->unsignedSmallInteger('interval')->default(1);

            $table->date('starts_on');
            /*
             * Either an end date or a count, and neither is required — a
             * retainer with no agreed end is the normal case. Both are kept
             * rather than one derived from the other, because "twelve
             * invoices" and "until next March" are different agreements even
             * when they currently coincide.
             */
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('max_occurrences')->nullable();

            // Where the schedule has got to. `next_run_on` is the whole
            // working state: null means it has finished.
            $table->date('next_run_on')->nullable();
            $table->date('last_run_on')->nullable();
            $table->unsignedSmallInteger('occurrences_generated')->default(0);

            $table->string('status', 12)->default('active');

            /*
             * Whether a generated invoice is issued, or left as a draft.
             *
             * Issuing without a human present is a real decision, and this is
             * where it is made: setting up the template IS the authorisation,
             * given once, in advance, by somebody who could see what would be
             * billed. A business that would rather check each one first turns
             * this off and gets drafts.
             */
            $table->boolean('auto_issue')->default(true);

            // Days after the generated invoice's date that it falls due.
            // Copied from the customer's terms at creation, then editable —
            // a retainer often has its own.
            $table->unsignedSmallInteger('payment_terms_days')->default(30);

            $table->char('currency', 3);
            $table->decimal('exchange_rate', 19, 10)->default(1);
            $table->boolean('prices_include_tax')->default(false);

            $table->string('discount_type', 12)->nullable();
            $table->decimal('discount_value', 19, 4)->nullable();

            $table->string('reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts');

            // The scheduler's query: what is due today, across everybody.
            $table->index(['organization_id', 'status', 'next_run_on']);
        });

        Schema::create('recurring_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('recurring_invoice_id');

            $table->unsignedSmallInteger('line_no');

            $table->uuid('item_id')->nullable();
            $table->string('description', 500);
            $table->string('unit', 20)->nullable();

            $table->decimal('quantity', 19, 6);
            $table->decimal('unit_price', 19, 4);

            $table->string('discount_type', 12)->nullable();
            $table->decimal('discount_value', 19, 4)->nullable();

            $table->uuid('tax_id')->nullable();
            $table->uuid('revenue_account_id');

            /*
             * No computed columns.
             *
             * A template has no totals, because the tax rates in force when
             * it generates are not necessarily the ones in force today. Every
             * figure is computed on the invoice it produces, at that
             * invoice's date — which is the same rule §5 applies everywhere
             * else.
             */
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('recurring_invoice_id')->references('id')->on('recurring_invoices')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes');
            $table->foreign('revenue_account_id')->references('id')->on('accounts');

            $table->unique(['recurring_invoice_id', 'line_no']);
        });

        /*
         * One row per occurrence generated. The idempotency guard.
         */
        Schema::create('recurring_invoice_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('recurring_invoice_id');

            // The date the schedule called for, not the date it ran. A run
            // that was late still belongs to the occurrence it was for.
            $table->date('scheduled_for');
            $table->timestamp('ran_at');

            $table->uuid('sales_document_id')->nullable();

            /*
             * Why it did not produce an invoice, where it did not.
             *
             * A failed occurrence is recorded rather than retried silently:
             * the customer may have been archived, or the period closed, and
             * a scheduler that kept trying every night would bury the reason
             * under a thousand identical failures.
             */
            $table->string('outcome', 16)->default('generated');
            $table->string('failure_reason', 255)->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('recurring_invoice_id')->references('id')->on('recurring_invoices')->cascadeOnDelete();
            $table->foreign('sales_document_id')->references('id')->on('sales_documents')->nullOnDelete();

            /*
             * The constraint that makes double-billing impossible.
             *
             * Not a check in code: a scheduler firing twice, a retried worker
             * and a human pressing "generate now" are three different races,
             * and only the database can arbitrate between processes.
             */
            $table->unique(['recurring_invoice_id', 'scheduled_for']);

            $table->index(['organization_id', 'ran_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE recurring_invoices
                ADD CONSTRAINT recurring_invoices_frequency_known
                    CHECK (frequency IN ('weekly','monthly','quarterly','yearly')),
                ADD CONSTRAINT recurring_invoices_status_known
                    CHECK (status IN ('active','paused','ended')),
                ADD CONSTRAINT recurring_invoices_interval_positive
                    CHECK (interval >= 1 AND interval <= 52),
                ADD CONSTRAINT recurring_invoices_currency_format
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT recurring_invoices_rate_positive
                    CHECK (exchange_rate > 0),
                ADD CONSTRAINT recurring_invoices_ends_after_start
                    CHECK (ends_on IS NULL OR ends_on >= starts_on),
                ADD CONSTRAINT recurring_invoices_occurrences_positive
                    CHECK (max_occurrences IS NULL OR max_occurrences >= 1),
                ADD CONSTRAINT recurring_invoices_discount_complete
                    CHECK ((discount_type IS NULL) = (discount_value IS NULL)),
                ADD CONSTRAINT recurring_invoices_discount_type_known
                    CHECK (discount_type IS NULL OR discount_type IN ('percentage','amount')),
                -- REPLACED by 2026_06_01_000300. This version forbade a
                -- paused template from keeping its place, which is the one
                -- thing pausing is for — see that migration for the
                -- invariant that was actually meant.
                ADD CONSTRAINT recurring_invoices_active_has_next_run
                    CHECK ((status = 'active') = (next_run_on IS NOT NULL));

            ALTER TABLE recurring_invoice_lines
                ADD CONSTRAINT recurring_invoice_lines_quantity_positive
                    CHECK (quantity > 0),
                ADD CONSTRAINT recurring_invoice_lines_price_non_negative
                    CHECK (unit_price >= 0),
                ADD CONSTRAINT recurring_invoice_lines_description_not_blank
                    CHECK (btrim(description) <> ''),
                ADD CONSTRAINT recurring_invoice_lines_discount_complete
                    CHECK ((discount_type IS NULL) = (discount_value IS NULL));

            ALTER TABLE recurring_invoice_runs
                ADD CONSTRAINT recurring_invoice_runs_outcome_known
                    CHECK (outcome IN ('generated','failed','skipped')),
                -- A successful run produced an invoice; a failed one says why.
                ADD CONSTRAINT recurring_invoice_runs_outcome_consistent
                    CHECK (
                        (outcome = 'generated' AND sales_document_id IS NOT NULL)
                        OR (outcome <> 'generated' AND btrim(coalesce(failure_reason, '')) <> '')
                    );
        SQL);

        foreach ([
            'recurring_invoices',
            'recurring_invoice_lines',
            'recurring_invoice_runs',
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
        Schema::dropIfExists('recurring_invoice_runs');
        Schema::dropIfExists('recurring_invoice_lines');
        Schema::dropIfExists('recurring_invoices');
    }
};
