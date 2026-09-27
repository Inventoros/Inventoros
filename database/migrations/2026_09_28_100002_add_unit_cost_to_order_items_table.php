<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the unit cost of each order line at the time of sale, so profit
     * margin and turnover reports no longer depend on today's purchase price.
     *
     * Existing rows cannot know what they cost when sold, so they are
     * backfilled from the CURRENT cost (the variant's purchase price when it
     * has one, otherwise the product's) and stamped with
     * unit_cost_backfilled_at, which the reports use to flag those figures as
     * estimates. Rows whose product has no cost (or no longer exists) stay
     * NULL: unknown, not zero.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable()->after('unit_price');
            $table->timestamp('unit_cost_backfilled_at')->nullable()->after('unit_cost');
        });

        $this->backfill();
    }

    /**
     * One set-based UPDATE with correlated subqueries (portable across
     * SQLite, MySQL and PostgreSQL), rather than a PHP loop over every line.
     * Only rows still missing a cost are touched, so it is safe to re-run.
     */
    public function backfill(): void
    {
        $currentCost = '(COALESCE(
            (SELECT product_variants.purchase_price FROM product_variants
                WHERE product_variants.id = order_items.product_variant_id
                  AND product_variants.product_id = order_items.product_id),
            (SELECT products.purchase_price FROM products WHERE products.id = order_items.product_id)
        ))';

        DB::table('order_items')
            ->whereNull('unit_cost')
            ->whereNotNull('product_id')
            ->update([
                'unit_cost' => DB::raw($currentCost),
                'unit_cost_backfilled_at' => now(),
            ]);

        // Lines whose cost is still unknown were not estimated from anything.
        DB::table('order_items')
            ->whereNull('unit_cost')
            ->whereNotNull('unit_cost_backfilled_at')
            ->update(['unit_cost_backfilled_at' => null]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'unit_cost_backfilled_at']);
        });
    }
};
