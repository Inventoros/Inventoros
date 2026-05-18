<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite indexes that hot list/sort/aggregate queries need to
     * avoid full table scans on large tenants.
     *
     *   products       (organization_id, is_active, created_at)  — index list pages that filter active + sort latest
     *   products       (organization_id, category_id)            — category-filtered product browsing
     *   products       (organization_id, location_id)            — location-filtered product browsing
     *   orders         (organization_id, source, order_date)     — source-filtered order lists with date sort
     *   order_items    (product_id, order_id)                    — sales-report aggregations joining order_items to orders
     *
     * stock_adjustments already carries (organization_id, created_at).
     * The partial index on a low-stock flag (Postgres-only) is a separate
     * decision because it requires a generated column.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['organization_id', 'is_active', 'created_at'], 'products_org_active_created_index');
            $table->index(['organization_id', 'category_id'], 'products_org_category_index');
            $table->index(['organization_id', 'location_id'], 'products_org_location_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['organization_id', 'source', 'order_date'], 'orders_org_source_date_index');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['product_id', 'order_id'], 'order_items_product_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_product_order_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_org_source_date_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_org_location_index');
            $table->dropIndex('products_org_category_index');
            $table->dropIndex('products_org_active_created_index');
        });
    }
};
