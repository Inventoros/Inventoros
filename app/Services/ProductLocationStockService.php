<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocationStock;
use Illuminate\Support\Collection;

/**
 * Reads the per-location on-hand breakdown behind products.stock, and can
 * (re)seed it from the products' assigned locations.
 *
 * This slice is read-only with respect to live stock: it never changes
 * products.stock. Location-aware writes (adjustments, transfers) land in
 * later slices.
 */
final class ProductLocationStockService
{
    /**
     * On-hand quantity of a product at a single location (0 if unbinned there).
     */
    public function quantityAt(Product $product, int $locationId): int
    {
        return (int) (ProductLocationStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->value('quantity') ?? 0);
    }

    /**
     * The product's on-hand quantity per location, richest first, with the
     * location eager-loaded for display.
     *
     * @return Collection<int, ProductLocationStock>
     */
    public function breakdown(Product $product): Collection
    {
        return ProductLocationStock::query()
            ->with('location')
            ->where('product_id', $product->id)
            ->orderByDesc('quantity')
            ->get();
    }

    /**
     * Total quantity assigned to locations for a product. Equals
     * $product->stock once every unit has been binned; less if some stock is
     * still unassigned.
     */
    public function totalAssigned(Product $product): int
    {
        return (int) ProductLocationStock::query()
            ->where('product_id', $product->id)
            ->sum('quantity');
    }

    /**
     * Move quantity of a product from one location bin to another. The total
     * (products.stock) is unchanged — only the per-location breakdown shifts.
     *
     * The source bin must hold at least $quantity. A product that has never
     * been binned is lazily seeded at its assigned location first (from its
     * current stock), so a transfer of a single-location product behaves
     * exactly like the pre-per-location global check.
     *
     * Must run inside a transaction that already holds a row lock on the
     * product, so concurrent transfers of the same product serialize.
     *
     * @throws \RuntimeException when the source bin is short
     */
    public function move(Product $product, int $fromLocationId, int $toLocationId, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $this->ensureBinned($product);

        $fromRow = ProductLocationStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $fromLocationId)
            ->first();

        $available = (int) ($fromRow->quantity ?? 0);

        if ($available < $quantity) {
            throw new \RuntimeException(
                "Insufficient stock at the source location for {$product->name}: have {$available}, transfer requires {$quantity}."
            );
        }

        $fromRow->decrement('quantity', $quantity);

        $toRow = ProductLocationStock::firstOrCreate(
            ['product_id' => $product->id, 'location_id' => $toLocationId],
            ['organization_id' => $product->organization_id, 'quantity' => 0],
        );
        $toRow->increment('quantity', $quantity);
    }

    /**
     * Deplete a product's bins to satisfy a decrement (a sale, a component
     * consumed), draining the product's primary location first and then the
     * fullest bins. Keeps SUM(bins) in step with a falling products.stock so a
     * bin never claims more than exists.
     *
     * Best-effort: an unbinned product is lazily seeded from its assigned
     * location first; a product with no location is left alone (its stock has
     * no bins to move). Any shortfall beyond what the bins hold simply came
     * from unassigned stock. Call BEFORE decrementing products.stock so the
     * lazy seed reads the pre-decrement total.
     *
     * Must run inside the caller's product-locked transaction.
     */
    public function consume(Product $product, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $this->ensureBinned($product);

        $bins = ProductLocationStock::query()
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            // Primary location first, then the fullest bins.
            ->orderByRaw('location_id = ? desc', [$product->location_id ?? 0])
            ->orderByDesc('quantity')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;

        foreach ($bins as $bin) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $bin->quantity);
            $bin->decrement('quantity', $take);
            $remaining -= $take;
        }
    }

    /**
     * Add received or returned units to a location bin (the product's primary
     * location by default), lazily creating it. Keeps SUM(bins) in step with a
     * rising products.stock. No-op when there is no location to receive into.
     *
     * Must run inside the caller's product-locked transaction.
     */
    public function receive(Product $product, int $quantity, ?int $locationId = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        $locationId ??= $product->location_id;

        if ($locationId === null) {
            return;
        }

        ProductLocationStock::firstOrCreate(
            ['product_id' => $product->id, 'location_id' => $locationId],
            ['organization_id' => $product->organization_id, 'quantity' => 0],
        )->increment('quantity', $quantity);
    }

    /**
     * Apply a signed delta to a product's quantity at one location bin,
     * keeping the per-location breakdown in step with a stock adjustment on
     * the same product. Lazily bins the product first, so the delta lands on
     * top of its real on-hand rather than an empty bin.
     *
     * Must run inside the adjustment's transaction (which holds the product
     * row lock). When $allowNegativeBin is false, refuses to drive the bin
     * below zero.
     *
     * @throws InsufficientStockException when the bin would go negative
     */
    public function applyDelta(Product $product, int $locationId, int $delta, bool $allowNegativeBin = true): int
    {
        $this->ensureBinned($product);

        $row = ProductLocationStock::firstOrCreate(
            ['product_id' => $product->id, 'location_id' => $locationId],
            ['organization_id' => $product->organization_id, 'quantity' => 0],
        );

        $after = (int) $row->quantity + $delta;

        if (! $allowNegativeBin && $after < 0) {
            throw new InsufficientStockException(
                'Cannot remove '.abs($delta)." units from that location for {$product->name}: only {$row->quantity} on hand there."
            );
        }

        $row->update(['quantity' => $after]);

        return $after;
    }

    /**
     * Seed a product's full current stock at its assigned location if it has
     * no per-location rows yet. No-op once the product is binned, or if it has
     * no assigned location to seed from.
     */
    private function ensureBinned(Product $product): void
    {
        if ($product->location_id === null) {
            return;
        }

        $alreadyBinned = ProductLocationStock::query()
            ->where('product_id', $product->id)
            ->exists();

        if ($alreadyBinned) {
            return;
        }

        ProductLocationStock::create([
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'location_id' => $product->location_id,
            'quantity' => (int) $product->stock,
        ]);
    }

    /**
     * Seed a per-location row for every product that has an assigned location
     * but no row there yet, using the product's current stock. Idempotent:
     * products already binned at their location are left untouched, so this is
     * safe to re-run to repair drift after the initial migration backfill.
     *
     * @return int the number of rows created
     */
    public function backfill(?int $organizationId = null): int
    {
        $created = 0;

        Product::query()
            ->whereNotNull('location_id')
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('id')
            ->chunkById(500, function (Collection $products) use (&$created): void {
                foreach ($products as $product) {
                    $alreadyBinned = ProductLocationStock::query()
                        ->where('product_id', $product->id)
                        ->where('location_id', $product->location_id)
                        ->exists();

                    if ($alreadyBinned) {
                        continue;
                    }

                    ProductLocationStock::create([
                        'organization_id' => $product->organization_id,
                        'product_id' => $product->id,
                        'location_id' => $product->location_id,
                        'quantity' => (int) $product->stock,
                    ]);

                    $created++;
                }
            });

        return $created;
    }
}
