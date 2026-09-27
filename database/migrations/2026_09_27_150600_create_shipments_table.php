<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shipment is one parcel leaving the warehouse for a sales order. An order
 * can have several (partial shipments); shipment_items records which order
 * lines, and how many units of each, are in the box.
 *
 * carrier is the integration that owns the shipment (manual or easypost);
 * carrier_name/service are the human-facing carrier and service level
 * (for example USPS Priority). The raw carrier purchase response is kept in
 * carrier_response so a bought label is never lost, even if the label file
 * download that follows it fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('carrier', 32)->default('manual');
            $table->string('carrier_name')->nullable();
            $table->string('service')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 2048)->nullable();

            $table->string('label_path')->nullable();
            $table->string('label_url', 2048)->nullable();

            $table->string('carrier_shipment_id')->nullable();
            $table->string('carrier_tracker_id')->nullable();
            $table->json('carrier_rates')->nullable();
            $table->json('carrier_response')->nullable();

            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->decimal('weight_oz', 10, 2)->nullable();
            $table->decimal('length_in', 8, 2)->nullable();
            $table->decimal('width_in', 8, 2)->nullable();
            $table->decimal('height_in', 8, 2)->nullable();

            $table->string('status', 32)->default('pending');
            $table->string('tracking_status_detail')->nullable();
            $table->boolean('notify_customer')->default(false);
            $table->timestamp('customer_notified_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_tracked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'order_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['carrier', 'status', 'last_tracked_at']);
            $table->index('carrier_tracker_id');
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
