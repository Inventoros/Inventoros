<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The product <-> supplier link: what this supplier calls the product, what it
 * charges, how long it takes, and whether it is the product's primary
 * (preferred) supplier. Exactly one link per product is primary; the
 * ProductService keeps that invariant.
 *
 * @property int $id
 * @property int $product_id
 * @property int $supplier_id
 * @property string|null $cost_price
 * @property string|null $supplier_sku
 * @property int|null $lead_time_days
 * @property int|null $minimum_order_quantity
 * @property bool $is_primary
 */
class ProductSupplier extends Pivot
{
    protected $table = 'product_supplier';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'lead_time_days' => 'integer',
            'minimum_order_quantity' => 'integer',
            'is_primary' => 'boolean',
        ];
    }
}
