<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Things you sell and buy.
 *
 * Goods and services in one table, distinguished by `kind`, because a line on
 * an invoice treats them identically — a description, a quantity, a price and
 * a tax. What differs is inventory: only tracked goods have a stock balance
 * and a cost of sale, and only they need an inventory account.
 *
 * An item is a DEFAULT, not a constraint. A line copies the item's price,
 * description, account and tax at the moment it is added, and the user can
 * change any of them. That copy is what makes a historical invoice readable
 * after the item has been renamed or repriced.
 *
 * @see ACCOUNTING_RULES.md §4.1, §4.10
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // 'goods' | 'service'
            $table->string('kind', 10)->default('service');

            $table->string('sku', 60)->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();

            // Unit of measure, as free text: 'hour', 'kg', 'each', 'licence'.
            $table->string('unit', 20)->nullable();

            /*
             * Six decimal places on quantity, four on price: an hourly rate
             * billed in tenths of an hour and a bulk price per gram both fit
             * without rounding at entry.
             */
            $table->decimal('sale_price', 19, 4)->nullable();
            $table->decimal('purchase_price', 19, 4)->nullable();

            // Null means the organisation's base currency.
            $table->char('currency', 3)->nullable();

            /*
             * Where each side posts. Defaults for the line, copied at the
             * moment it is added.
             */
            $table->uuid('sales_account_id')->nullable();
            $table->uuid('purchase_account_id')->nullable();
            $table->uuid('inventory_account_id')->nullable();

            $table->uuid('sales_tax_id')->nullable();
            $table->uuid('purchase_tax_id')->nullable();

            /*
             * Whether stock is tracked. Only meaningful for goods, and it is
             * what decides whether a shipment posts cost of sales — so it is
             * a column rather than an inference from `kind`.
             */
            $table->boolean('is_tracked')->default(false);

            $table->boolean('is_sold')->default(true);
            $table->boolean('is_purchased')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->timestamp('archived_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('sales_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('purchase_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('inventory_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('sales_tax_id')->references('id')->on('taxes')->nullOnDelete();
            $table->foreign('purchase_tax_id')->references('id')->on('taxes')->nullOnDelete();

            $table->index(['organization_id', 'kind', 'name']);
            $table->index(['organization_id', 'is_active', 'is_sold']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE items
                ADD CONSTRAINT items_kind_known
                    CHECK (kind IN ('goods','service')),
                ADD CONSTRAINT items_name_not_blank
                    CHECK (btrim(name) <> ''),
                ADD CONSTRAINT items_prices_non_negative
                    CHECK ((sale_price IS NULL OR sale_price >= 0)
                       AND (purchase_price IS NULL OR purchase_price >= 0)),
                ADD CONSTRAINT items_currency_format
                    CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
                -- A service has no stock to track, so tracking one is a
                -- contradiction rather than a preference.
                ADD CONSTRAINT items_only_goods_are_tracked
                    CHECK (NOT is_tracked OR kind = 'goods'),
                -- Tracked stock has to land somewhere on the balance sheet.
                ADD CONSTRAINT items_tracked_needs_inventory_account
                    CHECK (NOT is_tracked OR inventory_account_id IS NOT NULL),
                -- An item that is neither sold nor purchased cannot appear on
                -- any document, so it is a row nobody can use.
                ADD CONSTRAINT items_usable_somewhere
                    CHECK (is_sold OR is_purchased);
        SQL);

        // A SKU identifies one item. Blank is not a SKU, so those are exempt.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX items_sku_unique
                ON items (organization_id, sku)
                WHERE sku IS NOT NULL AND btrim(sku) <> ''
        SQL);

        DB::statement('ALTER TABLE items ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY items_tenant_isolation ON items
                USING (app_is_unscoped() OR organization_id = app_current_organization_id())
                WITH CHECK (app_is_unscoped() OR organization_id = app_current_organization_id());
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
