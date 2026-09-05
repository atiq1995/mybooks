<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations — the tenant boundary.
 *
 * Every organisation-owned table in the system carries `organization_id`
 * referencing this table, and every one of them gets a row-level security
 * policy. This table itself is not organisation-scoped: it *is* the scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();

            // -- Accounting identity -----------------------------------
            // The base currency is immutable once anything is posted: every
            // journal line stores a base-currency amount computed at posting
            // time, and changing the base afterwards would invalidate all of
            // them. Enforced in the domain, documented here.
            $table->char('base_currency', 3);

            // ISO 3166-1 alpha-2. Drives address formatting and defaults.
            $table->char('country_code', 2);

            // Tax rule pack key — 'PK' ships first. The tax engine is
            // jurisdiction-agnostic; the pack supplies rates and behaviours.
            $table->string('jurisdiction', 10)->default('PK');

            // Month the financial year begins: 1-12. Pakistan's tax year
            // runs July-June, so this is 7 far more often than it is 1.
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(7);

            // Rounding is an accounting policy, not a formatting preference.
            // Changing it changes reported figures. ACCOUNTING_RULES.md §2.
            $table->string('rounding_mode', 20)->default('HALF_UP');

            // -- Registration numbers ----------------------------------
            // Pakistan: NTN (income tax) and STRN (sales tax). Named
            // generically so other jurisdictions map onto the same columns.
            $table->string('tax_registration_number')->nullable();
            $table->string('sales_tax_registration_number')->nullable();
            $table->string('business_registration_number')->nullable();

            // -- Presentation ------------------------------------------
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->string('date_format', 20)->default('d M Y');

            // Address as JSON: field sets differ enough between countries
            // that a fixed column layout is wrong for most of them.
            $table->jsonb('address')->nullable();

            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // Object storage key, never a filesystem path. SECURITY.md §7.
            $table->string('logo_path')->nullable();

            // -- Lifecycle ---------------------------------------------
            // Null until the onboarding wizard completes. Until then the
            // organisation exists but has no usable chart of accounts, so
            // the application routes its users back into onboarding.
            $table->timestamp('onboarding_completed_at')->nullable();

            $table->uuid('created_by')->nullable();

            $table->timestamps();

            // Archived, not deleted. An organisation's books must remain
            // recoverable and auditable after it stops being used.
            $table->timestamp('archived_at')->nullable();

            $table->index('archived_at');
            $table->index('onboarding_completed_at');
        });

        // Currency and country codes are uppercase by convention everywhere
        // in the system; enforcing it here means no query ever has to guess.
        DB::statement(<<<'SQL'
            ALTER TABLE organizations
                ADD CONSTRAINT organizations_base_currency_format
                    CHECK (base_currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT organizations_country_code_format
                    CHECK (country_code ~ '^[A-Z]{2}$'),
                ADD CONSTRAINT organizations_fiscal_month_range
                    CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
                ADD CONSTRAINT organizations_rounding_mode_known
                    CHECK (rounding_mode IN ('HALF_UP','HALF_DOWN','HALF_EVEN','UP','DOWN'))
        SQL);

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('last_organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['last_organization_id']);
        });

        Schema::dropIfExists('organizations');
    }
};
