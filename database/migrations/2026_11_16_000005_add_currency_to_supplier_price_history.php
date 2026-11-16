<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A received purchase order line in a foreign currency is logged at its cost
 * converted to the organization's currency (at the PO's locked rate), with
 * the supplier's own price and the rate kept beside it. Without a recorded
 * rate the supplier's price is logged as it is, labelled with its currency
 * instead of being read as the organization's. Older rows have no currency
 * and keep showing in the product's currency as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_price_history', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('cost_price');
            $table->decimal('source_cost_price', 12, 2)->nullable()->after('currency');
            $table->char('source_currency', 3)->nullable()->after('source_cost_price');
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('source_currency');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_price_history', function (Blueprint $table) {
            $table->dropColumn(['currency', 'source_cost_price', 'source_currency', 'exchange_rate']);
        });
    }
};
