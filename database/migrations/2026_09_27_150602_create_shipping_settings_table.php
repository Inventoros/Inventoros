<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization shipping configuration: the EasyPost credentials (API key
 * and webhook secret, both encrypted at rest by the model's `encrypted` cast,
 * hence text columns), the test-mode toggle, the default ship-from address and
 * parcel, and the unguessable token that routes EasyPost's tracking webhooks
 * to this organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('easypost_enabled')->default(false);
            $table->text('easypost_api_key')->nullable();
            $table->boolean('easypost_test_mode')->default(true);
            $table->text('easypost_webhook_secret')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->json('from_address')->nullable();
            $table->json('default_parcel')->nullable();
            $table->boolean('notify_customers')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_settings');
    }
};
