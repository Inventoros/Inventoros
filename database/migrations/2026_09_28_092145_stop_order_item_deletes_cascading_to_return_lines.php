<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A return line records units that came back against one order line. It was
 * declared ON DELETE CASCADE from order_items, so anything that deleted and
 * recreated an order's lines silently erased received returns and reset the
 * returnable cap, letting the same units be returned (and restocked) again.
 *
 * NO ACTION refuses deleting an order line that return lines still point at.
 * It is checked at the end of the statement, so deleting a whole return
 * together with its lines still works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_order_items', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
        });

        Schema::table('return_order_items', function (Blueprint $table) {
            $table->foreign('order_item_id')->references('id')->on('order_items')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_order_items', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
        });

        Schema::table('return_order_items', function (Blueprint $table) {
            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
        });
    }
};
