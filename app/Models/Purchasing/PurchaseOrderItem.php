<?php

namespace App\Models\Purchasing;

use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
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
     * Get the purchase order this item belongs to
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Get the product associated with this item
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculate and set totals based on quantity and unit cost
     */
    public function calculateTotals(): void
    {
        $this->subtotal = $this->quantity_ordered * $this->unit_cost;
        $this->total = $this->subtotal + ($this->tax ?? 0);
    }

    /**
     * Check if item is fully received
     */
    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }

    /**
     * Get remaining quantity to receive
     */
    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    /**
     * Receive a quantity of this item
     * Creates a stock adjustment and updates product stock
     *
     * @param int $quantity The quantity to receive
     * @return StockAdjustment|null
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

        // Update received quantity
        $this->quantity_received += $quantityToReceive;
        $this->save();

        // Create stock adjustment and update product stock
        $purchaseOrder = $this->purchaseOrder;
        $adjustment = StockAdjustment::adjust(
            product: $this->product,
            quantity: $quantityToReceive,
            type: 'purchase',
            reason: "PO {$purchaseOrder->po_number} received",
            notes: $this->notes,
            reference: $purchaseOrder
        );

        // Update the purchase order status
        $purchaseOrder->refresh();
        $purchaseOrder->updateReceivingStatus();

        return $adjustment;
    }

    /**
     * Static method to create item from product
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
