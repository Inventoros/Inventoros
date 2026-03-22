<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Purchasing\PurchaseOrder;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class PurchaseOrderQuery extends Query
{
    protected $attributes = [
        'name' => 'purchaseOrder',
        'description' => 'Get a single purchase order by ID',
    ];

    public function type(): Type
    {
        return GraphQL::type('PurchaseOrder');
    }

    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the purchase order',
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        $organizationId = $user->organization_id;

        return PurchaseOrder::with(['supplier', 'items.product', 'creator'])
            ->forOrganization($organizationId)
            ->findOrFail($args['id']);
    }
}
