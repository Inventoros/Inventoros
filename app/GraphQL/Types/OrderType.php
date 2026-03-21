<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Order\Order;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class OrderType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Order',
        'description' => 'An order in the system',
        'model' => Order::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the order',
            ],
            'order_number' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Unique order number',
            ],
            'source' => [
                'type' => Type::string(),
                'description' => 'Order source (api, web, etc.)',
            ],
            'external_id' => [
                'type' => Type::string(),
                'description' => 'External system ID',
            ],
            'customer_name' => [
                'type' => Type::string(),
                'description' => 'Customer name',
            ],
            'customer_email' => [
                'type' => Type::string(),
                'description' => 'Customer email',
            ],
            'customer_address' => [
                'type' => Type::string(),
                'description' => 'Customer address',
            ],
            'status' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Order status',
            ],
            'approval_status' => [
                'type' => Type::string(),
                'description' => 'Approval status',
            ],
            'subtotal' => [
                'type' => Type::float(),
                'description' => 'Subtotal amount',
            ],
            'tax' => [
                'type' => Type::float(),
                'description' => 'Tax amount',
            ],
            'shipping' => [
                'type' => Type::float(),
                'description' => 'Shipping cost',
            ],
            'total' => [
                'type' => Type::float(),
                'description' => 'Total amount',
            ],
            'currency' => [
                'type' => Type::string(),
                'description' => 'Currency code',
            ],
            'order_date' => [
                'type' => Type::string(),
                'description' => 'Order date',
                'resolve' => fn (Order $order) => $order->order_date?->toIso8601String(),
            ],
            'shipped_at' => [
                'type' => Type::string(),
                'description' => 'Shipped timestamp',
                'resolve' => fn (Order $order) => $order->shipped_at?->toIso8601String(),
            ],
            'delivered_at' => [
                'type' => Type::string(),
                'description' => 'Delivered timestamp',
                'resolve' => fn (Order $order) => $order->delivered_at?->toIso8601String(),
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Order notes',
            ],
            'items' => [
                'type' => Type::listOf(GraphQL::type('OrderItem')),
                'description' => 'Order line items',
                'resolve' => fn (Order $order) => $order->items,
            ],
            'items_count' => [
                'type' => Type::int(),
                'description' => 'Number of line items',
                'resolve' => fn (Order $order) => $order->items()->count(),
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (Order $order) => $order->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (Order $order) => $order->updated_at?->toIso8601String(),
            ],
        ];
    }
}
