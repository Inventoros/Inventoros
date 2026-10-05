<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last signed entitlement document the marketplace issued to each
 * organization (see App\Services\Marketplace\PluginLicenceService). Kept
 * verbatim and verified on every read, so an edited row grants nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('install_id', 128);
            $table->longText('document')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_entitlements');
    }
};
