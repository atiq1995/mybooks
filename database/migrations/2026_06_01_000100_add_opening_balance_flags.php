<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mark the documents that came from a previous system.
 *
 * An opening invoice is a real invoice: it has a number, a customer, a due
 * date, and it ages exactly like any other — which is the whole reason it is
 * a document rather than a lump in the AR control account. What makes it
 * different is only where its contra side goes. §4.13 sends that to opening
 * balance equity rather than to revenue, because the sale itself happened in
 * the old system and was reported there.
 *
 * So the flag does not change how the document behaves. It answers a question
 * somebody will ask: "which of these did we actually sell, and which did we
 * carry over". Without it, the first month's revenue report on a migrated set
 * of books is impossible to explain — and the answer would otherwise have to
 * be inferred from which account a line happens to point at.
 *
 * @see ACCOUNTING_RULES.md §4.13
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table): void {
            $table->boolean('is_opening_balance')->default(false);
        });

        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->boolean('is_opening_balance')->default(false);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE sales_documents
                -- Only an invoice or a credit note can be carried over: a
                -- quote or an order from the old system is not a balance,
                -- and pretending it is would put a commitment in the ledger.
                ADD CONSTRAINT sales_documents_opening_only_posting_types
                    CHECK (NOT is_opening_balance OR type IN ('invoice','credit_note'));

            ALTER TABLE purchase_documents
                ADD CONSTRAINT purchase_documents_opening_only_posting_types
                    CHECK (NOT is_opening_balance OR type IN ('bill','vendor_credit'));
        SQL);

        // The query the opening-balances screen reads, and the one a revenue
        // report has to exclude.
        DB::statement(<<<'SQL'
            CREATE INDEX sales_documents_opening_balances
                ON sales_documents (organization_id, issue_date)
                WHERE is_opening_balance
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX purchase_documents_opening_balances
                ON purchase_documents (organization_id, issue_date)
                WHERE is_opening_balance
        SQL);
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table): void {
            $table->dropColumn('is_opening_balance');
        });

        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->dropColumn('is_opening_balance');
        });
    }
};
