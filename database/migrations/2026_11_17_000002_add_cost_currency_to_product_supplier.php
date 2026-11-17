<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier link's cost is in a currency of its own (#429). Until now it was
 * implicitly in the supplier's currency, so changing a supplier's currency
 * re-denominated every cost already entered for it. Existing links take the
 * supplier's current currency (what they were read in so far), else the
 * organization's, else the install default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_supplier', function (Blueprint $table) {
            $table->char('cost_currency', 3)->nullable()->after('cost_price');
        });

        DB::table('product_supplier')->whereNull('cost_currency')->update([
            'cost_currency' => DB::raw("(SELECT UPPER(NULLIF(TRIM(suppliers.currency), '')) FROM suppliers WHERE suppliers.id = product_supplier.supplier_id)"),
        ]);

        DB::table('product_supplier')->whereNull('cost_currency')->update([
            'cost_currency' => DB::raw("(SELECT UPPER(NULLIF(TRIM(organizations.currency), '')) FROM products INNER JOIN organizations ON organizations.id = products.organization_id WHERE products.id = product_supplier.product_id)"),
        ]);

        DB::table('product_supplier')->whereNull('cost_currency')->update([
            'cost_currency' => strtoupper((string) config('currencies.default', 'USD')),
        ]);
    }

    public function down(): void
    {
        Schema::table('product_supplier', function (Blueprint $table) {
            $table->dropColumn('cost_currency');
        });
    }
};
