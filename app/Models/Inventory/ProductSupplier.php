<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Auth\Organization;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The product <-> supplier link: what this supplier calls the product, what it
 * charges, how long it takes, and whether it is the product's primary
 * (preferred) supplier. Exactly one link per product is primary; the
 * ProductService keeps that invariant.
 *
 * cost_price is in cost_currency, which defaults to the supplier's currency
 * when the link is made and is kept when the supplier's currency changes
 * later. A null cost_currency (a row written straight to the table) reads
 * as the supplier's current currency.
 *
 * @property int $id
 * @property int $product_id
 * @property int $supplier_id
 * @property string|null $cost_price
 * @property string|null $cost_currency
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

    /**
     * The currency a supplier link's cost is in, for a supplier loaded
     * through a product (with its pivot): the link's own currency, else the
     * supplier's, else the organization's.
     */
    public static function costCurrencyOf(Supplier $supplier): string
    {
        return self::normalizeCurrency($supplier->pivot?->cost_currency ?? null)
            ?? self::normalizeCurrency($supplier->currency)
            ?? Organization::currencyFor($supplier->organization_id === null ? null : (int) $supplier->organization_id);
    }

    /**
     * An upper-case three-letter code, or null for a blank value.
     */
    public static function normalizeCurrency(mixed $currency): ?string
    {
        $code = strtoupper(trim((string) $currency));

        return $code === '' ? null : $code;
    }
}
