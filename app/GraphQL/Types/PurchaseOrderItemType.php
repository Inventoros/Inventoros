<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Purchasing\PurchaseOrderItem;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class PurchaseOrderItemType extends GraphQLType
{
    protected $attributes = [
        'name' => 'PurchaseOrderItem',
        'description' => 'A line item within a purchase order',
        'model' => PurchaseOrderItem::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the item',
            ],
            'product_id' => [
                'type' => Type::int(),
                'description' => 'The product ID',
            ],
            'product_name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Product name',
            ],
            'sku' => [
                'type' => Type::string(),
                'description' => 'Product SKU',
            ],
            'supplier_sku' => [
                'type' => Type::string(),
                'description' => 'Supplier-specific SKU',
            ],
            'quantity_ordered' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Quantity ordered',
            ],
            'quantity_received' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Quantity received so far',
            ],
            'remaining_quantity' => [
                'type' => Type::int(),
                'description' => 'Remaining quantity to receive',
                'resolve' => fn (PurchaseOrderItem $item) => $item->remaining_quantity,
            ],
            'is_fully_received' => [
                'type' => Type::boolean(),
                'description' => 'Whether fully received',
                'resolve' => fn (PurchaseOrderItem $item) => $item->isFullyReceived(),
            ],
            'unit_cost' => [
                'type' => Type::nonNull(Type::float()),
                'description' => 'Unit cost',
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
            'notes' => [
                'type' => Type::string(),
                'description' => 'Item notes',
            ],
            'product' => [
                'type' => GraphQL::type('Product'),
                'description' => 'The associated product',
                'resolve' => fn (PurchaseOrderItem $item) => $item->product,
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (PurchaseOrderItem $item) => $item->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (PurchaseOrderItem $item) => $item->updated_at?->toIso8601String(),
            ],
        ];
    }
}
