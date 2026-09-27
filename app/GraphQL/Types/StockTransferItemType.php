<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Inventory\StockTransferItem;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class StockTransferItemType extends GraphQLType
{
    protected $attributes = [
        'name' => 'StockTransferItem',
        'description' => 'A line on a stock transfer',
        'model' => StockTransferItem::class,
    ];

    public function fields(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the transfer line'],
            'product_id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The product moved'],
            'quantity' => ['type' => Type::nonNull(Type::int()), 'description' => 'Quantity moved'],
            'notes' => ['type' => Type::string(), 'description' => 'Line notes'],
            'product' => [
                'type' => GraphQL::type('Product'),
                'description' => 'The product moved',
                'resolve' => fn (StockTransferItem $item) => $item->product,
            ],
        ];
    }
}
