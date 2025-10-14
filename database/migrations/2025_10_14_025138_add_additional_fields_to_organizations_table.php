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
        Schema::table('organizations', function (Blueprint $table) {
            // Rename zip_code to zip for consistency
            if (Schema::hasColumn('organizations', 'zip_code') && !Schema::hasColumn('organizations', 'zip')) {
                $table->renameColumn('zip_code', 'zip');
            }

            // Only add columns that don't already exist
            if (!Schema::hasColumn('organizations', 'currency')) {
                $table->string('currency', 3)->nullable()->default('USD');
            }
            if (!Schema::hasColumn('organizations', 'date_format')) {
                $table->string('date_format', 50)->nullable();
            }
            if (!Schema::hasColumn('organizations', 'time_format')) {
                $table->string('time_format', 50)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Only drop columns that exist
            if (Schema::hasColumn('organizations', 'currency')) {
                $table->dropColumn('currency');
            }
            if (Schema::hasColumn('organizations', 'date_format')) {
                $table->dropColumn('date_format');
            }
            if (Schema::hasColumn('organizations', 'time_format')) {
                $table->dropColumn('time_format');
            }
        });
    }
};
