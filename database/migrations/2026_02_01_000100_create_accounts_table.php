<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts.
 *
 * Five root types, each with a normal balance that decides whether a debit
 * increases or decreases it. Contra accounts carry the opposite normal balance
 * within their type — trade discounts are an income account with a debit
 * normal balance — which is why `normal_balance` is stored rather than derived
 * from `type`.
 *
 * @see ACCOUNTING_RULES.md §3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Account number, e.g. 1200. Unique per organisation, and what
            // accountants actually navigate by.
            $table->string('code', 20);
            $table->string('name');
            $table->string('description')->nullable();

            $table->string('type', 20);
            $table->string('subtype', 40)->nullable();

            // 'debit' or 'credit'. Stored, not derived — contra accounts
            // invert it within their type.
            $table->string('normal_balance', 6);

            // A tree, so a report can roll children into a parent.
            $table->uuid('parent_id')->nullable();

            /*
             * The role this account plays for the machinery — 'accounts_
             * receivable', 'gst_output', 'retained_earnings'. Null for
             * ordinary accounts. A system account cannot be deleted, and its
             * type cannot change once anything is posted.
             */
            $table->string('system_role', 40)->nullable();

            // Bank and cash accounts may be denominated in a currency other
            // than the organisation's base.
            $table->char('currency', 3)->nullable();

            $table->boolean('is_active')->default(true);

            /*
             * A parent that only groups its children. Postings go to leaves:
             * posting to a header would make its subtotal meaningless.
             */
            $table->boolean('is_header')->default(false);

            $table->timestamps();
            $table->timestamp('archived_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'type', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        /*
         * The parent link is added after the table exists.
         *
         * A self-referencing foreign key declared inside Schema::create runs
         * before the primary key is in place, so PostgreSQL rejects it with
         * "no unique constraint matching given keys".
         */
        Schema::table('accounts', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('accounts')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE accounts
                ADD CONSTRAINT accounts_type_known
                    CHECK (type IN ('asset','liability','equity','income','expense')),
                ADD CONSTRAINT accounts_normal_balance_known
                    CHECK (normal_balance IN ('debit','credit')),
                ADD CONSTRAINT accounts_currency_format
                    CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
                -- A header groups; it never holds a posting, so it never
                -- carries a currency of its own.
                ADD CONSTRAINT accounts_header_has_no_currency
                    CHECK (NOT is_header OR currency IS NULL),
                ADD CONSTRAINT accounts_code_not_blank
                    CHECK (btrim(code) <> '')
        SQL);

        // One account per system role per organisation: two accounts both
        // claiming to be "the" AR control account is unresolvable.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX accounts_system_role_unique
                ON accounts (organization_id, system_role)
                WHERE system_role IS NOT NULL
        SQL);

        DB::statement('ALTER TABLE accounts ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY accounts_tenant_isolation ON accounts
                USING (app_is_unscoped() OR organization_id = app_current_organization_id())
                WITH CHECK (app_is_unscoped() OR organization_id = app_current_organization_id());
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
