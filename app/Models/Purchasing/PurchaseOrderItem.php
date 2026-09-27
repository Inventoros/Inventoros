<?php

declare(strict_types=1);

namespace App\Models\Purchasing;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\SupplierPriceHistory;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents an item within a purchase order.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property string $product_name
 * @property string|null $sku
 * @property string|null $supplier_sku
 * @property int $quantity_ordered
 * @property int $quantity_received
 * @property string $unit_cost
 * @property string $subtotal
 * @property string $tax
 * @property string $total
 * @property string|null $notes
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $remaining_quantity
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 */
class PurchaseOrderItem extends Model
{
    protected static function booted(): void
    {
        // Changing the lines of an approved draft changes what was approved,
        // so it has to go back for approval before it can be sent.
        $reopen = function (PurchaseOrderItem $item): void {
            $po = PurchaseOrder::withoutGlobalScope(OrganizationScope::class)->find($item->purchase_order_id);

            if ($po
                && $po->status === PurchaseOrder::STATUS_DRAFT
                && $po->approval_status === PurchaseOrder::APPROVAL_APPROVED) {
                $po->clearApproval();
                $po->save();
            }
        };

        static::saved($reopen);
        static::deleted($reopen);
    }

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'sku',
        'supplier_sku',
        'quantity_ordered',
        'quantity_received',
        'unit_cost',
        'subtotal',
        'tax',
        'total',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'unit_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the purchase order this item belongs to.
     *
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Get the product associated with this item.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the product variant this line buys, when it buys one.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Resolve the variant a validated line names, scoped to its product (and
     * so its organization). Null when the line names none.
     */
    public static function resolveVariant(Product $product, mixed $variantId): ?ProductVariant
    {
        if ($variantId === null || $variantId === '') {
            return null;
        }

        return ProductVariant::where('organization_id', $product->organization_id)
            ->where('product_id', $product->id)
            ->findOrFail($variantId);
    }

    /**
     * Calculate and set totals based on quantity and unit cost.
     */
    public function calculateTotals(): void
    {
        $this->subtotal = $this->quantity_ordered * $this->unit_cost;
        $this->total = $this->subtotal + ($this->tax ?? 0);
    }

    /**
     * Check if item is fully received.
     */
    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }

    /**
     * Get remaining quantity to receive.
     */
    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    /**
     * Receive a quantity of this item.
     *
     * Creates a stock adjustment and updates the stock of what the line buys:
     * the variant when the line names one, otherwise the product.
     *
     * @param  int  $quantity  The quantity to receive
     */
    public function receive(int $quantity): ?StockAdjustment
    {
        if ($quantity <= 0) {
            return null;
        }

        // Cap the quantity to remaining amount
        $maxReceivable = $this->remaining_quantity;
        $quantityToReceive = min($quantity, $maxReceivable);

        if ($quantityToReceive <= 0) {
            return null;
        }

        // The product may have been deleted after this PO line was created;
        // StockAdjustment::adjust() would fatal on a null product. Skip rather
        // than record a receipt against a non-existent product.
        if (! $this->product) {
            return null;
        }

        // Same for a variant line whose variant has since been deleted:
        // crediting the parent instead would put the goods on the wrong count.
        if ($this->product_variant_id !== null && ! $this->variant) {
            return null;
        }

        // Update received quantity
        $this->quantity_received += $quantityToReceive;
        $this->save();

        $purchaseOrder = $this->purchaseOrder;

        if ($this->variant !== null) {
            // A variant line credits the variant's own stock, through the same
            // ledger path order fulfilment and restocks use. Variant stock has
            // no per-location breakdown, so there is no bin to book into.
            $adjustment = StockAdjustment::adjustVariant(
                variant: $this->variant,
                quantity: $quantityToReceive,
                type: 'purchase',
                reason: "PO {$purchaseOrder->po_number} received",
                notes: $this->notes,
                reference: $purchaseOrder,
            );

            $purchaseOrder->refresh();
            $purchaseOrder->updateReceivingStatus();

            return $adjustment;
        }

        // Create stock adjustment and update product stock
        $adjustment = StockAdjustment::adjust(
            product: $this->product,
            quantity: $quantityToReceive,
            type: 'purchase',
            reason: "PO {$purchaseOrder->po_number} received",
            notes: $this->notes,
            reference: $purchaseOrder,
            // Book the received goods into the product's location so the
            // per-location breakdown rises with the total instead of drifting
            // into "unassigned". Null location -> total only (adjust() skips it).
            locationId: $this->product->location_id,
        );

        $this->recordSupplierCost($purchaseOrder);

        // Update the purchase order status
        $purchaseOrder->refresh();
        $purchaseOrder->updateReceivingStatus();

        return $adjustment;
    }

    /**
     * Log the unit cost this line was received at to the supplier price
     * history. A line received in several partial deliveries is logged once.
     */
    protected function recordSupplierCost(PurchaseOrder $purchaseOrder): void
    {
        if (! $purchaseOrder->supplier_id || $this->unit_cost === null) {
            return;
        }

        $alreadyRecorded = SupplierPriceHistory::withoutGlobalScopes()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('product_id', $this->product_id)
            ->where('source', SupplierPriceHistory::SOURCE_PURCHASE_ORDER)
            ->where('cost_price', $this->unit_cost)
            ->exists();

        if (! $alreadyRecorded) {
            SupplierPriceHistory::record(
                $this->product,
                (int) $purchaseOrder->supplier_id,
                $this->unit_cost,
                SupplierPriceHistory::SOURCE_PURCHASE_ORDER,
                $purchaseOrder->id,
            );
        }
    }

    /**
     * Static method to create item from product.
     *
     * @return static
     */
    public static function fromProduct(Product $product, int $quantity, ?float $unitCost = null): self
    {
        // Try to get cost from supplier pivot or product
        if ($unitCost === null) {
            $unitCost = $product->purchase_price ?? $product->price ?? 0;
        }

        $item = new self([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
        ]);

        $item->calculateTotals();

        return $item;
    }
}
