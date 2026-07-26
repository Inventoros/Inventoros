<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\Order\OrderItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a specific batch a specific order line consumed. Lets a
 * cancelled or deleted order return exactly what it took to each batch.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $order_item_id
 * @property int $product_batch_id
 * @property int $quantity
 */
class OrderItemBatchAllocation extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'order_item_id',
        'product_batch_id',
        'quantity',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<ProductBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }
}
