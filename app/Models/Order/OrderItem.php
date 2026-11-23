<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Enums\DiscountType;
use App\Models\Inventory\OrderItemKitComponent;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property int $quantity_backordered Units on this line not yet fulfilled from stock (0 unless backordered)
 * @property-read int $quantity_fulfilled quantity − quantity_backordered: the units that left stock
 * @property string $unit_price
 * @property string|null $unit_cost Unit cost at the time of sale (null when unknown)
 * @property Carbon|null $unit_cost_backfilled_at Set when unit_cost was estimated from current cost by the backfill
 * @property string $subtotal Gross: quantity x unit_price, before the line discount
 * @property DiscountType|null $discount_type
 * @property string|null $discount_value
 * @property string $discount_amount
 * @property string|null $tax_rate Percent the line was taxed at; null when its tax is an amount as entered
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
        'quantity_backordered',
        'unit_price',
        'unit_cost',
        'unit_cost_backfilled_at',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
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
            'quantity_backordered' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'unit_cost_backfilled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * The units of this line that were fulfilled from stock: the ordered
     * quantity less whatever is still on backorder.
     *
     * @return Attribute<int, never>
     */
    protected function quantityFulfilled(): Attribute
    {
        return Attribute::make(
            get: fn (): int => (int) $this->quantity - (int) $this->quantity_backordered,
        );
    }

    /**
     * The line's net: its gross subtotal less its own line discount, before
     * tax (and before any share of an order-level discount).
     */
    public function netAmount(): string
    {
        return Money::subtract($this->subtotal, $this->discount_amount);
    }

    /**
     * The unit cost to record on a line sold now: the variant's own purchase
     * price when it has one, otherwise the product's. Null when neither is
     * known, so reports can tell "unknown" from "free".
     */
    public static function costAtSale(Product $product, ?ProductVariant $variant = null): ?string
    {
        if ($product->isKit()) {
            return app(\App\Services\Inventory\KitSaleService::class)->costAtSale($product);
        }

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
     * What a kit line drew from each component (empty for plain lines).
     *
     * @return HasMany<OrderItemKitComponent, $this>
     */
    public function kitComponents(): HasMany
    {
        return $this->hasMany(OrderItemKitComponent::class);
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
