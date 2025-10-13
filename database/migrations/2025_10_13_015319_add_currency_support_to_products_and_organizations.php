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
        // Add default currency to organizations
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('default_currency', 3)->default('USD')->after('is_active');
            $table->json('supported_currencies')->nullable()->after('default_currency');
        });

        // Add currency field to products
        Schema::table('products', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('price');
            $table->json('price_in_currencies')->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['default_currency', 'supported_currencies']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['currency', 'price_in_currencies']);
        });
    }
};
