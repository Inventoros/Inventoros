<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Inventory\Product;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class ProductsQuery extends Query
{
    protected $attributes = [
        'name' => 'products',
        'description' => 'List products with optional filters',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('Product'));
    }

    public function args(): array
    {
        return [
            'search' => [
                'type' => Type::string(),
                'description' => 'Search by name, SKU, or barcode',
            ],
            'category_id' => [
                'type' => Type::int(),
                'description' => 'Filter by category ID',
            ],
            'location_id' => [
                'type' => Type::int(),
                'description' => 'Filter by location ID',
            ],
            'is_active' => [
                'type' => Type::boolean(),
                'description' => 'Filter by active status',
            ],
            'low_stock' => [
                'type' => Type::boolean(),
                'description' => 'Show only products below minimum stock',
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

        $query = Product::with(['category', 'location'])
            ->forOrganization($organizationId);

        if (!empty($args['search'])) {
            $search = $args['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if (isset($args['category_id'])) {
            $query->where('category_id', $args['category_id']);
        }

        if (isset($args['location_id'])) {
            $query->where('location_id', $args['location_id']);
        }

        if (isset($args['is_active'])) {
            $query->where('is_active', $args['is_active']);
        }

        if (!empty($args['low_stock'])) {
            $query->lowStock();
        }

        $sortBy = $args['sort_by'] ?? 'created_at';
        $sortDir = $args['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        $limit = min($args['limit'] ?? 50, 100);

        return $query->limit($limit)->get();
    }
}
