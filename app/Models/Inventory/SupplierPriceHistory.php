<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded cost a supplier charged for a product.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property string $cost_price
 * @property string|null $currency The currency cost_price is in (null on older rows)
 * @property string|null $source_cost_price The supplier's own price, when cost_price was converted
 * @property string|null $source_currency
 * @property string|null $exchange_rate The purchase order rate used for the conversion
 * @property string $source
 * @property int|null $user_id
 * @property Carbon $recorded_at
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read Supplier $supplier
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read User|null $user
 */
class SupplierPriceHistory extends Model
{
    use BelongsToOrganization;

    public const SOURCE_SUPPLIER_LINK = 'supplier_link';

    public const SOURCE_PURCHASE_ORDER = 'purchase_order';

    protected $table = 'supplier_price_history';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'product_id',
        'product_variant_id',
        'supplier_id',
        'purchase_order_id',
        'cost_price',
        'currency',
        'source_cost_price',
        'source_currency',
        'exchange_rate',
        'source',
        'user_id',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'source_cost_price' => 'decimal:2',
            'exchange_rate' => 'decimal:10',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * Record what a supplier charged for a product, now, by the current user.
     *
     * $currency attributes (currency, source_cost_price, source_currency,
     * exchange_rate) say what currency the cost is in and, for a converted
     * cost, what the supplier charged and at which rate.
     *
     * @param  array{currency?: string|null, source_cost_price?: string|null, source_currency?: string|null, exchange_rate?: string|null}  $currency
     */
    public static function record(
        Product $product,
        int $supplierId,
        float|int|string $costPrice,
        string $source,
        ?int $purchaseOrderId = null,
        ?ProductVariant $variant = null,
        array $currency = [],
    ): self {
        return self::create(array_intersect_key($currency, array_flip(['currency', 'source_cost_price', 'source_currency', 'exchange_rate'])) + [
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'supplier_id' => $supplierId,
            'purchase_order_id' => $purchaseOrderId,
            'cost_price' => $costPrice,
            'source' => $source,
            'user_id' => auth()->id(),
            'recorded_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
