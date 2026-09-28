<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents an item within a return order.
 *
 * @property int $id
 * @property int $return_order_id
 * @property int $order_item_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $quantity
 * @property string $condition
 * @property bool $restock
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ReturnOrder $returnOrder
 * @property-read OrderItem $orderItem
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class ReturnOrderItem extends Model
{
    protected $fillable = [
        'return_order_id',
        'order_item_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'condition',
        'restock',
    ];

    /**
     * A return line brings back what its order line sold: when the sale was a
     * variant, the line carries that variant so receiving it credits the
     * variant's stock. Derived from the order line unless set explicitly.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if ($item->product_variant_id === null && $item->order_item_id !== null) {
                $item->product_variant_id = OrderItem::query()->whereKey($item->order_item_id)->value('product_variant_id');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'restock' => 'boolean',
        ];
    }

    /**
     * Get the return order this item belongs to.
     *
     * @return BelongsTo<ReturnOrder, $this>
     */
    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    /**
     * Get the original order item.
     *
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * Get the product.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variant this line returns, when the sale was a variant.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
