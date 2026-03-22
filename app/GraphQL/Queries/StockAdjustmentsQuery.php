<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Inventory\StockAdjustment;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class StockAdjustmentsQuery extends Query
{
    protected $attributes = [
        'name' => 'stockAdjustments',
        'description' => 'List stock adjustments with optional filters',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('StockAdjustment'));
    }

    public function args(): array
    {
        return [
            'product_id' => [
                'type' => Type::int(),
                'description' => 'Filter by product ID',
            ],
            'type' => [
                'type' => Type::string(),
                'description' => 'Filter by adjustment type: manual, count, damage, return, transfer',
            ],
            'date_from' => [
                'type' => Type::string(),
                'description' => 'Filter adjustments from this date (YYYY-MM-DD)',
            ],
            'date_to' => [
                'type' => Type::string(),
                'description' => 'Filter adjustments until this date (YYYY-MM-DD)',
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

        $query = StockAdjustment::with(['product', 'user'])
            ->forOrganization($organizationId);

        if (isset($args['product_id'])) {
            $query->forProduct($args['product_id']);
        }

        if (!empty($args['type'])) {
            $query->ofType($args['type']);
        }

        if (!empty($args['date_from'])) {
            $query->where('created_at', '>=', $args['date_from']);
        }

        if (!empty($args['date_to'])) {
            $query->where('created_at', '<=', $args['date_to']);
        }

        $sortBy = $args['sort_by'] ?? 'created_at';
        $sortDir = $args['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        $limit = min($args['limit'] ?? 50, 100);

        return $query->limit($limit)->get();
    }
}
