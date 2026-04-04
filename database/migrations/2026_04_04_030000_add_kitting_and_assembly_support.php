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
        // Add type column to products
        Schema::table('products', function (Blueprint $table) {
            $table->string('type', 20)->default('standard')->after('organization_id');
            $table->index(['organization_id', 'type']);
        });

        // Product components (BOM) - links kit/assembly to their components
        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('component_product_id')->constrained('products')->onDelete('cascade');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['parent_product_id', 'component_product_id']);
            $table->index('component_product_id');
        });

        // Work orders for assembly production
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('work_order_number')->unique();
            $table->integer('quantity')->default(1);
            $table->integer('quantity_produced')->default(0);
            $table->enum('status', ['draft', 'pending', 'in_progress', 'completed', 'cancelled'])->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'product_id']);
        });

        // Work order component consumption tracking
        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->decimal('quantity_required', 10, 2);
            $table->decimal('quantity_consumed', 10, 2)->default(0);
            $table->timestamps();

            $table->index('work_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('product_components');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'type']);
            $table->dropColumn('type');
        });
    }
};
