<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Order\OrderItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one kit order line drew of one component: units per kit and units
 * actually taken from stock. The source of truth for restocking a kit line.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $order_item_id
 * @property int $component_product_id
 * @property int $quantity_per_kit
 * @property int $quantity_taken
 */
class OrderItemKitComponent extends Model
{
    use BelongsToOrganization, HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'order_item_id',
        'component_product_id',
        'quantity_per_kit',
        'quantity_taken',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity_per_kit' => 'integer',
        'quantity_taken' => 'integer',
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
     * @return BelongsTo<Product, $this>
     */
    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
