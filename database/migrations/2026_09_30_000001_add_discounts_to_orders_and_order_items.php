<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real discounts on sales orders.
 *
 * Each line may carry its own discount and the order may carry one more on
 * top. `discount_type` is 'percent' or 'fixed' (null = no discount),
 * `discount_value` is what was entered (a percentage or a money amount) and
 * `discount_amount` is the resolved money value the server computed from it.
 *
 * On orders, `discount_amount` is the TOTAL discount (every line discount plus
 * the order-level discount), so a stored order row always reconciles on its
 * own: subtotal - discount_amount + tax + shipping = total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('discount_type', 16)->nullable()->after('subtotal');
            $table->decimal('discount_value', 12, 2)->nullable()->after('discount_type');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_value');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('discount_type', 16)->nullable()->after('subtotal');
            $table->decimal('discount_value', 12, 2)->nullable()->after('discount_type');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']);
        });
    }
};
