<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Models\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents an item within a return order.
 *
 * @property int $id
 * @property int $return_order_id
 * @property int $order_item_id
 * @property int $product_id
 * @property int $quantity
 * @property string $condition
 * @property bool $restock
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order\ReturnOrder $returnOrder
 * @property-read \App\Models\Order\OrderItem $orderItem
 * @property-read \App\Models\Inventory\Product $product
 */
class ReturnOrderItem extends Model
{
    protected $fillable = [
        'return_order_id',
        'order_item_id',
        'product_id',
        'quantity',
        'condition',
        'restock',
    ];

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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Order\ReturnOrder, $this>
     */
    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    /**
     * Get the original order item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Order\OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * Get the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
