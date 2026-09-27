<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A parcel sent for a sales order. See the shipments migration for the column
 * meanings; ShipmentService owns every state change.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $order_id
 * @property int|null $warehouse_id
 * @property int|null $created_by
 * @property string $carrier
 * @property string|null $carrier_name
 * @property string|null $service
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property string|null $label_path
 * @property string|null $label_url
 * @property string|null $carrier_shipment_id
 * @property string|null $carrier_tracker_id
 * @property array|null $carrier_rates
 * @property array|null $carrier_response
 * @property string|null $cost
 * @property string|null $currency
 * @property string|null $weight_oz
 * @property string|null $length_in
 * @property string|null $width_in
 * @property string|null $height_in
 * @property ShipmentStatus $status
 * @property string|null $tracking_status_detail
 * @property bool $notify_customer
 * @property Carbon|null $customer_notified_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $last_tracked_at
 */
class Shipment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'order_id',
        'warehouse_id',
        'created_by',
        'carrier',
        'carrier_name',
        'service',
        'tracking_number',
        'tracking_url',
        'label_path',
        'label_url',
        'carrier_shipment_id',
        'carrier_tracker_id',
        'carrier_rates',
        'carrier_response',
        'cost',
        'currency',
        'weight_oz',
        'length_in',
        'width_in',
        'height_in',
        'status',
        'tracking_status_detail',
        'notify_customer',
        'customer_notified_at',
        'shipped_at',
        'delivered_at',
        'last_tracked_at',
    ];

    /**
     * Carrier payloads are internal and the label path is a private-disk path;
     * neither is ever serialized to a client.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'carrier_rates',
        'carrier_response',
        'label_path',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'carrier_rates' => 'array',
            'carrier_response' => 'array',
            'cost' => 'decimal:2',
            'weight_oz' => 'decimal:2',
            'length_in' => 'decimal:2',
            'width_in' => 'decimal:2',
            'height_in' => 'decimal:2',
            'notify_customer' => 'boolean',
            'customer_notified_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_tracked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The ship-from warehouse.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ShipmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /**
     * Shipments that still hold their quantities (anything but cancelled).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', ShipmentStatus::CANCELLED->value);
    }

    /**
     * Shipments whose goods have physically left the warehouse.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLeftWarehouse(Builder $query): Builder
    {
        return $query->whereIn('status', ShipmentStatus::leftValues());
    }

    public function hasLabel(): bool
    {
        return $this->label_path !== null || $this->label_url !== null;
    }

    /**
     * The client-facing shape shared by the order page, REST, and MCP.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $this->loadMissing('items.orderItem', 'warehouse');

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'carrier' => $this->carrier,
            'carrier_name' => $this->carrier_name,
            'service' => $this->service,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'has_label' => $this->hasLabel(),
            'cost' => $this->cost,
            'currency' => $this->currency,
            'weight_oz' => $this->weight_oz,
            'length_in' => $this->length_in,
            'width_in' => $this->width_in,
            'height_in' => $this->height_in,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tracking_status_detail' => $this->tracking_status_detail,
            'warehouse' => $this->warehouse ? ['id' => $this->warehouse->id, 'name' => $this->warehouse->name] : null,
            'notify_customer' => $this->notify_customer,
            'customer_notified_at' => $this->customer_notified_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->items->map(fn (ShipmentItem $item) => [
                'order_item_id' => $item->order_item_id,
                'product_name' => $item->orderItem?->product_name,
                'sku' => $item->orderItem?->sku,
                'quantity' => $item->quantity,
            ])->values()->all(),
        ];
    }
}
