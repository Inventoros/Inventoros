<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Low-stock and out-of-stock alerts for products sold by variant.
 *
 * A variant's stock moves without products.stock, so ProductObserver never
 * sees it. A variant with its own minimum (min_stock above 0) alerts on its
 * own crossing; otherwise the product's on-hand total (the sum of its active
 * variants, as the dashboard counts it) is checked against the product's
 * minimum. Both fire only when the threshold is crossed, after the stock
 * transaction commits.
 */
final class ProductVariantObserver
{
    public function updated(ProductVariant $variant): void
    {
        if (! $variant->wasChanged('stock')) {
            return;
        }

        $old = (int) $variant->getOriginal('stock');
        $new = (int) $variant->stock;

        if ((int) $variant->min_stock > 0) {
            $this->checkVariant($variant, $old, $new);

            return;
        }

        if ($variant->is_active) {
            $this->checkProduct($variant, $new - $old);
        }
    }

    private function checkVariant(ProductVariant $variant, int $old, int $new): void
    {
        $minimum = (int) $variant->min_stock;

        if ($old > 0 && $new <= 0) {
            DB::afterCommit(fn () => NotificationService::createVariantStockNotification($variant, 'out_of_stock'));
        } elseif ($new > 0 && $new <= $minimum && $old > $minimum) {
            DB::afterCommit(function () use ($variant) {
                NotificationService::createVariantStockNotification($variant, 'low_stock');
                do_action('variant_low_stock_alert', $variant);
            });
        }
    }

    private function checkProduct(ProductVariant $variant, int $delta): void
    {
        $product = Product::withoutGlobalScopes()->find($variant->product_id);
        if ($product === null || ! $product->has_variants) {
            return;
        }

        $after = $product->total_stock;
        $before = $after - $delta;
        $minimum = $product->min_stock;

        if ($before > 0 && $after <= 0) {
            DB::afterCommit(function () use ($product) {
                NotificationService::createOutOfStockNotification($product);
                do_action('out_of_stock_alert', $product);
            });
        } elseif ($minimum !== null && $after > 0 && $after <= $minimum && $before > $minimum) {
            DB::afterCommit(function () use ($product) {
                NotificationService::createLowStockNotification($product);
                do_action('low_stock_alert', $product);
            });
        }
    }
}
