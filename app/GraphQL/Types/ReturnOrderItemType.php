<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Order\ReturnOrderItem;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class ReturnOrderItemType extends GraphQLType
{
    protected $attributes = [
        'name' => 'ReturnOrderItem',
        'description' => 'A line on a return',
        'model' => ReturnOrderItem::class,
    ];

    public function fields(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the return line'],
            'order_item_id' => ['type' => Type::int(), 'description' => 'The order line being returned'],
            'product_id' => ['type' => Type::int(), 'description' => 'The returned product'],
            'quantity' => ['type' => Type::nonNull(Type::int()), 'description' => 'Quantity returned'],
            'condition' => ['type' => Type::string(), 'description' => 'new, used or damaged'],
            'restock' => ['type' => Type::boolean(), 'description' => 'Whether the units go back into stock on receive'],
            'product' => [
                'type' => GraphQL::type('Product'),
                'description' => 'The returned product',
                'resolve' => fn (ReturnOrderItem $item) => $item->product,
            ],
        ];
    }
}
