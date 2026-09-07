<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document numbering and exchange rates.
 *
 * Numbering is a row that gets locked, NOT a PostgreSQL sequence. Sequences
 * are faster but leave gaps on rollback, and many tax authorities treat a gap
 * in invoice numbering as evidence of a deleted invoice.
 *
 * @see ACCOUNTING_RULES.md §9, §8
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // 'invoice', 'bill', 'journal', 'payment_received'...
            $table->string('document_type', 40);

            $table->string('prefix', 12)->default('');
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);

            // 'never' | 'yearly' | 'monthly' — when the counter restarts.
            $table->string('reset_policy', 10)->default('never');

            // Which year/month the current counter belongs to, so a reset can
            // be detected without a scheduled job.
            $table->unsignedSmallInteger('period_year')->nullable();
            $table->unsignedTinyInteger('period_month')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'document_type']);
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->char('from_currency', 3);
            $table->char('to_currency', 3);

            // Ten decimal places: money needs four, but a rate multiplied by a
            // large amount needs more precision than the result does.
            $table->decimal('rate', 19, 10);

            $table->date('effective_on');

            // 'manual' | 'imported' | 'api' — provenance matters when a
            // figure is questioned months later.
            $table->string('source', 20)->default('manual');

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            // One rate per pair per day. A second would make conversion
            // non-deterministic.
            //
            // No separate lookup index: the unique constraint's own index
            // already serves "latest rate for this pair on or before date",
            // and a second index on the same columns would only collide with
            // it once PostgreSQL truncates both names to 63 characters.
            $table->unique(['organization_id', 'from_currency', 'to_currency', 'effective_on'], 'exchange_rates_pair_day_unique');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE document_sequences
                ADD CONSTRAINT document_sequences_reset_policy_known
                    CHECK (reset_policy IN ('never','yearly','monthly')),
                ADD CONSTRAINT document_sequences_padding_sane
                    CHECK (padding BETWEEN 1 AND 12),
                ADD CONSTRAINT document_sequences_next_number_positive
                    CHECK (next_number >= 1);

            ALTER TABLE exchange_rates
                ADD CONSTRAINT exchange_rates_positive
                    CHECK (rate > 0),
                ADD CONSTRAINT exchange_rates_currency_format
                    CHECK (from_currency ~ '^[A-Z]{3}$' AND to_currency ~ '^[A-Z]{3}$'),
                -- A rate from a currency to itself is always 1 and is never
                -- stored; storing one invites it being wrong.
                ADD CONSTRAINT exchange_rates_not_self
                    CHECK (from_currency <> to_currency),
                ADD CONSTRAINT exchange_rates_source_known
                    CHECK (source IN ('manual','imported','api'));
        SQL);

        foreach (['document_sequences', 'exchange_rates'] as $table) {
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
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('document_sequences');
    }
};
