<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Reorder quantities and draft purchase orders built from a product's primary
 * supplier.
 *
 * Shared by the scheduled `inventory:check-reorder-points` command and the
 * "Create PO" quick action on the dashboard reorder suggestions and the
 * low-stock report, so both suggest the same quantity at the same cost.
 */
final class ReorderService
{
    /**
     * Eager-load constraint that loads only a product's primary supplier link.
     *
     * @return array<string, \Closure(BelongsToMany<Supplier, Product>): void>
     */
    public static function primarySupplierEagerLoad(): array
    {
        return ['suppliers' => function ($query): void {
            $query->wherePivot('is_primary', true);
        }];
    }

    /**
     * The product's primary supplier (with its pivot), when one is linked.
     * Uses the loaded relation when present.
     */
    public function primarySupplier(Product $product): ?Supplier
    {
        $suppliers = $product->relationLoaded('suppliers')
            ? $product->suppliers
            : $product->suppliers()->wherePivot('is_primary', true)->get();

        return $suppliers->first(fn (Supplier $supplier) => (bool) $supplier->pivot?->is_primary);
    }

    /**
     * How many units to order.
     *
     * The product's configured reorder quantity when it has one; otherwise the
     * gap up to max_stock (falling back to reorder_point, then min_stock). At
     * least one unit, and never below the primary supplier's minimum order.
     */
    public function suggestedQuantity(Product $product, ?Supplier $primary = null): int
    {
        if ($product->reorder_quantity !== null && $product->reorder_quantity > 0) {
            $quantity = (int) $product->reorder_quantity;
        } else {
            $target = $product->max_stock ?? $product->reorder_point ?? $product->min_stock ?? 0;
            $quantity = (int) $target - (int) $product->stock;
        }

        $quantity = max(1, $quantity);

        $minimum = $primary?->pivot?->minimum_order_quantity;
        if ($minimum !== null && $minimum > $quantity) {
            $quantity = (int) $minimum;
        }

        return $quantity;
    }

    /**
     * Unit cost for a line: the supplier's link cost, else the product's
     * purchase price, else its price.
     */
    public function unitCost(Product $product, ?Supplier $primary = null): string
    {
        return Money::of($primary?->pivot?->cost_price ?? $product->purchase_price ?? $product->price ?? 0);
    }

    /**
     * Group products by primary supplier.
     *
     * @param  iterable<Product>  $products  with suppliers loaded via primarySupplierEagerLoad()
     * @return array{groups: array<int, array{supplier: Supplier, products: array<int, Product>}>, withoutSupplier: array<int, Product>}
     */
    public function groupByPrimarySupplier(iterable $products): array
    {
        $groups = [];
        $withoutSupplier = [];

        foreach ($products as $product) {
            $primary = $this->primarySupplier($product);

            if (! $primary) {
                $withoutSupplier[] = $product;

                continue;
            }

            $groups[$primary->id] ??= ['supplier' => $primary, 'products' => []];
            $groups[$primary->id]['products'][] = $product;
        }

        return ['groups' => array_values($groups), 'withoutSupplier' => $withoutSupplier];
    }

    /**
     * Create one DRAFT purchase order to a supplier for the given products,
     * each line at the suggested quantity and the supplier's cost, and log it.
     *
     * @param  array<int, Product>  $products  with their primary supplier loaded
     * @param  string  $activityAction  e.g. 'auto_reorder' or 'quick_reorder'
     * @param  string  $activityDescription  prefix; the PO number and product names are appended
     */
    public function createDraftPurchaseOrder(
        int $organizationId,
        int $supplierId,
        array $products,
        ?int $userId,
        string $notes,
        string $activityAction,
        string $activityDescription,
    ): PurchaseOrder {
        return PurchaseOrder::createWithNumber($organizationId, function (string $poNumber) use ($organizationId, $supplierId, $products, $userId, $notes, $activityAction, $activityDescription) {
            $po = PurchaseOrder::create([
                'organization_id' => $organizationId,
                'supplier_id' => $supplierId,
                'created_by' => $userId,
                'po_number' => $poNumber,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => now(),
                'subtotal' => 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => 0,
                'notes' => $notes,
            ]);

            $subtotal = Money::of(0);

            foreach ($products as $product) {
                $primary = $this->primarySupplier($product);
                $quantity = $this->suggestedQuantity($product, $primary);
                $unitCost = $this->unitCost($product, $primary);
                $lineTotal = Money::multiply($unitCost, $quantity);

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'supplier_sku' => $primary?->pivot?->supplier_sku,
                    'quantity_ordered' => $quantity,
                    'quantity_received' => 0,
                    'unit_cost' => $unitCost,
                    'subtotal' => $lineTotal,
                    'tax' => 0,
                    'total' => $lineTotal,
                ]);

                $subtotal = Money::add($subtotal, $lineTotal);
            }

            $po->update(['subtotal' => $subtotal, 'total' => $subtotal]);

            $productNames = collect($products)->pluck('name')->implode(', ');
            ActivityLog::create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'subject_type' => PurchaseOrder::class,
                'subject_id' => $po->id,
                'action' => $activityAction,
                'description' => "{$activityDescription} {$po->po_number} for: {$productNames}",
                'properties' => [
                    'po_number' => $po->po_number,
                    'supplier_id' => $supplierId,
                    'product_count' => count($products),
                    'total' => $subtotal,
                ],
            ]);

            return $po;
        });
    }

    /**
     * Query of an organization's products with only their primary supplier
     * link loaded, ready for suggestedQuantity()/groupByPrimarySupplier().
     *
     * @return Builder<Product>
     */
    public function productsWithPrimarySupplier(int $organizationId): Builder
    {
        return Product::query()
            ->where('organization_id', $organizationId)
            ->with(self::primarySupplierEagerLoad());
    }

    /**
     * Names of products, for messages.
     *
     * @param  array<int, Product>|Collection<int, Product>  $products
     */
    public function describe(array|Collection $products): string
    {
        return collect($products)->map(fn (Product $p) => "{$p->name} ({$p->sku})")->implode(', ');
    }
}
