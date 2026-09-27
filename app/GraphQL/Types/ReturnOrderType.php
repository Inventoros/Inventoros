<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Order\ReturnOrder;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class ReturnOrderType extends GraphQLType
{
    protected $attributes = [
        'name' => 'ReturnOrder',
        'description' => 'A return (RMA) against a sales order',
        'model' => ReturnOrder::class,
    ];

    public function fields(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the return'],
            'return_number' => ['type' => Type::nonNull(Type::string()), 'description' => 'Return number'],
            'order_id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The order being returned against'],
            'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'return or exchange'],
            'status' => ['type' => Type::nonNull(Type::string()), 'description' => 'pending, approved, received, completed or rejected'],
            'reason' => ['type' => Type::string(), 'description' => 'Reason for the return'],
            'notes' => ['type' => Type::string(), 'description' => 'Notes, including any rejection reason'],
            'refund_amount' => ['type' => Type::float(), 'description' => 'Refund amount'],
            'completed_at' => [
                'type' => Type::string(),
                'description' => 'Completion timestamp',
                'resolve' => fn (ReturnOrder $r) => $r->completed_at?->toIso8601String(),
            ],
            'order' => [
                'type' => GraphQL::type('Order'),
                'description' => 'The sales order',
                'resolve' => fn (ReturnOrder $r) => $r->order,
            ],
            'items' => [
                'type' => Type::listOf(GraphQL::type('ReturnOrderItem')),
                'description' => 'Returned lines',
                'resolve' => fn (ReturnOrder $r) => $r->items,
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (ReturnOrder $r) => $r->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (ReturnOrder $r) => $r->updated_at?->toIso8601String(),
            ],
        ];
    }
}
