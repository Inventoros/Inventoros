<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Inventory\ProductLocation;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class LocationsQuery extends Query
{
    protected $attributes = [
        'name' => 'locations',
        'description' => 'List storage locations',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('Location'));
    }

    public function args(): array
    {
        return [
            'is_active' => [
                'type' => Type::boolean(),
                'description' => 'Filter by active status',
            ],
            'search' => [
                'type' => Type::string(),
                'description' => 'Search by name or code',
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

        $query = ProductLocation::forOrganization($organizationId);

        if (isset($args['is_active'])) {
            $query->where('is_active', $args['is_active']);
        }

        if (!empty($args['search'])) {
            $search = $args['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $limit = min($args['limit'] ?? 50, 100);

        return $query->orderBy('name')->limit($limit)->get();
    }
}
