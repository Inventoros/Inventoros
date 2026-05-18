<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the global UNIQUE constraints on identifier columns that are
     * conceptually per-tenant and replace them with composite uniques on
     * (organization_id, <column>). Two tenants on the same instance can
     * legitimately use the same SKU, PO number, return number, transfer
     * number, or work order number without colliding.
     *
     * Mirrors the earlier 2026_03_12_000002 fix for orders.order_number.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->unique(['organization_id', 'sku']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['po_number']);
            $table->unique(['organization_id', 'po_number']);
        });

        Schema::table('return_orders', function (Blueprint $table) {
            $table->dropUnique(['return_number']);
            $table->unique(['organization_id', 'return_number']);
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropUnique(['transfer_number']);
            $table->unique(['organization_id', 'transfer_number']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropUnique(['work_order_number']);
            $table->unique(['organization_id', 'work_order_number']);
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'work_order_number']);
            $table->unique(['work_order_number']);
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'transfer_number']);
            $table->unique(['transfer_number']);
        });

        Schema::table('return_orders', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'return_number']);
            $table->unique(['return_number']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'po_number']);
            $table->unique(['po_number']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'sku']);
            $table->unique(['sku']);
        });
    }
};
