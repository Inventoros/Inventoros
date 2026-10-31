<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_replenishment_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('product_locations');
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('managed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('reorder_point');
            $table->unsignedInteger('target_stock');
            $table->unsignedInteger('reorder_quantity')->nullable();
            $table->unsignedInteger('minimum_order_quantity')->default(1);
            $table->decimal('unit_cost', 16, 2);
            $table->char('currency', 3);
            $table->string('supplier_sku')->nullable();
            $table->boolean('auto_reorder')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['product_variant_id', 'warehouse_id'], 'variant_replenishment_variant_warehouse_unique');
            $table->index(['organization_id', 'auto_reorder', 'id'], 'variant_replenishment_auto_index');
        });
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->foreignId('replenishment_policy_id')->nullable()->constrained('variant_replenishment_policies')->nullOnDelete();
            $table->foreignId('receiving_location_id')->nullable()->constrained('product_locations');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropForeign(['replenishment_policy_id']);
            $table->dropForeign(['receiving_location_id']);
            $table->dropColumn(['replenishment_policy_id', 'receiving_location_id']);
        });
        Schema::dropIfExists('variant_replenishment_policies');
    }
};
