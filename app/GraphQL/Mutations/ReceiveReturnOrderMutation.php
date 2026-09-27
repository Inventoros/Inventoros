<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Order\ReturnOrder;
use App\Services\ReturnOrderService;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class ReceiveReturnOrderMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'receiveReturnOrder',
        'description' => 'Receive an approved return, restocking every line marked for restock',
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
        return ['id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the return']];
    }

    public function resolve($root, array $args)
    {
        $returnOrder = ReturnOrder::forOrganization($this->organizationId())->find($args['id'])
            ?? throw new Error('Return not found');

        $this->attempt(fn () => app(ReturnOrderService::class)->receive($returnOrder, $this->actor()));

        return $returnOrder->fresh(['order', 'items.product']);
    }
}
