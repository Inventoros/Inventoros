<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record on each order whether creating it took its lines out of stock.
 *
 * Historical imports (and imported orders that arrive already cancelled) are
 * recorded without touching inventory, because those goods left long ago and
 * current stock already reflects it. Nothing on the order said so, and so
 * cancelling, rejecting, deleting or editing one "restocked" units that were
 * never taken. Every restock path now reads this flag.
 *
 * Backfill: an order committed stock exactly when order creation wrote its
 * order_fulfillment ledger rows, so an order with lines but no such row was
 * recorded without touching stock and is marked false. Everything else stays
 * true (the column default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('stock_committed')->default(true)->after('approval_notes');
        });

        DB::table('orders')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id');
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('stock_adjustments')
                    ->whereColumn('stock_adjustments.reference_id', 'orders.id')
                    ->where('stock_adjustments.reference_type', 'App\\Models\\Order\\Order')
                    ->where('stock_adjustments.type', 'order_fulfillment');
            })
            ->update(['stock_committed' => false]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stock_committed');
        });
    }
};
