<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses, and the mileage rates some of them are computed from.
 *
 * An expense is not a bill, and this is a separate table rather than a fourth
 * purchase document type for three reasons that all show up in the columns
 * below:
 *
 *   - It is usually PAID at the moment it is recorded, so there is no payable
 *     to age and no allocation to make. §4.8's entry credits the bank
 *     directly.
 *   - Where it is not, the money is owed to an EMPLOYEE rather than a vendor,
 *     which is a different liability with a different report.
 *   - It goes through approval before it posts, and the person who submits it
 *     is usually the person who spent the money — so submitter and approver
 *     are separate facts, both recorded.
 *
 * Mileage is a line whose quantity is a distance and whose price is a rate.
 * The rate is stored ON the line, and the rate table is only where a default
 * comes from: a rate that changes in April must not retroactively restate
 * March's claims, which is the same rule the tax components follow.
 *
 * @see ACCOUNTING_RULES.md §4.6, §4.8, §5, §6
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Mileage rates, dated.
         *
         * Per organisation and per unit, because a business with drivers in
         * two countries claims at two rates — and the unit is part of the
         * rate, not a display preference: 50 per kilometre and 50 per mile
         * are different amounts of money.
         */
        Schema::create('mileage_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->string('name', 80);
            // 'km' | 'mi'
            $table->string('unit', 4);
            // Money per unit of distance. Four places, like every other rate
            // that becomes an amount.
            $table->decimal('rate', 19, 4);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->index(['organization_id', 'effective_from']);
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->string('number', 30);

            /*
             * Who was paid.
             *
             * `contact_id` when the merchant is somebody we keep records for;
             * `merchant` for the far more common case where it is a taxi or a
             * hardware shop nobody will ever look up. Both, because forcing a
             * contact record for every coffee is how an expense screen stops
             * being used.
             */
            $table->uuid('contact_id')->nullable();
            $table->string('merchant', 160)->nullable();

            $table->date('expense_date');

            /*
             * 'company' — paid from a company bank or cash account.
             * 'reimbursable' — somebody paid out of their own pocket.
             *
             * The distinction decides the credit side of §4.8's entry, so it
             * is a column rather than an inference from whether an account
             * was chosen.
             */
            $table->string('payment_mode', 16)->default('company');

            // Where the money came from, for a company-paid expense. Null for
            // a reimbursable one, which credits the employee instead.
            $table->uuid('paid_through_account_id')->nullable();

            // Who is owed, for a reimbursable expense.
            $table->uuid('reimburse_user_id')->nullable();

            $table->string('reference', 80)->nullable();
            $table->string('status', 20)->default('draft');

            $table->char('currency', 3);
            $table->decimal('exchange_rate', 19, 10)->default(1);

            // Very often true on this side: a receipt total includes the tax.
            $table->boolean('prices_include_tax')->default(false);

            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            // §4.6's rule applies here too: what cannot be reclaimed is part
            // of the cost, so the two figures are kept apart.
            $table->decimal('tax_claimable_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            $table->decimal('subtotal_base', 19, 4)->default(0);
            $table->decimal('tax_total_base', 19, 4)->default(0);
            $table->decimal('tax_claimable_total_base', 19, 4)->default(0);
            $table->decimal('total_base', 19, 4)->default(0);

            /*
             * Billable to a customer.
             *
             * `billable_contact_id` is who it will be rebilled to, and
             * `billed_document_id` is the invoice that did it — null until
             * then, which is what makes "what have we not billed on yet" a
             * query rather than a spreadsheet.
             */
            $table->boolean('is_billable')->default(false);
            $table->uuid('billable_contact_id')->nullable();
            $table->uuid('billed_document_id')->nullable();

            $table->text('notes')->nullable();

            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('void_journal_entry_id')->nullable();

            /*
             * Submitted and approved are separate facts, because they are
             * usually separate people — the whole reason an expense has an
             * approval step at all.
             */
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('billable_contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('billed_document_id')->references('id')->on('sales_documents')->nullOnDelete();
            $table->foreign('paid_through_account_id')->references('id')->on('accounts');
            $table->foreign('reimburse_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('void_journal_entry_id')->references('id')->on('journal_entries');

            $table->unique(['organization_id', 'number']);

            $table->index(['organization_id', 'status', 'expense_date']);
            // "What have we not billed on yet", which is the query the
            // billable flag exists for.
            $table->index(['organization_id', 'is_billable', 'billed_document_id']);
            $table->index(['organization_id', 'reimburse_user_id', 'status']);
        });

        Schema::create('expense_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('expense_id');

            $table->unsignedSmallInteger('line_no');

            // 'amount' — a sum spent. 'mileage' — a distance at a rate.
            $table->string('kind', 12)->default('amount');

            $table->string('description', 500);

            /*
             * For a mileage line, `quantity` is the distance and
             * `unit_price` is the rate per unit — the same two columns doing
             * the same arithmetic, so the tax engine needs no special case.
             * `unit` says which it is, and is copied so a later change to the
             * organisation's default cannot restate an old claim.
             */
            $table->decimal('quantity', 19, 6)->default(1);
            $table->decimal('unit_price', 19, 4);
            $table->string('unit', 8)->nullable();
            $table->uuid('mileage_rate_id')->nullable();

            $table->uuid('tax_id')->nullable();
            // Where the cost lands: an expense account, or an asset account
            // where what was bought is still worth something.
            $table->uuid('debit_account_id');
            $table->boolean('tax_is_claimable')->default(true);

            $table->decimal('gross', 19, 4)->default(0);
            $table->decimal('net', 19, 4)->default(0);
            $table->decimal('taxable', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            $table->uuid('project_id')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('expense_id')->references('id')->on('expenses')->cascadeOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes');
            $table->foreign('debit_account_id')->references('id')->on('accounts');
            $table->foreign('mileage_rate_id')->references('id')->on('mileage_rates')->nullOnDelete();

            $table->unique(['expense_id', 'line_no']);
        });

        Schema::create('expense_line_taxes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('expense_id');
            $table->uuid('expense_line_id');

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
            $table->foreign('expense_id')->references('id')->on('expenses')->cascadeOnDelete();
            $table->foreign('expense_line_id')->references('id')->on('expense_lines')->cascadeOnDelete();
            $table->foreign('tax_component_id')->references('id')->on('tax_components');
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->unique(['expense_line_id', 'tax_component_id']);

            // The input half of the tax return, again.
            $table->index(['organization_id', 'tax_component_id', 'expense_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE mileage_rates
                ADD CONSTRAINT mileage_rates_unit_known
                    CHECK (unit IN ('km','mi')),
                ADD CONSTRAINT mileage_rates_rate_positive
                    CHECK (rate > 0),
                ADD CONSTRAINT mileage_rates_dates_ordered
                    CHECK (effective_to IS NULL OR effective_to >= effective_from);

            ALTER TABLE expenses
                ADD CONSTRAINT expenses_status_known
                    CHECK (status IN ('draft','submitted','approved','rejected','void')),
                ADD CONSTRAINT expenses_payment_mode_known
                    CHECK (payment_mode IN ('company','reimbursable')),
                ADD CONSTRAINT expenses_currency_format
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT expenses_rate_positive
                    CHECK (exchange_rate > 0),
                ADD CONSTRAINT expenses_totals_non_negative
                    CHECK (subtotal >= 0 AND tax_total >= 0 AND total >= 0
                       AND tax_claimable_total >= 0),
                ADD CONSTRAINT expenses_claimable_within_tax
                    CHECK (tax_claimable_total <= tax_total),
                /*
                 * A company-paid expense names the account it came out of; a
                 * reimbursable one must not, because it did not come out of
                 * one. Without this the two modes could both be half filled
                 * in, and the posting rule would have to guess.
                 */
                ADD CONSTRAINT expenses_payment_source_matches_mode
                    CHECK ((payment_mode = 'company') = (paid_through_account_id IS NOT NULL)),
                -- Approval is what posts an expense, so the two facts travel
                -- together.
                ADD CONSTRAINT expenses_approval_matches_posting
                    CHECK ((approved_at IS NULL) = (journal_entry_id IS NULL)),
                -- A rejection has to say why. "Rejected" with no reason is a
                -- dead end for whoever submitted it.
                ADD CONSTRAINT expenses_rejection_has_reason
                    CHECK (status <> 'rejected' OR btrim(coalesce(rejection_reason, '')) <> ''),
                -- Only a billable expense can be marked as billed on.
                ADD CONSTRAINT expenses_billed_only_when_billable
                    CHECK (billed_document_id IS NULL OR is_billable);

            ALTER TABLE expense_lines
                ADD CONSTRAINT expense_lines_kind_known
                    CHECK (kind IN ('amount','mileage')),
                ADD CONSTRAINT expense_lines_quantity_positive
                    CHECK (quantity > 0),
                ADD CONSTRAINT expense_lines_price_non_negative
                    CHECK (unit_price >= 0),
                ADD CONSTRAINT expense_lines_description_not_blank
                    CHECK (btrim(description) <> ''),
                -- A mileage line without a unit is a distance in nothing.
                ADD CONSTRAINT expense_lines_mileage_has_unit
                    CHECK (kind <> 'mileage' OR unit IN ('km','mi')),
                ADD CONSTRAINT expense_lines_amounts_non_negative
                    CHECK (gross >= 0 AND net >= 0 AND taxable >= 0
                       AND tax_total >= 0 AND total >= 0);

            ALTER TABLE expense_line_taxes
                ADD CONSTRAINT expense_line_taxes_rate_range
                    CHECK (rate >= 0 AND rate <= 1),
                ADD CONSTRAINT expense_line_taxes_amounts_non_negative
                    CHECK (taxable_amount >= 0 AND tax_amount >= 0 AND tax_amount_base >= 0);
        SQL);

        // An expense must not post twice, and its entry is the proof.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX expenses_journal_entry_unique
                ON expenses (journal_entry_id)
                WHERE journal_entry_id IS NOT NULL
        SQL);

        /*
         * One default mileage rate per unit at a time.
         *
         * Two accounts of "the" rate per kilometre is unresolvable, and the
         * form would silently pick whichever came back first.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX mileage_rates_one_default_per_unit
                ON mileage_rates (organization_id, unit)
                WHERE is_default AND effective_to IS NULL
        SQL);

        foreach (['mileage_rates', 'expenses', 'expense_lines', 'expense_line_taxes'] as $table) {
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
        Schema::dropIfExists('expense_line_taxes');
        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('mileage_rates');
    }
};
