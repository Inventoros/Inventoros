<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Order\OrderItem;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class OrderItemType extends GraphQLType
{
    protected $attributes = [
        'name' => 'OrderItem',
        'description' => 'A line item within an order',
        'model' => OrderItem::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the order item',
            ],
            'order_id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The parent order ID',
            ],
            'product_id' => [
                'type' => Type::int(),
                'description' => 'The product ID',
            ],
            'product_name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Product name at time of order',
            ],
            'sku' => [
                'type' => Type::string(),
                'description' => 'Product SKU at time of order',
            ],
            'quantity' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Quantity ordered',
            ],
            'unit_price' => [
                'type' => Type::nonNull(Type::float()),
                'description' => 'Price per unit',
            ],
            'subtotal' => [
                'type' => Type::float(),
                'description' => 'Line subtotal',
            ],
            'tax' => [
                'type' => Type::float(),
                'description' => 'Line tax',
            ],
            'total' => [
                'type' => Type::float(),
                'description' => 'Line total',
            ],
            'product' => [
                'type' => GraphQL::type('Product'),
                'description' => 'The associated product',
                'resolve' => fn (OrderItem $item) => $item->product,
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (OrderItem $item) => $item->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (OrderItem $item) => $item->updated_at?->toIso8601String(),
            ],
        ];
    }
}
