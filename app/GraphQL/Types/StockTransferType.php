<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Inventory\StockTransfer;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class StockTransferType extends GraphQLType
{
    protected $attributes = [
        'name' => 'StockTransfer',
        'description' => 'A stock transfer between two locations',
        'model' => StockTransfer::class,
    ];

    public function fields(): array
    {
        $timestamp = fn (string $field, string $description) => [
            'type' => Type::string(),
            'description' => $description,
            'resolve' => fn (StockTransfer $t) => $t->{$field}?->toIso8601String(),
        ];

        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the transfer'],
            'transfer_number' => ['type' => Type::nonNull(Type::string()), 'description' => 'Transfer number'],
            'status' => ['type' => Type::nonNull(Type::string()), 'description' => 'pending, in_transit, completed or cancelled'],
            'from_location_id' => ['type' => Type::int(), 'description' => 'Source location ID'],
            'to_location_id' => ['type' => Type::int(), 'description' => 'Destination location ID'],
            'is_inter_warehouse' => ['type' => Type::boolean(), 'description' => 'Whether the transfer crosses warehouses'],
            'shipping_method' => ['type' => Type::string(), 'description' => 'Shipping method'],
            'tracking_number' => ['type' => Type::string(), 'description' => 'Tracking number'],
            'notes' => ['type' => Type::string(), 'description' => 'Notes'],
            'shipped_at' => $timestamp('shipped_at', 'When the transfer was shipped'),
            'estimated_arrival' => $timestamp('estimated_arrival', 'Estimated arrival'),
            'completed_at' => $timestamp('completed_at', 'When the transfer was completed'),
            'from_location' => [
                'type' => GraphQL::type('Location'),
                'description' => 'Source location',
                'resolve' => fn (StockTransfer $t) => $t->fromLocation,
            ],
            'to_location' => [
                'type' => GraphQL::type('Location'),
                'description' => 'Destination location',
                'resolve' => fn (StockTransfer $t) => $t->toLocation,
            ],
            'transferred_by_name' => [
                'type' => Type::string(),
                'description' => 'Name of the user who created the transfer',
                'resolve' => fn (StockTransfer $t) => $t->transferredBy?->name,
            ],
            'items' => [
                'type' => Type::listOf(GraphQL::type('StockTransferItem')),
                'description' => 'Transfer lines',
                'resolve' => fn (StockTransfer $t) => $t->items,
            ],
            'created_at' => $timestamp('created_at', 'Creation timestamp'),
            'updated_at' => $timestamp('updated_at', 'Last update timestamp'),
        ];
    }
}
