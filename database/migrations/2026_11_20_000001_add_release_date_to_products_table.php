<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // A future release date makes the product a preorder: it can be
            // ordered past stock while backorders are on, and orders holding
            // it cannot ship before the date.
            $table->date('release_date')->nullable()->after('allow_backorder');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('release_date');
        });
    }
};
