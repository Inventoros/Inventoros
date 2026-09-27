<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\PurchaseOrderService;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class ReceivePurchaseOrderMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'receivePurchaseOrder',
        'description' => 'Receive quantities against a sent or partly received purchase order, adding them to stock',
    ];

    protected function permissions(): array
    {
        return ['receive_purchase_orders'];
    }

    public function type(): Type
    {
        return GraphQL::type('PurchaseOrder');
    }

    public function args(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the purchase order'],
            'items' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('PurchaseOrderReceiveItemInput')))), 'description' => 'Quantities received per line'],
        ];
    }

    protected function rules(array $args = []): array
    {
        return ['items' => ['required', 'array', 'min:1', 'max:500']];
    }

    public function resolve($root, array $args)
    {
        $purchaseOrder = PurchaseOrder::forOrganization($this->organizationId())->find($args['id'])
            ?? throw new Error('Purchase order not found');

        $received = $this->attempt(fn () => app(PurchaseOrderService::class)->receive($purchaseOrder, $args['items']));

        if ($received === 0) {
            throw new Error('No items were received');
        }

        return $purchaseOrder->fresh(['supplier', 'items']);
    }
}
