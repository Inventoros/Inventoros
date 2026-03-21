<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Order\Order;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class OrdersQuery extends Query
{
    protected $attributes = [
        'name' => 'orders',
        'description' => 'List orders with optional filters',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('Order'));
    }

    public function args(): array
    {
        return [
            'search' => [
                'type' => Type::string(),
                'description' => 'Search by order number, customer name, or email',
            ],
            'status' => [
                'type' => Type::string(),
                'description' => 'Filter by status: pending, processing, shipped, delivered, cancelled',
            ],
            'source' => [
                'type' => Type::string(),
                'description' => 'Filter by order source',
            ],
            'date_from' => [
                'type' => Type::string(),
                'description' => 'Filter orders from this date (YYYY-MM-DD)',
            ],
            'date_to' => [
                'type' => Type::string(),
                'description' => 'Filter orders until this date (YYYY-MM-DD)',
            ],
            'sort_by' => [
                'type' => Type::string(),
                'description' => 'Sort field (default: created_at)',
            ],
            'sort_dir' => [
                'type' => Type::string(),
                'description' => 'Sort direction: asc or desc (default: desc)',
            ],
            'limit' => [
                'type' => Type::int(),
                'description' => 'Maximum number of results (default: 50, max: 100)',
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        $organizationId = $user->organization_id;

        $query = Order::with('items')
            ->forOrganization($organizationId);

        if (!empty($args['search'])) {
            $search = $args['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        if (!empty($args['status'])) {
            $query->byStatus($args['status']);
        }

        if (!empty($args['source'])) {
            $query->bySource($args['source']);
        }

        if (!empty($args['date_from'])) {
            $query->where('order_date', '>=', $args['date_from']);
        }

        if (!empty($args['date_to'])) {
            $query->where('order_date', '<=', $args['date_to']);
        }

        $sortBy = $args['sort_by'] ?? 'created_at';
        $sortDir = $args['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        $limit = min($args['limit'] ?? 50, 100);

        return $query->limit($limit)->get();
    }
}
