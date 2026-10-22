<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock reservations (available to promise). A row holds units that are on
 * hand but promised to an owner (a plugin slug, or "core") under the owner's
 * own reference. available = on hand - SUM(quantity of active holds), computed
 * when needed and never stored. See App\Services\StockReservationService and
 * docs/plans/2026-10-05-stock-reservations-design.md.
 *
 * A hold is against the product total (location_id null), one location bin
 * (location_id set), one variant (product_variant_id set), one serial
 * (product_serial_id set, quantity 1) or part of one batch (product_batch_id
 * set). An active hold has neither consumed_at nor released_at set, and an
 * expires_at that is null or in the future.
 *
 * scope_key spells out the product, variant, location, serial and batch as one
 * non-null string ("p12:v0:l0:s0:b0"), so the unique index that makes a
 * retried reserve() idempotent works on every database: MySQL, PostgreSQL and
 * SQLite all treat NULLs in a unique index as distinct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('product_locations')->nullOnDelete();
            $table->foreignId('product_serial_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('owner_type', 100);
            $table->string('owner_reference', 191);
            $table->string('scope_key', 100);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->nullableMorphs('consumed_by');
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 30)->nullable();
            $table->timestamps();

            // The sum behind available = on hand - reserved.
            $table->index(['organization_id', 'product_id', 'product_variant_id'], 'stock_reservations_product_index');
            // Named: the generated names pass MySQL's 64-character limit.
            $table->unique(['organization_id', 'owner_type', 'owner_reference', 'scope_key'], 'stock_reservations_owner_scope_unique');
            $table->index(['owner_type', 'owner_reference'], 'stock_reservations_owner_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
