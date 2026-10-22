<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_order_items', function (Blueprint $table) {
            // Null identifies legacy rows; their financial history is preserved.
            $table->decimal('refund_amount', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('return_order_items', function (Blueprint $table) {
            $table->dropColumn('refund_amount');
        });
    }
};
