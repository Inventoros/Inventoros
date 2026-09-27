<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Order\ReturnOrder;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class ReturnOrdersQuery extends Query
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'returnOrders',
        'description' => 'List returns (RMAs) with optional filters',
    ];

    protected function permissions(): array
    {
        return ['manage_returns'];
    }

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('ReturnOrder'));
    }

    public function args(): array
    {
        return [
            'status' => ['type' => Type::string(), 'description' => 'pending, approved, received, completed or rejected'],
            'type' => ['type' => Type::string(), 'description' => 'return or exchange'],
            'order_id' => ['type' => Type::int(), 'description' => 'Only returns against this order'],
            'limit' => ['type' => Type::int(), 'description' => 'Maximum number of results (default: 50, max: 100)'],
        ];
    }

    public function resolve($root, array $args)
    {
        return ReturnOrder::with(['order', 'items.product'])
            ->forOrganization($this->organizationId())
            ->when($args['status'] ?? null, fn ($query, $status) => $query->byStatus($status))
            ->when($args['type'] ?? null, fn ($query, $type) => $query->byType($type))
            ->when($args['order_id'] ?? null, fn ($query, $orderId) => $query->where('order_id', $orderId))
            ->latest()
            ->latest('id')
            ->limit(max(1, min($args['limit'] ?? 50, 100)))
            ->get();
    }
}
