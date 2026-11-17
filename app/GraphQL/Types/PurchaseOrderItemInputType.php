<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\InputType;

class PurchaseOrderItemInputType extends InputType
{
    protected $attributes = [
        'name' => 'PurchaseOrderItemInput',
        'description' => 'A purchase order line. Pass `id` on update to keep an existing line; lines left out are removed.',
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::int(),
                'description' => 'Existing line ID (update only)',
            ],
            'product_id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The product ordered',
            ],
            'product_variant_id' => [
                'type' => Type::int(),
                'description' => 'The variant ordered, for variant-tracked products',
            ],
            'quantity' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Quantity ordered',
                'rules' => ['required', 'integer', 'min:1'],
            ],
            'unit_cost' => [
                'type' => Type::nonNull(Type::float()),
                'description' => 'Unit cost',
                'rules' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            ],
            'supplier_sku' => [
                'type' => Type::string(),
                'description' => 'Supplier SKU',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'tax_rate' => [
                'type' => Type::float(),
                'description' => 'Tax rate in percent (0-100). The server computes the line tax from it and ignores tax. Left out on update, an existing line keeps its rate',
                'rules' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:100'],
            ],
            'tax' => [
                'type' => Type::float(),
                'description' => 'Tax amount for this line, kept as sent when the line has no tax_rate',
                'rules' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            ],
        ];
    }
}
