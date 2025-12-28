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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('title')->nullable(); // e.g., "Small / Red" - auto-generated from options
            $table->json('option_values'); // e.g., {"Size": "Small", "Color": "Red"}
            $table->decimal('price', 10, 2)->nullable(); // Override product price
            $table->decimal('purchase_price', 10, 2)->nullable(); // Override product cost
            $table->decimal('compare_at_price', 10, 2)->nullable(); // Original price for discounts
            $table->integer('stock')->default(0);
            $table->integer('min_stock')->default(0);
            $table->string('image')->nullable(); // Variant-specific image
            $table->decimal('weight', 10, 3)->nullable(); // Weight in default unit
            $table->string('weight_unit', 10)->default('kg'); // kg, lb, oz, g
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_shipping')->default(true);
            $table->integer('position')->default(0); // Display order
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'sku']);
            $table->index(['product_id', 'position']);
            $table->index('barcode');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
