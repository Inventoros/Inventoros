<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of what a supplier charged for a product.
 *
 * A row is written whenever a product's supplier cost changes (product form,
 * API, import) and whenever a purchase order line is received at a unit cost,
 * so the product page can show how a supplier's price moved over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('cost_price', 12, 2);
            // 'supplier_link' = cost set on the product/supplier link,
            // 'purchase_order' = cost a PO line was received at.
            $table->string('source', 32)->default('supplier_link');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            // Named explicitly: the generated name is 68 characters and MySQL
            // rejects identifiers over 64, which broke migrate on MySQL.
            $table->index(['organization_id', 'product_id', 'recorded_at'], 'supplier_price_history_org_product_recorded_index');
            $table->index(['supplier_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_price_history');
    }
};
