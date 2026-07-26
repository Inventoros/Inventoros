<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-location bin can never legitimately hold a negative quantity — every
 * write path guards against over-drawing. Make the column unsigned so the
 * database rejects a negative bin (a symptom of a stock bug) rather than
 * silently storing it. (No-op on engines without unsigned integers; a real
 * backstop on MySQL.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_location_stocks', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_location_stocks', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });
    }
};
