<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Order\Order;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class OrderQuery extends Query
{
    protected $attributes = [
        'name' => 'order',
        'description' => 'Get a single order by ID',
    ];

    public function type(): Type
    {
        return GraphQL::type('Order');
    }

    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the order',
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        $organizationId = $user->organization_id;

        return Order::with('items.product')
            ->forOrganization($organizationId)
            ->findOrFail($args['id']);
    }
}
