<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use App\Services\BackorderService;

/**
 * Reconciles backorders when stock rises.
 *
 * Both PurchaseOrderItem::receive() and manual stock adjustments book stock
 * through StockAdjustment::adjust()/adjustVariant(), which fire the
 * `stock_adjusted` hook after commit. Listening there covers every
 * stock-increase path (receiving, manual corrections, cancellation restocks)
 * from one place.
 */
final class BackorderReconcileSubscriber
{
    public static function subscribe(): void
    {
        add_action('stock_adjusted', [self::class, 'onStockAdjusted'], 50);
    }

    public static function onStockAdjusted(StockAdjustment $adjustment, ?Product $product = null): void
    {
        // Only a stock increase can fulfil a backorder. Reconciliation's own
        // fulfilment writes a negative adjustment, so this guard also stops it
        // re-entering itself.
        if ((int) $adjustment->adjustment_quantity <= 0) {
            return;
        }

        $organizationId = (int) $adjustment->organization_id;
        $productId = (int) $adjustment->product_id;
        if ($organizationId <= 0 || $productId <= 0) {
            return;
        }

        $variantId = $adjustment->product_variant_id !== null ? (int) $adjustment->product_variant_id : null;

        app(BackorderService::class)->reconcileForProduct($organizationId, $productId, $variantId);
    }
}
