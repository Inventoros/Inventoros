<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the variant a return line brings back, so receiving the return
     * credits the variant's stock (which the sale decremented) rather than
     * the parent product's. Nullable: lines for products without variants
     * leave it null.
     *
     * Existing return lines are backfilled from the order line they return.
     */
    public function up(): void
    {
        Schema::table('return_order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();

            $table->index('product_variant_id');
        });

        $this->backfill();
    }

    /**
     * One set-based UPDATE with a correlated subquery (portable across SQLite,
     * MySQL and PostgreSQL). Only rows still missing a variant are touched, so
     * it is safe to re-run.
     */
    public function backfill(): void
    {
        DB::table('return_order_items')
            ->whereNull('product_variant_id')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('order_items')
                    ->whereColumn('order_items.id', 'return_order_items.order_item_id')
                    ->whereNotNull('order_items.product_variant_id');
            })
            ->update([
                'product_variant_id' => DB::raw('(SELECT order_items.product_variant_id FROM order_items WHERE order_items.id = return_order_items.order_item_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('return_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
