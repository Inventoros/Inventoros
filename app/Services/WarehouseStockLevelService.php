<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\WarehouseReorderPoint;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A product's on-hand per warehouse and the thresholds that apply there.
 *
 * On-hand in a warehouse is the sum of the product's bins at that
 * warehouse's locations. Thresholds come from warehouse_reorder_points; a
 * blank field inherits the product-level value, and a warehouse with no row
 * is not tracked on its own (the product-level check on the total applies).
 */
final class WarehouseStockLevelService
{
    /**
     * @return array<int, int> warehouse id => on-hand
     */
    public function onHandByWarehouse(Product $product): array
    {
        return DB::table('product_location_stocks as pls')
            ->join('product_locations as pl', 'pl.id', '=', 'pls.location_id')
            ->where('pls.product_id', $product->id)
            ->whereNotNull('pl.warehouse_id')
            ->whereNull('pl.deleted_at')
            ->groupBy('pl.warehouse_id')
            ->selectRaw('pl.warehouse_id, sum(pls.quantity) as on_hand')
            ->pluck('on_hand', 'warehouse_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    public function onHandInWarehouse(int $productId, int $warehouseId): int
    {
        return (int) DB::table('product_location_stocks as pls')
            ->join('product_locations as pl', 'pl.id', '=', 'pls.location_id')
            ->where('pls.product_id', $productId)
            ->where('pl.warehouse_id', $warehouseId)
            ->whereNull('pl.deleted_at')
            ->sum('pls.quantity');
    }

    /**
     * The thresholds that apply in a warehouse: the row's values, blanks
     * filled from the product.
     *
     * @return array{min_stock: int|null, reorder_point: int|null, reorder_quantity: int|null, max_stock: int|null}
     */
    public function effectiveLevels(WarehouseReorderPoint $row, Product $product): array
    {
        $levels = [];

        foreach (WarehouseReorderPoint::LEVELS as $field) {
            $levels[$field] = $row->{$field} ?? $product->{$field};
        }

        return $levels;
    }

    /**
     * One row per warehouse for the product page: every active warehouse the
     * viewer can access (plus inactive ones still holding stock or levels),
     * with its on-hand, its own saved values, and whether it is low or due
     * for reorder under the effective thresholds.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function levelsForProduct(Product $product, ?User $viewer = null): Collection
    {
        $onHand = $this->onHandByWarehouse($product);
        $rows = WarehouseReorderPoint::query()
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('warehouse_id');

        $warehouses = Warehouse::query()
            ->where('organization_id', $product->organization_id)
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('id', array_keys($onHand))
                ->orWhereIn('id', $rows->keys()->all()))
            ->when($viewer, fn ($q) => app(WarehouseAccessService::class)->scopeWarehouses($q, $viewer))
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'priority']);

        return $warehouses->map(function (Warehouse $warehouse) use ($product, $onHand, $rows) {
            /** @var WarehouseReorderPoint|null $row */
            $row = $rows->get($warehouse->id);
            $stock = $onHand[$warehouse->id] ?? 0;
            $effective = $row ? $this->effectiveLevels($row, $product) : null;

            return [
                'warehouse_id' => $warehouse->id,
                'warehouse_name' => $warehouse->name,
                'warehouse_code' => $warehouse->code,
                'on_hand' => $stock,
                'min_stock' => $row?->min_stock,
                'reorder_point' => $row?->reorder_point,
                'reorder_quantity' => $row?->reorder_quantity,
                'max_stock' => $row?->max_stock,
                'is_low' => $effective !== null && $effective['min_stock'] !== null && $stock <= $effective['min_stock'],
                'needs_reorder' => $effective !== null && $this->isDue($stock, $effective),
            ];
        })->values();
    }

    /**
     * Warehouses where the product is at or below its effective reorder
     * point, with the quantity each one needs.
     *
     * @return array<int, array{warehouse_id: int, warehouse_name: string, on_hand: int, reorder_point: int, suggested_quantity: int}>
     */
    public function shortfalls(Product $product): array
    {
        $rows = $product->relationLoaded('warehouseReorderPoints')
            ? $product->warehouseReorderPoints
            : $product->warehouseReorderPoints()->with('warehouse:id,name,priority')->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $rows->loadMissing('warehouse:id,name,priority');
        $onHand = $this->onHandByWarehouse($product);
        $shortfalls = [];

        foreach ($rows->sortByDesc(fn ($row) => $row->warehouse?->priority ?? 0) as $row) {
            if (! $row->warehouse) {
                continue;
            }

            $stock = $onHand[$row->warehouse_id] ?? 0;
            $effective = $this->effectiveLevels($row, $product);

            if (! $this->isDue($stock, $effective)) {
                continue;
            }

            $shortfalls[] = [
                'warehouse_id' => $row->warehouse_id,
                'warehouse_name' => $row->warehouse->name,
                'on_hand' => $stock,
                'reorder_point' => (int) $effective['reorder_point'],
                'suggested_quantity' => $this->quantityFor($stock, $effective),
            ];
        }

        return $shortfalls;
    }

    /**
     * @param  array{min_stock: int|null, reorder_point: int|null, reorder_quantity: int|null, max_stock: int|null}  $levels
     */
    private function isDue(int $onHand, array $levels): bool
    {
        return $levels['reorder_point'] !== null
            && ($levels['reorder_quantity'] === null || $levels['reorder_quantity'] > 0)
            && $onHand <= $levels['reorder_point'];
    }

    /**
     * The warehouse's reorder quantity, else the gap up to its max (falling
     * back to the reorder point, then the minimum). At least one unit.
     *
     * @param  array{min_stock: int|null, reorder_point: int|null, reorder_quantity: int|null, max_stock: int|null}  $levels
     */
    private function quantityFor(int $onHand, array $levels): int
    {
        if ($levels['reorder_quantity'] !== null && $levels['reorder_quantity'] > 0) {
            return (int) $levels['reorder_quantity'];
        }

        $target = $levels['max_stock'] ?? $levels['reorder_point'] ?? $levels['min_stock'] ?? 0;

        return max(1, (int) $target - $onHand);
    }
}
