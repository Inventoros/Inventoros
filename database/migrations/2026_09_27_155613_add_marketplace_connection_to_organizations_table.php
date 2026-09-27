<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's inventoros.com marketplace connection: the API token
 * (stored encrypted via the model's `encrypted` cast) and the account it
 * belongs to, shown on Plugins > Marketplace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('marketplace_token')->nullable();
            $table->json('marketplace_account')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['marketplace_token', 'marketplace_account']);
        });
    }
};
