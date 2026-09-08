<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers and vendors.
 *
 * One table, not two. The same business is very often both — you buy from
 * your printer and you invoice them for consultancy — and two rows means two
 * balances, two addresses and two sets of tax numbers that drift apart. A
 * `kind` column says which side of the ledger a contact appears on, and
 * "both" is a first-class answer.
 *
 * @see ACCOUNTING_RULES.md §4.1, §4.6
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // 'customer' | 'vendor' | 'both'
            $table->string('kind', 10)->default('customer');

            /*
             * What appears on a document. Kept separate from the legal name
             * because an invoice addressed to "Acme" needs to say "Acme
             * (Private) Limited" in the fine print, and both are searched.
             */
            $table->string('display_name', 160);
            $table->string('legal_name', 200)->nullable();

            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website', 200)->nullable();

            /*
             * Pakistan: NTN and STRN. Named generically so another
             * jurisdiction maps onto the same columns.
             */
            $table->string('tax_registration_number', 50)->nullable();
            $table->string('sales_tax_registration_number', 50)->nullable();

            /*
             * Whether the contact files tax returns. In Pakistan the
             * withholding rate for a non-filer is roughly double a filer's,
             * so this is not a note — it changes the arithmetic at payment.
             */
            $table->boolean('is_tax_filer')->default(true);

            /*
             * The currency this contact is billed in. Documents in another
             * currency carry a rate; the base amount is fixed at posting.
             */
            $table->char('currency', 3)->nullable();

            // Days from issue to due. 0 means due on receipt.
            $table->unsignedSmallInteger('payment_terms_days')->default(30);

            /*
             * A credit limit is advisory, not enforced: refusing to invoice a
             * customer who has ordered is a commercial decision, not a
             * database constraint. The UI warns; nothing blocks.
             */
            $table->decimal('credit_limit', 19, 4)->nullable();

            $table->jsonb('billing_address')->nullable();
            $table->jsonb('shipping_address')->nullable();

            $table->text('notes')->nullable();

            /*
             * Override control accounts, for the rare organisation that keeps
             * separate receivables by segment. Null means the system account.
             */
            $table->uuid('receivable_account_id')->nullable();
            $table->uuid('payable_account_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->timestamp('archived_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('receivable_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('payable_account_id')->references('id')->on('accounts')->nullOnDelete();

            /*
             * Names are NOT unique. Two genuinely different businesses can
             * share a name, and refusing the second one is worse than showing
             * both — so the UI warns about a near-duplicate and the database
             * allows it.
             */
            $table->index(['organization_id', 'kind', 'display_name']);
            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'email']);
        });

        Schema::create('contact_persons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('contact_id');

            $table->string('name', 160);
            $table->string('role', 80)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();

            // Who documents are addressed to by default.
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();

            $table->index(['organization_id', 'contact_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE contacts
                ADD CONSTRAINT contacts_kind_known
                    CHECK (kind IN ('customer','vendor','both')),
                ADD CONSTRAINT contacts_currency_format
                    CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT contacts_display_name_not_blank
                    CHECK (btrim(display_name) <> ''),
                ADD CONSTRAINT contacts_credit_limit_non_negative
                    CHECK (credit_limit IS NULL OR credit_limit >= 0),
                -- Terms beyond a year are a data-entry slip, not a business
                -- arrangement.
                ADD CONSTRAINT contacts_terms_sane
                    CHECK (payment_terms_days BETWEEN 0 AND 365);
        SQL);

        // At most one primary person per contact: two "default" recipients is
        // unresolvable when a document has to pick one.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX contact_persons_one_primary
                ON contact_persons (contact_id)
                WHERE is_primary
        SQL);

        foreach (['contacts', 'contact_persons'] as $table) {
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
        Schema::dropIfExists('contact_persons');
        Schema::dropIfExists('contacts');
    }
};
