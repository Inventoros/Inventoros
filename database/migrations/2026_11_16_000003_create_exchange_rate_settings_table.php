<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization exchange rate configuration: the selected provider, an
 * optional explicit fallback provider, provider settings (API keys among
 * them, encrypted at rest by the model's cast, hence a text column), the
 * currencies to keep rates for, automatic synchronization and its last
 * outcome, which the settings page shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rate_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 64)->default('frankfurter');
            $table->string('fallback_provider', 64)->nullable();
            $table->text('provider_config')->nullable();
            $table->json('currencies')->nullable();
            $table->unsignedSmallInteger('stale_after_days')->default(4);
            $table->boolean('sync_enabled')->default(false);
            $table->string('sync_frequency', 32)->default('daily');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_sync_attempt_at')->nullable();
            $table->string('last_sync_status', 16)->nullable();
            $table->text('last_sync_error')->nullable();
            $table->unsignedSmallInteger('sync_failures')->default(0);
            $table->timestamp('next_sync_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_settings');
    }
};
