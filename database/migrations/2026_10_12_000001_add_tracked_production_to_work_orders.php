<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets work orders produce and consume variants, serial-tracked and
 * batch-tracked products, and consume virtual kits (exploded into their
 * components when the work order is created).
 *
 *  - work_orders.product_variant_id: the variant the order produces.
 *  - work_order_items.product_variant_id: the variant a component line draws on.
 *  - work_order_items.kit_product_id: the kit a component line was exploded from.
 *  - work_order_allocations: which serials / batch quantities each component
 *    line consumed when the order completed.
 *  - work_order_outputs: which variant, serials or batch the order produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->nullOnDelete();
        });

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->nullOnDelete();
            $table->foreignId('kit_product_id')->nullable()->after('product_variant_id')
                ->constrained('products')->nullOnDelete();
        });

        Schema::create('work_order_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_serial_id')->nullable()->constrained('product_serials')->nullOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->integer('quantity');
            $table->timestamps();

            $table->index('work_order_id');
            $table->index('work_order_item_id');
            $table->index('product_serial_id');
            $table->index('product_batch_id');
        });

        Schema::create('work_order_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('product_serial_id')->nullable()->constrained('product_serials')->nullOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->integer('quantity');
            $table->timestamps();

            $table->index('work_order_id');
            $table->index('product_serial_id');
            $table->index('product_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_outputs');
        Schema::dropIfExists('work_order_allocations');

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kit_product_id');
            $table->dropConstrainedForeignId('product_variant_id');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
