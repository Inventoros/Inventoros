<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\Product;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Observer for Product model events.
 *
 * Monitors product changes and triggers notifications for
 * low stock and out of stock conditions.
 */
final class ProductObserver
{
    /**
     * Handle the Product "created" event.
     *
     * Fires the `product_created` action hook here, on the model lifecycle,
     * rather than in the Inertia controller — so plugins observe product
     * creation regardless of the surface that created it (web, REST, GraphQL,
     * or MCP), not just the web path.
     */
    public function created(Product $product): void
    {
        do_action('product_created', $product, auth()->user());
    }

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

            // Defer the notification + webhook fan-out until the surrounding
            // stock transaction commits: shorter lock hold, and nothing fires
            // if the change rolls back. afterCommit runs immediately when no
            // transaction is open.
            if ($oldStock > 0 && $newStock == 0) {
                // Product went out of stock.
                DB::afterCommit(function () use ($product) {
                    NotificationService::createOutOfStockNotification($product);
                    do_action('out_of_stock_alert', $product);
                });
            } elseif ($newStock > 0 && $newStock <= $product->min_stock && $oldStock > $product->min_stock) {
                // Product crossed into low stock (but wasn't before).
                DB::afterCommit(function () use ($product) {
                    NotificationService::createLowStockNotification($product);
                    do_action('low_stock_alert', $product);
                });
            }
        }
    }
}
