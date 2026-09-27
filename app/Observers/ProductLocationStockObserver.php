<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\WarehouseReorderPoint;
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
    public function __construct(private readonly WarehouseStockLevelService $levels) {}

    public function created(ProductLocationStock $bin): void
    {
        $this->check($bin, 0);
    }

    public function updated(ProductLocationStock $bin): void
    {
        if (! $bin->wasChanged('quantity')) {
            return;
        }

        $this->check($bin, (int) $bin->getOriginal('quantity'));
    }

    private function check(ProductLocationStock $bin, int $before): void
    {
        $delta = (int) $bin->quantity - $before;

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
            ->where('product_id', $bin->product_id)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if (! $row || ! $row->product || ! $row->warehouse) {
            return;
        }

        $minimum = $this->levels->effectiveLevels($row, $row->product)['min_stock'];

        if ($minimum === null) {
            return;
        }

        $after = $this->levels->onHandInWarehouse($bin->product_id, (int) $warehouseId);
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
}
