<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Inventory\Supplier;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class SupplierQuery extends Query
{
    protected $attributes = [
        'name' => 'supplier',
        'description' => 'Get a single supplier by ID',
    ];

    public function type(): Type
    {
        return GraphQL::type('Supplier');
    }

    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the supplier',
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        $organizationId = $user->organization_id;

        return Supplier::with('products')
            ->forOrganization($organizationId)
            ->findOrFail($args['id']);
    }
}
