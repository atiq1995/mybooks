<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taxes, as data.
 *
 * A tax is a named thing a user picks on a line — "GST 18%" — and it is made
 * of one or more COMPONENTS. That indirection is not over-engineering: a
 * single Pakistani sales line can carry provincial sales tax plus a further
 * tax for an unregistered buyer, each landing in its own liability account,
 * each reportable separately on the return.
 *
 * Components are versioned by effective date, because a rate change must
 * never retroactively alter a posted document. Posted documents keep their
 * computed amounts, and this table only decides what happens next.
 *
 * Withholding lives here too, but it is not a tax ON the document: it is a
 * deduction at payment time that changes how a balance is settled, never the
 * invoice total.
 *
 * @see ACCOUNTING_RULES.md §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->string('name', 80);
            // Short form for a document line: 'GST18', 'PST13', 'WHT-S'.
            $table->string('code', 20);

            /*
             * 'sales'       output tax charged to a customer
             * 'purchase'    input tax charged by a vendor
             * 'both'        the same rate applies in each direction
             * 'withholding' deducted at payment, never on the document
             */
            $table->string('applies_to', 12)->default('both');

            /*
             * Whether prices using this tax are entered inclusive by default.
             * A per-document override exists; this is only the starting point,
             * because a retailer quotes inclusive and a wholesaler does not.
             */
            $table->boolean('is_inclusive_default')->default(false);

            /*
             * A zero-rated supply is taxable at 0% and appears on the return;
             * an exempt supply is outside the tax entirely and does not. They
             * are not the same, and reporting them as one is a filing error.
             */
            $table->boolean('is_zero_rated')->default(false);
            $table->boolean('is_exempt')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->timestamp('archived_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'applies_to', 'is_active']);
        });

        Schema::create('tax_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('tax_id');

            $table->string('name', 80);

            /*
             * Six decimal places: a rate is a proportion, not money, and
             * 0.0825 or 0.166667 both occur. Stored as a fraction rather than
             * a percentage so nothing has to remember to divide by 100.
             */
            $table->decimal('rate', 9, 6);

            // Order matters for compounding, so it is explicit.
            $table->unsignedTinyInteger('sequence')->default(1);

            /*
             * A compound component taxes the preceding components as well as
             * the net. Rare, but where it applies, ignoring it understates the
             * liability.
             */
            $table->boolean('is_compound')->default(false);

            /*
             * Where each direction lands. Sales tax charged is a liability;
             * purchase tax paid is a receivable — the SAME component can be
             * both, on different documents, which is why there are two
             * columns rather than one.
             */
            $table->uuid('output_account_id')->nullable();
            $table->uuid('input_account_id')->nullable();

            /*
             * Effective dating. A rate that changes on 1 July does not touch
             * June's invoices, and the calculator resolves the component that
             * was in force on the document's own date.
             */
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes')->cascadeOnDelete();
            $table->foreign('output_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('input_account_id')->references('id')->on('accounts')->nullOnDelete();

            $table->index(['organization_id', 'tax_id', 'effective_from']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE taxes
                ADD CONSTRAINT taxes_applies_to_known
                    CHECK (applies_to IN ('sales','purchase','both','withholding')),
                ADD CONSTRAINT taxes_code_not_blank
                    CHECK (btrim(code) <> ''),
                -- Zero-rated and exempt are different treatments, so a tax
                -- cannot claim both.
                ADD CONSTRAINT taxes_zero_rated_or_exempt
                    CHECK (NOT (is_zero_rated AND is_exempt));

            ALTER TABLE tax_components
                -- A rate above 100% is a percentage entered as a fraction, or
                -- the reverse. Either way it is a mistake worth refusing.
                ADD CONSTRAINT tax_components_rate_range
                    CHECK (rate >= 0 AND rate <= 1),
                ADD CONSTRAINT tax_components_sequence_positive
                    CHECK (sequence >= 1),
                ADD CONSTRAINT tax_components_dates_ordered
                    CHECK (effective_to IS NULL OR effective_to >= effective_from);
        SQL);

        /*
         * One version of a component per start date, so resolving "the rate on
         * this document's date" has exactly one answer.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tax_components_version_unique
                ON tax_components (tax_id, sequence, effective_from)
        SQL);

        foreach (['taxes', 'tax_components'] as $table) {
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
        Schema::dropIfExists('tax_components');
        Schema::dropIfExists('taxes');
    }
};
