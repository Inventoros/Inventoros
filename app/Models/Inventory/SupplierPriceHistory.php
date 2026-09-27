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
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property string $cost_price
 * @property string $source
 * @property int|null $user_id
 * @property Carbon $recorded_at
 * @property-read Product $product
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
        'supplier_id',
        'purchase_order_id',
        'cost_price',
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
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * Record what a supplier charged for a product, now, by the current user.
     */
    public static function record(
        Product $product,
        int $supplierId,
        float|int|string $costPrice,
        string $source,
        ?int $purchaseOrderId = null,
    ): self {
        return self::create([
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
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
