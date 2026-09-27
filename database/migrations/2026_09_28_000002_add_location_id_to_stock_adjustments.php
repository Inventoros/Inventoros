<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which location bin an adjustment landed in, so adjustment history can
 * be scoped to the warehouses a user is allowed to see. Null for adjustments
 * that only moved the product total (no bin named).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('product_variant_id')
                ->constrained('product_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
