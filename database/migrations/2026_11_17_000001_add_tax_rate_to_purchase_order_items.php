<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tax rate, in percent, a purchase order line was taxed at.
 *
 * When it is set the server computes the line's `tax` amount from it (the
 * same rules as sales order lines, see App\Support\LineTax); when it is null
 * the line's `tax` is an amount as given, which for every existing line is
 * 0. Null means "no rate", which is different from 0%. decimal(6,3) holds
 * rates such as 27, 5.5 or 9.375.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 3)->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
