<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an installed plugin came from. The marketplace only updates plugins
 * it installed, so it can never overwrite a local plugin that happens to
 * share a marketplace slug. NULL = uploaded or copied in by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
