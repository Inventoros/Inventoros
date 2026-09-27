<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Shipping\ShipmentItem;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

class ShipmentItemType extends GraphQLType
{
    protected $attributes = [
        'name' => 'ShipmentItem',
        'description' => 'An order line (and how many of its units) packed into a shipment',
        'model' => ShipmentItem::class,
    ];

    public function fields(): array
    {
        return [
            'order_item_id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The order line'],
            'product_name' => [
                'type' => Type::string(),
                'description' => 'Product name on the order line',
                'resolve' => fn (ShipmentItem $item) => $item->orderItem?->product_name,
            ],
            'sku' => [
                'type' => Type::string(),
                'description' => 'SKU on the order line',
                'resolve' => fn (ShipmentItem $item) => $item->orderItem?->sku,
            ],
            'quantity' => ['type' => Type::nonNull(Type::int()), 'description' => 'Units in this shipment'],
        ];
    }
}
