<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\Product;
use App\Services\Hooks\DomainHooks;
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
     * Attributes whose change alone is not a product edit: on-hand stock is
     * reported by stock_changed, and the timestamps move with every save.
     */
    private const NOT_AN_EDIT = ['stock', 'updated_at', 'created_at', 'deleted_at'];

    public function __construct(private readonly DomainHooks $hooks) {}

    /**
     * Handle the Product "created" event.
     *
     * Fires the `product_created` action hook here, on the model lifecycle,
     * rather than in the Inertia controller, so plugins observe product
     * creation regardless of the surface that created it (web, REST, GraphQL,
     * MCP, import), once the product and its options and variants are
     * committed.
     */
    public function created(Product $product): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('product_created', $product, fn () => do_action('product_created', $product, $user));

        if ((int) $product->stock !== 0) {
            $this->hooks->totalStockChanged($product, (int) $product->id, null, 0, (int) $product->stock);
        }
    }

    /**
     * Fires `product_deleted` from every surface (web, bulk delete, REST,
     * GraphQL), after the delete commits.
     */
    public function deleted(Product $product): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('product_deleted', $product, fn () => do_action('product_deleted', $product, $user));
    }

    /**
     * Announce a product edit from any surface. Called by updated() for a
     * change to the product row, and by ProductService for an edit that only
     * touched its options, variants or suppliers. A product created in the
     * same transaction is announced by product_created alone.
     */
    public function announceUpdate(Product $product): void
    {
        if ($this->hooks->isPending('product_created', $product)) {
            return;
        }

        $user = auth()->user();

        $this->hooks->afterCommit('product_updated', $product, fn () => do_action('product_updated', $product, $user));
    }

    /**
     * Handle the Product "updated" event.
     * Check for low stock and out of stock conditions.
     */
    public function updated(Product $product): void
    {
        if (array_diff(array_keys($product->getChanges()), self::NOT_AN_EDIT) !== []) {
            $this->announceUpdate($product);
        }

        if ($product->wasChanged('stock')) {
            $this->hooks->totalStockChanged($product, (int) $product->id, null, (int) $product->getOriginal('stock'), (int) $product->stock);
        }

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
