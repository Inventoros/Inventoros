<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\InputType;

class StockTransferItemInputType extends InputType
{
    protected $attributes = [
        'name' => 'StockTransferItemInput',
        'description' => 'A line on a new stock transfer',
    ];

    public function fields(): array
    {
        return [
            'product_id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The product to move',
            ],
            'quantity' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Quantity to move',
                'rules' => ['required', 'integer', 'min:1'],
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Line notes',
                'rules' => ['nullable', 'string', 'max:500'],
            ],
        ];
    }
}
