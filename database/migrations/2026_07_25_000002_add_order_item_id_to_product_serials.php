<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a serial to the order line that consumed it.
 *
 * When a serial-tracked product is sold, the specific serials shipped are
 * marked sold and pinned to their order item, so cancelling or deleting the
 * order can release exactly those units back to available. Nullable: only
 * allocated serials carry a link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->foreignId('order_item_id')
                ->nullable()
                ->after('status')
                ->constrained('order_items')
                ->nullOnDelete();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
        });
    }
};
