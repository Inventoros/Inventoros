<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax rates, in percent, on products and on sales order lines.
 *
 * `products.tax_rate` is the rate the product is sold at (for example 27 for
 * 27% VAT). It is informational for the product's own price, which stays the
 * net (tax exclusive) selling price, and pre-fills new order lines.
 *
 * `order_items.tax_rate` is the rate a line was taxed at. When it is set the
 * server computes the line's `tax` amount from it; when it is null the line's
 * `tax` is an amount as entered (CSV import, API), exactly as before.
 *
 * Both are nullable: null means "no rate", which is different from 0%.
 * decimal(6,3) holds rates such as 27, 5.5 or 9.375.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 3)->nullable()->after('purchase_price');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 3)->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
