<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal years and the periods inside them.
 *
 * Posting into a closed period is refused unless the actor holds
 * `accounting.post_to_closed_period`, which is audited on every use. A locked
 * period can never be reopened — that is what "we have filed this" means.
 *
 * @see ACCOUNTING_RULES.md §7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Label, not a calendar year: Pakistan's 2026-27 tax year runs
            // July 2026 to June 2027.
            $table->string('label', 20);

            $table->date('starts_on');
            $table->date('ends_on');

            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();

            // The year-end entry that moved net income to retained earnings.
            $table->uuid('closing_entry_id')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'label']);
            $table->index(['organization_id', 'starts_on']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('fiscal_year_id');

            // 1-12 within the year, in fiscal order rather than calendar order.
            $table->unsignedTinyInteger('sequence');
            $table->string('label', 20);

            $table->date('starts_on');
            $table->date('ends_on');

            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('fiscal_year_id')->references('id')->on('fiscal_years')->cascadeOnDelete();

            $table->unique(['fiscal_year_id', 'sequence']);
            // Finding the period for a posting date is the hottest query in
            // the ledger, so it gets an index shaped for exactly that.
            $table->index(['organization_id', 'starts_on', 'ends_on']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fiscal_years
                ADD CONSTRAINT fiscal_years_status_known
                    CHECK (status IN ('open','closed','locked')),
                ADD CONSTRAINT fiscal_years_dates_ordered
                    CHECK (ends_on > starts_on);

            ALTER TABLE fiscal_periods
                ADD CONSTRAINT fiscal_periods_status_known
                    CHECK (status IN ('open','closed','locked')),
                ADD CONSTRAINT fiscal_periods_dates_ordered
                    CHECK (ends_on >= starts_on),
                ADD CONSTRAINT fiscal_periods_sequence_range
                    CHECK (sequence BETWEEN 1 AND 12);
        SQL);

        /*
         * Periods within an organisation must not overlap, or a posting date
         * would fall into two of them and the ledger would have two answers
         * for which period it belongs to.
         */
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_periods
                ADD CONSTRAINT fiscal_periods_no_overlap
                EXCLUDE USING gist (
                    organization_id WITH =,
                    daterange(starts_on, ends_on, '[]') WITH &&
                )
        SQL);

        foreach (['fiscal_years', 'fiscal_periods'] as $table) {
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
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
