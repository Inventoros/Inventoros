<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization switch for the customer portal. Off by default: nothing
 * under /portal/{slug} answers until an admin turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('portal_enabled')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('portal_enabled');
        });
    }
};
