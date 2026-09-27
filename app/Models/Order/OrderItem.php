<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents an item within an order.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property string $product_name
 * @property string|null $sku
 * @property int $quantity
 * @property string $unit_price
 * @property string|null $unit_cost Unit cost at the time of sale (null when unknown)
 * @property Carbon|null $unit_cost_backfilled_at Set when unit_cost was estimated from current cost by the backfill
 * @property string $subtotal
 * @property string $tax
 * @property string $total
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Product|null $product
 */
class OrderItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'sku',
        'quantity',
        'unit_price',
        'unit_cost',
        'subtotal',
        'tax',
        'total',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_variant_id' => 'integer',
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'unit_cost_backfilled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * The unit cost to record on a line sold now: the variant's own purchase
     * price when it has one, otherwise the product's. Null when neither is
     * known, so reports can tell "unknown" from "free".
     */
    public static function costAtSale(Product $product, ?ProductVariant $variant = null): ?string
    {
        $cost = $variant?->purchase_price ?? $product->purchase_price;

        return $cost === null ? null : (string) $cost;
    }

    /**
     * Get the order that owns the item.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product associated with the order item.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the specific variant this line was sold as, if any.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
