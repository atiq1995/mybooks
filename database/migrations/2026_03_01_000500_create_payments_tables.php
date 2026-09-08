<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, and what they were applied to.
 *
 * A payment is not "an invoice being paid". It is money arriving, which may
 * settle several invoices, part of one, or nothing at all — an advance. That
 * is why allocation is its own table: modelling a payment as a column on an
 * invoice cannot express any of those three cases, and all three are
 * ordinary.
 *
 * Withholding sits on the payment, never on the invoice. The customer settles
 * the invoice in full and hands part of it to the tax authority on our
 * behalf; the withheld amount is a receivable from that authority, not a
 * discount and not a shortfall. The invoice's balance goes to zero either
 * way.
 *
 * @see ACCOUNTING_RULES.md §4.2, §4.3, §4.4, §5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            /*
             * 'received' from a customer, 'made' to a vendor. One table again,
             * because allocation, withholding, over-payment and the bank side
             * behave identically in both directions — only the sign and the
             * control account differ.
             */
            $table->string('direction', 10);

            $table->string('number', 30);

            $table->uuid('contact_id');
            $table->date('payment_date');

            // Where the money landed or left from.
            $table->uuid('bank_account_id');

            $table->string('method', 20)->default('bank_transfer');
            $table->string('reference', 80)->nullable();

            $table->char('currency', 3);
            $table->decimal('exchange_rate', 19, 10)->default(1);

            /*
             * `amount` is what settles the contact's balance — the invoice
             * value being cleared. `amount_received` is what actually hit the
             * bank. They differ by exactly the withholding, which is the
             * whole point of keeping both.
             */
            $table->decimal('amount', 19, 4);
            $table->decimal('withholding_amount', 19, 4)->default(0);
            $table->decimal('amount_received', 19, 4);

            $table->decimal('amount_base', 19, 4);
            $table->decimal('withholding_amount_base', 19, 4)->default(0);

            // Which withholding tax was applied, for the return.
            $table->uuid('withholding_tax_id')->nullable();

            /*
             * How much of this payment has been applied to documents. The
             * remainder is an advance, and it is a real liability — we owe
             * goods, services or a refund — so it is tracked rather than
             * inferred.
             */
            $table->decimal('allocated_amount', 19, 4)->default(0);

            $table->string('status', 20)->default('completed');

            $table->text('notes')->nullable();

            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('void_journal_entry_id')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts');
            $table->foreign('bank_account_id')->references('id')->on('accounts');
            $table->foreign('withholding_tax_id')->references('id')->on('taxes')->nullOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('void_journal_entry_id')->references('id')->on('journal_entries');

            $table->unique(['organization_id', 'direction', 'number']);

            $table->index(['organization_id', 'direction', 'payment_date']);
            $table->index(['organization_id', 'contact_id', 'direction', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->uuid('payment_id');
            $table->uuid('sales_document_id');

            // In document currency, which may differ from the payment's.
            $table->decimal('amount', 19, 4);
            $table->decimal('amount_base', 19, 4);

            /*
             * Realised FX on this allocation.
             *
             * The receivable clears at the rate on the INVOICE, never a
             * re-derived one; the bank receives at today's rate. The
             * difference is a realised gain or loss, and it belongs to the
             * allocation rather than the payment, because a payment settling
             * two invoices at two different original rates has two different
             * gains.
             */
            $table->decimal('fx_gain_loss_base', 19, 4)->default(0);

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            $table->foreign('sales_document_id')->references('id')->on('sales_documents');

            // One allocation row per payment per document; changing an
            // allocation updates it rather than adding a second.
            $table->unique(['payment_id', 'sales_document_id']);

            $table->index(['organization_id', 'sales_document_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_direction_known
                    CHECK (direction IN ('received','made')),
                ADD CONSTRAINT payments_status_known
                    CHECK (status IN ('draft','completed','refunded','bounced','void')),
                ADD CONSTRAINT payments_method_known
                    CHECK (method IN ('cash','cheque','bank_transfer','card','online','other')),
                ADD CONSTRAINT payments_currency_format
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT payments_rate_positive
                    CHECK (exchange_rate > 0),
                -- A payment of nothing is not a payment.
                ADD CONSTRAINT payments_amount_positive
                    CHECK (amount > 0),
                ADD CONSTRAINT payments_withholding_non_negative
                    CHECK (withholding_amount >= 0),
                -- Withholding is a share of the payment, not an addition to
                -- it: withholding more than the whole amount is arithmetic
                -- nobody meant.
                ADD CONSTRAINT payments_withholding_within_amount
                    CHECK (withholding_amount <= amount),
                -- The identity that makes both columns meaningful. If this
                -- ever fails, the bank side and the ledger side disagree.
                ADD CONSTRAINT payments_received_is_amount_less_withholding
                    CHECK (amount_received = amount - withholding_amount),
                ADD CONSTRAINT payments_allocated_within_amount
                    CHECK (allocated_amount >= 0 AND allocated_amount <= amount);

            ALTER TABLE payment_allocations
                ADD CONSTRAINT payment_allocations_amount_positive
                    CHECK (amount > 0);
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_journal_entry_unique
                ON payments (journal_entry_id)
                WHERE journal_entry_id IS NOT NULL
        SQL);

        foreach (['payments', 'payment_allocations'] as $table) {
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
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
