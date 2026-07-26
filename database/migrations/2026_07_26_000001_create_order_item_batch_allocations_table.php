<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how much of each batch an order line consumed.
 *
 * Batch consumption is spread across batches (FEFO), so unlike a serial — one
 * discrete unit with a status flip — restoring a cancelled order needs to know
 * exactly how much came from which batch. Each row is one (order line, batch)
 * consumption; releasing an order adds the quantity back to its batch and
 * deletes the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_batch_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_batch_id')->constrained('product_batches')->cascadeOnDelete();
            $table->integer('quantity');
            $table->timestamps();

            $table->index('order_item_id');
            $table->index('product_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_batch_allocations');
    }
};
