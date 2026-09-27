<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Order\ReturnOrder;
use App\Services\ReturnOrderService;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class ReturnOrderQuery extends Query
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'returnOrder',
        'description' => 'Get a single return (RMA) by ID',
    ];

    protected function permissions(): array
    {
        return ['manage_returns'];
    }

    public function type(): Type
    {
        return GraphQL::type('ReturnOrder');
    }

    public function args(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the return'],
        ];
    }

    public function resolve($root, array $args)
    {
        $returnOrder = ReturnOrder::with(['order', 'items.product'])
            ->forOrganization($this->organizationId())
            ->find($args['id']) ?? throw new Error('Return not found');

        app(ReturnOrderService::class)->authorizeView($returnOrder, $this->actor());

        return $returnOrder;
    }
}
