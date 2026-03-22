<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Purchasing\PurchaseOrder;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class PurchaseOrdersQuery extends Query
{
    protected $attributes = [
        'name' => 'purchaseOrders',
        'description' => 'List purchase orders with optional filters',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('PurchaseOrder'));
    }

    public function args(): array
    {
        return [
            'search' => [
                'type' => Type::string(),
                'description' => 'Search by PO number or supplier name',
            ],
            'status' => [
                'type' => Type::string(),
                'description' => 'Filter by status: draft, sent, partial, received, cancelled',
            ],
            'supplier_id' => [
                'type' => Type::int(),
                'description' => 'Filter by supplier ID',
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

        $query = PurchaseOrder::with(['supplier', 'items'])
            ->forOrganization($organizationId);

        if (!empty($args['search'])) {
            $query->search($args['search']);
        }

        if (!empty($args['status'])) {
            $query->byStatus($args['status']);
        }

        if (isset($args['supplier_id'])) {
            $query->bySupplier($args['supplier_id']);
        }

        $sortBy = $args['sort_by'] ?? 'created_at';
        $sortDir = $args['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        $limit = min($args['limit'] ?? 50, 100);

        return $query->limit($limit)->get();
    }
}
