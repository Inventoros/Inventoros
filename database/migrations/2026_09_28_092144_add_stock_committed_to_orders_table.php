<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * Backfill: every existing order is marked true (the column default). The
 * historical import is new in this release, so no existing order can be one.
 * The ledger cannot be used to tell: orders created before order_fulfillment
 * ledger rows existed did take stock but have no such rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('stock_committed')->default(true)->after('approval_notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stock_committed');
        });
    }
};
