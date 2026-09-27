<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns that were too narrow for the values the app writes. SQLite ignores
 * VARCHAR lengths, so these only failed on MySQL and PostgreSQL:
 *
 * - webhooks.secret was VARCHAR(64) but holds the `encrypted` cast's
 *   ciphertext (~250 bytes), so creating any webhook failed.
 * - warehouses.country was VARCHAR(2) while the form defaults to "Canada"
 *   and validation allows 255 characters; province and postal_code were
 *   likewise narrower than their validation rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('secret')->change();
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('province', 255)->nullable()->change();
            $table->string('postal_code', 255)->nullable()->change();
            $table->string('country', 255)->nullable()->default('CA')->change();
        });
    }

    public function down(): void
    {
        // Narrowing again would truncate or reject existing data; the wider
        // columns are compatible with the previous code.
    }
};
