<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Order\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order line (and how many of its units) packed into a shipment.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int $order_item_id
 * @property int $quantity
 */
class ShipmentItem extends Model
{
    protected $fillable = [
        'shipment_id',
        'order_item_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
