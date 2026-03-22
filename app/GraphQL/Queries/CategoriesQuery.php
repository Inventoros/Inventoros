<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Inventory\ProductCategory;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class CategoriesQuery extends Query
{
    protected $attributes = [
        'name' => 'categories',
        'description' => 'List product categories',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('ProductCategory'));
    }

    public function args(): array
    {
        return [
            'is_active' => [
                'type' => Type::boolean(),
                'description' => 'Filter by active status',
            ],
            'root_only' => [
                'type' => Type::boolean(),
                'description' => 'Only return root categories (no parent)',
            ],
            'parent_id' => [
                'type' => Type::int(),
                'description' => 'Filter by parent category ID',
            ],
            'search' => [
                'type' => Type::string(),
                'description' => 'Search by name',
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

        $query = ProductCategory::with('children')
            ->forOrganization($organizationId);

        if (isset($args['is_active'])) {
            $query->where('is_active', $args['is_active']);
        }

        if (!empty($args['root_only'])) {
            $query->root();
        }

        if (isset($args['parent_id'])) {
            $query->where('parent_id', $args['parent_id']);
        }

        if (!empty($args['search'])) {
            $query->where('name', 'like', "%{$args['search']}%");
        }

        $limit = min($args['limit'] ?? 50, 100);

        return $query->orderBy('name')->limit($limit)->get();
    }
}
