<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->default('CA');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('timezone', 50)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'code']);
            $table->index(['organization_id', 'is_default']);
        });

        // Pivot: which users can access which warehouses
        Schema::create('warehouse_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['warehouse_id', 'user_id']);
        });

        // Add warehouse_id to product_locations
        Schema::table('product_locations', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->index(['warehouse_id', 'is_active']);
        });

        // Add warehouse_id to orders (default fulfillment warehouse)
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });

        // Add inter-warehouse shipping fields to stock_transfers
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('from_warehouse_id')->nullable()->after('organization_id')->constrained('warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->after('from_warehouse_id')->constrained('warehouses')->nullOnDelete();
            $table->boolean('is_inter_warehouse')->default(false)->after('status');
            $table->string('shipping_method')->nullable()->after('is_inter_warehouse');
            $table->string('tracking_number')->nullable()->after('shipping_method');
            $table->timestamp('shipped_at')->nullable()->after('tracking_number');
            $table->timestamp('estimated_arrival')->nullable()->after('shipped_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_warehouse_id');
            $table->dropConstrainedForeignId('to_warehouse_id');
            $table->dropColumn(['is_inter_warehouse', 'shipping_method', 'tracking_number', 'shipped_at', 'estimated_arrival']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::table('product_locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::dropIfExists('warehouse_user');
        Schema::dropIfExists('warehouses');
    }
};
