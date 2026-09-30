<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the variant a purchase order line buys. Nullable: lines for
     * products without variants leave it null, and existing rows stay valid.
     * When set, receiving the line credits the variant's own stock instead of
     * the parent product's.
     */
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();

            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        // Foreign key and the explicit index go first: SQLite refuses to drop
        // a column that an index still names.
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropIndex(['product_variant_id']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('product_variant_id');
        });
    }
};
