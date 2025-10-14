<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Adjustment details
            $table->string('type'); // increase, decrease, recount, damage, return
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->integer('adjustment_quantity'); // can be positive or negative

            // Reason and notes
            $table->string('reason')->nullable(); // received_shipment, sold, damaged, expired, theft, recount
            $table->text('notes')->nullable();

            // Reference information
            $table->string('reference_type')->nullable(); // Order, Purchase, etc.
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['product_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
