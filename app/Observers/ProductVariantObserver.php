<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Services\Hooks\DomainHooks;
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
 *
 * It also fires the variant_created, variant_updated and variant_deleted
 * hooks and records variant stock moves for stock_changed, once per variant
 * per transaction, from every surface.
 */
final class ProductVariantObserver
{
    /**
     * Attributes whose change alone is not a variant edit (stock moves are
     * reported by stock_changed).
     */
    private const NOT_AN_EDIT = ['stock', 'updated_at', 'created_at', 'deleted_at'];

    public function __construct(private readonly DomainHooks $hooks) {}

    public function created(ProductVariant $variant): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('variant_created', $variant, fn () => do_action('variant_created', $variant, $user));

        if ((int) $variant->stock !== 0) {
            $this->hooks->totalStockChanged($variant, (int) $variant->product_id, (int) $variant->id, 0, (int) $variant->stock);
        }
    }

    public function deleted(ProductVariant $variant): void
    {
        $user = auth()->user();

        $this->hooks->afterCommit('variant_deleted', $variant, fn () => do_action('variant_deleted', $variant, $user));
    }

    public function updated(ProductVariant $variant): void
    {
        if (array_diff(array_keys($variant->getChanges()), self::NOT_AN_EDIT) !== []
            && ! $this->hooks->isPending('variant_created', $variant)) {
            $user = auth()->user();

            $this->hooks->afterCommit('variant_updated', $variant, fn () => do_action('variant_updated', $variant, $user));
        }

        if (! $variant->wasChanged('stock')) {
            return;
        }

        $this->hooks->totalStockChanged($variant, (int) $variant->product_id, (int) $variant->id, (int) $variant->getOriginal('stock'), (int) $variant->stock);

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
