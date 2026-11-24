<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of what a kit order line drew from stock.
 *
 * The order line is the kit itself; these rows record, per component, how
 * many units each kit used and how many were actually taken. Cancelling,
 * returning or resizing the line works from this snapshot, never from the
 * current bill of materials, which may have been edited since the sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_kit_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            // Products are soft-deleted; a hard-deleted one takes its rows with it.
            $table->foreignId('component_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('quantity_per_kit');
            $table->unsignedInteger('quantity_taken');
            $table->timestamps();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_kit_components');
    }
};
