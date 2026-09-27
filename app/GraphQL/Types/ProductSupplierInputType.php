<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\InputType;

/**
 * One supplier link in createProduct / updateProduct `suppliers`. Validation
 * (org-scoped supplier, one primary, ranges) lives on the mutations, shared
 * with the web and REST surfaces via ValidatesProductSuppliers.
 */
class ProductSupplierInputType extends InputType
{
    protected $attributes = [
        'name' => 'ProductSupplierInput',
        'description' => 'A supplier the product is bought from',
    ];

    public function fields(): array
    {
        return [
            'supplier_id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Supplier ID (must belong to your organization)',
            ],
            'supplier_sku' => [
                'type' => Type::string(),
                'description' => "The supplier's SKU for the product",
            ],
            'cost_price' => [
                'type' => Type::float(),
                'description' => 'What the supplier charges per unit',
            ],
            'lead_time_days' => [
                'type' => Type::int(),
                'description' => 'Supplier lead time in days',
            ],
            'minimum_order_quantity' => [
                'type' => Type::int(),
                'description' => 'Minimum units per order (1 or more)',
            ],
            'is_primary' => [
                'type' => Type::boolean(),
                'description' => 'Primary (preferred) supplier. At most one; the first is used when none is set.',
            ],
        ];
    }
}
