<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-warehouse stock thresholds for a product. When a row exists, low-stock
 * detection and reorder suggestions compare the product's on-hand in that
 * warehouse (the sum of its bins at the warehouse's locations) against these
 * values; products without rows keep using the product-level thresholds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_reorder_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('reorder_point')->nullable();
            $table->unsignedInteger('reorder_quantity')->nullable();
            $table->unsignedInteger('min_stock')->nullable();
            $table->unsignedInteger('max_stock')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
            $table->index(['organization_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_reorder_points');
    }
};
