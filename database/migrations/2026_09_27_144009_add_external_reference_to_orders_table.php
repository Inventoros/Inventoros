<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order reference from the system an order was imported from. Unique per
 * organization so re-importing the same file never duplicates an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('external_reference')->nullable()->after('external_id');
            $table->unique(['organization_id', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'external_reference']);
            $table->dropColumn('external_reference');
        });
    }
};
