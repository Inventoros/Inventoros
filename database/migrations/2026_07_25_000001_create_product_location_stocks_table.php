<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location on-hand quantity for products.
 *
 * products.stock remains the authoritative TOTAL on hand; this table breaks
 * that total down by location. The invariant is: for any product that has
 * rows here, products.stock == SUM(quantity). Stock that is not yet assigned
 * to a location (product.location_id is null) simply has no rows here until
 * it is binned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_location_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('product_locations')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();

            // One row per product per location.
            $table->unique(['product_id', 'location_id']);
            $table->index(['organization_id', 'product_id']);
            $table->index(['organization_id', 'location_id']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_location_stocks');
    }

    /**
     * Seed each product's current on-hand at the location it is assigned to.
     * Raw + chunked so replaying this migration never depends on model state,
     * and never loads the whole catalogue into memory. The table is empty at
     * this point, so a plain insert is safe (no idempotency needed here — the
     * repair command handles re-runs).
     */
    private function backfill(): void
    {
        DB::table('products')
            ->whereNotNull('location_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->select('id', 'organization_id', 'location_id', 'stock')
            ->chunk(500, function ($products): void {
                $now = now();
                $rows = [];

                foreach ($products as $product) {
                    $rows[] = [
                        'organization_id' => $product->organization_id,
                        'product_id' => $product->id,
                        'location_id' => $product->location_id,
                        'quantity' => $product->stock,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('product_location_stocks')->insert($rows);
                }
            });
    }
};
