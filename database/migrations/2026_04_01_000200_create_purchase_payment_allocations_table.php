<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a vendor payment settled.
 *
 * `payments` already carries a `direction`, so it serves both sides without
 * change — allocation, withholding, over-payment and the bank side behave
 * identically whichever way the money moves, which is why Phase 3 built it
 * that way. Only the documents being settled differ, and so only the
 * allocation table is new.
 *
 * A second table rather than a nullable `purchase_document_id` on
 * `payment_allocations`: that column would have to be nullable, its sales
 * counterpart would have to become nullable too, and the guarantee that an
 * allocation names exactly one real document would drop from a NOT NULL
 * foreign key to a check constraint nobody reads. Two tables keep both sides
 * total.
 *
 * @see ACCOUNTING_RULES.md §4.7, §4.11
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->uuid('payment_id');
            $table->uuid('purchase_document_id');

            // In document currency, which may differ from the payment's.
            $table->decimal('amount', 19, 4);
            $table->decimal('amount_base', 19, 4);

            /*
             * Realised FX on this allocation.
             *
             * The payable clears at the rate on the BILL, never a re-derived
             * one; the bank pays at today's. The difference belongs to the
             * allocation rather than the payment, because one payment
             * settling two bills booked at two rates has two different
             * differences.
             *
             * The sign convention is the ledger's, not the vendor's: a
             * positive figure is a gain to us. Paying a foreign bill that has
             * become cheaper in rupees is a gain, and it reads as one here.
             */
            $table->decimal('fx_gain_loss_base', 19, 4)->default(0);

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            $table->foreign('purchase_document_id')->references('id')->on('purchase_documents');

            $table->unique(['payment_id', 'purchase_document_id']);

            $table->index(['organization_id', 'purchase_document_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE purchase_payment_allocations
                ADD CONSTRAINT purchase_payment_allocations_amount_positive
                    CHECK (amount > 0);
        SQL);

        DB::statement('ALTER TABLE purchase_payment_allocations ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY purchase_payment_allocations_tenant_isolation
                ON purchase_payment_allocations
                USING (app_is_unscoped() OR organization_id = app_current_organization_id())
                WITH CHECK (app_is_unscoped() OR organization_id = app_current_organization_id());
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payment_allocations');
    }
};
