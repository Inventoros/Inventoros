<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAdjustment;

/**
 * Stock invariants every stock-moving scenario must leave intact.
 *
 *  - A binned product (one with any per-location rows) holds exactly its
 *    on-hand total across its bins: products.stock == SUM(bins).
 *  - The ledger explains the total: the product's starting stock plus the sum
 *    of its product-level ledger rows equals products.stock.
 */
trait AssertsStockInvariants
{
    /**
     * Assert stock == SUM(bins) for every binned product in the database.
     */
    protected function assertBinnedStockBalanced(): void
    {
        $binTotals = ProductLocationStock::withoutGlobalScopes()
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        foreach ($binTotals as $productId => $total) {
            $product = Product::withoutGlobalScopes()->find($productId);

            $this->assertNotNull($product, "Bins exist for missing product {$productId}.");
            $this->assertSame(
                (int) $product->stock,
                (int) $total,
                "Product {$product->sku}: stock {$product->stock} != SUM(bins) {$total}.",
            );
        }
    }

    /**
     * Assert the product-level ledger (rows without a variant) moves the
     * product from $startingStock to its current stock.
     */
    protected function assertLedgerExplainsStock(Product $product, int $startingStock): void
    {
        $net = (int) StockAdjustment::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->sum('adjustment_quantity');

        $this->assertSame(
            (int) $product->fresh()->stock,
            $startingStock + $net,
            "Product {$product->sku}: ledger net {$net} from {$startingStock} does not explain stock {$product->fresh()->stock}.",
        );
    }

    /**
     * Count the product's ledger rows of one type.
     */
    protected function ledgerRows(Product $product, string $type): int
    {
        return StockAdjustment::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('type', $type)
            ->count();
    }
}
