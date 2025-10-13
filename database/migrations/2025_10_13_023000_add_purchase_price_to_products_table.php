<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Rename 'cost' to 'purchase_price' for clarity
            $table->renameColumn('cost', 'purchase_price');

            // Add 'selling_price' column (this will be the actual selling price)
            // We'll keep 'price' as it is and add this as an alias for clarity
            $table->decimal('selling_price', 10, 2)->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('purchase_price', 'cost');
            $table->dropColumn('selling_price');
        });
    }
};
