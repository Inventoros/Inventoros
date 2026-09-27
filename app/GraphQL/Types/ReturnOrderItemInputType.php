<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\InputType;

class ReturnOrderItemInputType extends InputType
{
    protected $attributes = [
        'name' => 'ReturnOrderItemInput',
        'description' => 'A line on a new return. The product is taken from the order line.',
    ];

    public function fields(): array
    {
        return [
            'order_item_id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The order line being returned',
            ],
            'quantity' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Quantity to return',
                'rules' => ['required', 'integer', 'min:1'],
            ],
            'condition' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'new, used or damaged',
                'rules' => ['required', 'in:new,used,damaged'],
            ],
            'restock' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Put the units back into stock when received',
            ],
        ];
    }
}
