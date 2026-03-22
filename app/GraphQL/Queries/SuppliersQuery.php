<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Inventory\Supplier;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class SuppliersQuery extends Query
{
    protected $attributes = [
        'name' => 'suppliers',
        'description' => 'List suppliers with optional filters',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('Supplier'));
    }

    public function args(): array
    {
        return [
            'search' => [
                'type' => Type::string(),
                'description' => 'Search by name, code, contact name, or email',
            ],
            'is_active' => [
                'type' => Type::boolean(),
                'description' => 'Filter by active status',
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

        $query = Supplier::forOrganization($organizationId);

        if (!empty($args['search'])) {
            $query->search($args['search']);
        }

        if (isset($args['is_active'])) {
            $query->where('is_active', $args['is_active']);
        }

        $sortBy = $args['sort_by'] ?? 'created_at';
        $sortDir = $args['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        $limit = min($args['limit'] ?? 50, 100);

        return $query->limit($limit)->get();
    }
}
