<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\Product;
use App\Services\NotificationService;

/**
 * Observer for Product model events.
 *
 * Monitors product changes and triggers notifications for
 * low stock and out of stock conditions.
 */
final class ProductObserver
{
    /**
     * Handle the Product "updated" event.
     * Check for low stock and out of stock conditions.
     */
    public function updated(Product $product): void
    {
        // Only check if stock quantity changed
        if ($product->isDirty('stock')) {
            $oldStock = $product->getOriginal('stock');
            $newStock = $product->stock;

            // Check if product went out of stock
            if ($oldStock > 0 && $newStock == 0) {
                NotificationService::createOutOfStockNotification($product);
            }
            // Check if product is now low stock (but wasn't before)
            elseif ($newStock > 0 && $newStock <= $product->min_stock && $oldStock > $product->min_stock) {
                NotificationService::createLowStockNotification($product);
            }
        }
    }
}
