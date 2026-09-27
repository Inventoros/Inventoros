<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\InputType;

class OrderItemInputType extends InputType
{
    protected $attributes = [
        'name' => 'OrderItemInput',
        'description' => 'Input type for creating order items',
    ];

    public function fields(): array
    {
        return [
            'product_id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The product ID',
                'rules' => ['required', 'integer', 'exists:products,id'],
            ],
            'product_variant_id' => [
                'type' => Type::int(),
                'description' => 'The specific variant ID (required for variant-tracked products)',
                'rules' => ['nullable', 'integer', 'exists:product_variants,id'],
            ],
            'quantity' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Quantity to order',
                'rules' => ['required', 'integer', 'min:1'],
            ],
            'unit_price' => [
                'type' => Type::float(),
                'description' => 'Unit price override (uses product price if not provided)',
                'rules' => ['nullable', 'numeric', 'min:0'],
            ],
            'discount_type' => [
                'type' => Type::string(),
                'description' => 'Line discount type: percent or fixed',
                'rules' => ['nullable', 'string', 'in:percent,fixed'],
            ],
            'discount_value' => [
                'type' => Type::float(),
                'description' => 'Line discount: a percentage (0-100) or an amount up to the line subtotal',
                'rules' => ['nullable', 'numeric', 'min:0'],
            ],
            'tax' => [
                'type' => Type::float(),
                'description' => 'Tax amount for this line item',
                'rules' => ['nullable', 'numeric', 'min:0'],
            ],
        ];
    }
}
