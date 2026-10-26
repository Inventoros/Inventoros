<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\VariantLocationStock;
use App\Models\Inventory\WarehouseReorderPoint;
use App\Services\Hooks\DomainHooks;
use App\Services\NotificationService;
use App\Services\WarehouseStockLevelService;
use Illuminate\Support\Facades\DB;

/**
 * Raises a low-stock alert when a bin change takes a product's on-hand in a
 * warehouse across that warehouse's own minimum. Only warehouses with
 * per-warehouse thresholds are checked; the product-level alert on the total
 * is handled by ProductObserver.
 */
class ProductLocationStockObserver
{
    public function __construct(
        private readonly WarehouseStockLevelService $levels,
        private readonly DomainHooks $hooks,
    ) {}

    public function created(ProductLocationStock|VariantLocationStock $bin): void
    {
        $this->recordForStockChanged($bin, 0);
        $this->check($bin, 0);
    }

    public function updated(ProductLocationStock|VariantLocationStock $bin): void
    {
        if (! $bin->wasChanged('quantity')) {
            return;
        }

        $this->recordForStockChanged($bin, (int) $bin->getOriginal('quantity'));
        $this->check($bin, (int) $bin->getOriginal('quantity'));
    }

    public function deleted(ProductLocationStock|VariantLocationStock $bin): void
    {
        $this->hooks->locationStockChanged($bin, $this->productId($bin), (int) $bin->location_id, (int) $bin->getOriginal('quantity'), 0, $this->variantId($bin));
    }

    /**
     * Bin moves are reported by the product's stock_changed hook (in
     * `locations`), so a move between two bins, which leaves the total
     * unchanged, is still announced.
     */
    private function recordForStockChanged(ProductLocationStock|VariantLocationStock $bin, int $before): void
    {
        if ($before !== (int) $bin->quantity) {
            $this->hooks->locationStockChanged($bin, $this->productId($bin), (int) $bin->location_id, $before, (int) $bin->quantity, $this->variantId($bin));
        }
    }

    private function check(ProductLocationStock|VariantLocationStock $bin, int $before): void
    {
        $delta = (int) $bin->quantity - $before;

        if ($bin instanceof VariantLocationStock && (! $bin->variant?->is_active || $bin->variant->trashed())) {
            return;
        }

        // Only a falling bin can cross into low stock.
        if ($delta >= 0) {
            return;
        }

        $warehouseId = ProductLocation::withoutGlobalScopes()->whereKey($bin->location_id)->value('warehouse_id');

        if ($warehouseId === null) {
            return;
        }

        $row = WarehouseReorderPoint::withoutGlobalScopes()
            ->with(['product' => fn ($q) => $q->withoutGlobalScopes(), 'warehouse' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('product_id', $this->productId($bin))
            ->where('warehouse_id', $warehouseId)
            ->first();

        if (! $row || ! $row->product || ! $row->warehouse) {
            return;
        }

        $minimum = $this->levels->effectiveLevels($row, $row->product)['min_stock'];

        if ($minimum === null) {
            return;
        }

        $after = $this->levels->onHandInWarehouse($this->productId($bin), (int) $warehouseId);
        $previous = $after - $delta;

        if ($previous > $minimum && $after <= $minimum) {
            $product = $row->product;
            $warehouse = $row->warehouse;

            DB::afterCommit(function () use ($product, $warehouse, $after, $minimum) {
                NotificationService::createWarehouseLowStockNotification($product, $warehouse, $after, (int) $minimum);
                do_action('warehouse_low_stock_alert', $product, $warehouse, $after);
            });
        }
    }

    private function productId(ProductLocationStock|VariantLocationStock $bin): int
    {
        if (! $bin instanceof VariantLocationStock) {
            return (int) $bin->product_id;
        }
        if (! $bin->relationLoaded('variant')) {
            $bin->setRelation('variant', ProductVariant::withTrashed()->where('organization_id', $bin->organization_id)
                ->find($bin->product_variant_id));
        }

        return (int) $bin->variant?->product_id;
    }

    private function variantId(ProductLocationStock|VariantLocationStock $bin): ?int
    {
        return $bin instanceof VariantLocationStock ? (int) $bin->product_variant_id : null;
    }
}
