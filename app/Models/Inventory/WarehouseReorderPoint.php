<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock thresholds for one product in one warehouse.
 *
 * A row means "track this product in this warehouse on its own": low-stock
 * detection and reorder suggestions compare the product's on-hand in the
 * warehouse (the sum of its bins at the warehouse's locations) against these
 * values. A blank field inherits the product-level value.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property int $warehouse_id
 * @property int|null $reorder_point
 * @property int|null $reorder_quantity
 * @property int|null $min_stock
 * @property int|null $max_stock
 * @property-read Product $product
 * @property-read Warehouse $warehouse
 */
class WarehouseReorderPoint extends Model
{
    use BelongsToOrganization;

    /**
     * The threshold columns, in display order.
     */
    public const LEVELS = ['min_stock', 'reorder_point', 'reorder_quantity', 'max_stock'];

    protected $fillable = [
        'organization_id',
        'product_id',
        'warehouse_id',
        'reorder_point',
        'reorder_quantity',
        'min_stock',
        'max_stock',
    ];

    protected function casts(): array
    {
        return [
            'reorder_point' => 'integer',
            'reorder_quantity' => 'integer',
            'min_stock' => 'integer',
            'max_stock' => 'integer',
        ];
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
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * SQL for the product's on-hand in this row's warehouse, correlated to
     * the warehouse_reorder_points row in the outer query.
     */
    public static function onHandSql(): string
    {
        return '(select coalesce(sum(pls.quantity), 0) from product_location_stocks pls'
            .' inner join product_locations pl on pl.id = pls.location_id'
            .' where pls.product_id = warehouse_reorder_points.product_id'
            .' and pl.warehouse_id = warehouse_reorder_points.warehouse_id'
            .' and pl.deleted_at is null)';
    }
}
