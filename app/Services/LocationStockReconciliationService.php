<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocationStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Surfaces (and optionally corrects) drift between a product's recorded stock
 * and the sum of its per-location bins.
 *
 * products.stock is the authoritative total; product_location_stocks breaks it
 * down per location, and the invariant is SUM(bins) <= stock (any shortfall is
 * unassigned stock not yet binned). Every write path is wired to keep the two
 * in step, but this is the detection net for historical drift or a future
 * regression — the per-location analogue of the tracked-stock reconciliation.
 *
 * A positive difference (stock > SUM(bins)) is stock not yet assigned to a
 * location; a negative difference (SUM(bins) > stock) is a bin claiming more
 * than exists, which should never happen. reconcile() brings SUM(bins) back to
 * stock by binning any shortfall into the primary location and draining any
 * over-claim, without touching products.stock.
 */
final class LocationStockReconciliationService
{
    /**
     * Products that have at least one location bin whose bins do not sum to the
     * recorded stock.
     *
     * @return Collection<int, array{
     *     product: Product,
     *     recorded_stock: int,
     *     binned_total: int,
     *     difference: int
     * }>
     */
    public function discrepancies(int $organizationId): Collection
    {
        return Product::query()
            ->where('organization_id', $organizationId)
            ->whereHas('locationStocks')
            ->withSum('locationStocks as binned_total', 'quantity')
            ->get()
            ->map(function (Product $product): array {
                $binned = (int) ($product->binned_total ?? 0);
                $recorded = (int) $product->stock;

                return [
                    'product' => $product,
                    'recorded_stock' => $recorded,
                    'binned_total' => $binned,
                    'difference' => $recorded - $binned,
                ];
            })
            ->filter(fn (array $row): bool => $row['difference'] !== 0)
            ->values();
    }

    /**
     * Bring SUM(bins) back in line with products.stock for every drifted
     * product: bin any shortfall into the primary location (receive), drain any
     * over-claim (consume). products.stock is unchanged — the bins are the
     * derived breakdown being corrected to it. Each product is row-locked and
     * its bins re-summed under the lock, so a concurrent movement can't be
     * clobbered.
     *
     * @return int the number of products corrected
     */
    public function reconcile(int $organizationId): int
    {
        $fixed = 0;
        $bins = app(ProductLocationStockService::class);

        foreach ($this->discrepancies($organizationId) as $row) {
            DB::transaction(function () use ($row, $bins, &$fixed): void {
                $product = Product::query()->whereKey($row['product']->id)->lockForUpdate()->first();
                if ($product === null) {
                    return;
                }

                $binned = (int) ProductLocationStock::query()
                    ->where('product_id', $product->id)->sum('quantity');
                $difference = (int) $product->stock - $binned;

                if ($difference === 0) {
                    return;
                }

                if ($difference > 0) {
                    $bins->receive($product, $difference);
                } else {
                    $bins->consume($product, -$difference);
                }

                $fixed++;
            });
        }

        return $fixed;
    }
}
