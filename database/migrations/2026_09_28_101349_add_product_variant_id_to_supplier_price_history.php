<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which variant a supplier cost was for.
 *
 * Receiving a variant purchase order line used to skip the price history
 * entirely. The variant is nullable because product-level costs (supplier
 * links, simple products) have no variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_price_history', function (Blueprint $table) {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_price_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
