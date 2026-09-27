<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional storage capacity, in units, for warehouses and their locations.
 * Used only to show utilisation; nothing is refused when a location is full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->nullable()->after('priority');
        });

        Schema::table('product_locations', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->nullable()->after('bin');
        });
    }

    public function down(): void
    {
        Schema::table('product_locations', function (Blueprint $table) {
            $table->dropColumn('capacity');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('capacity');
        });
    }
};
