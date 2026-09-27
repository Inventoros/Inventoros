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

class UpdatePurchaseOrderMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'updatePurchaseOrder',
        'description' => 'Update an editable purchase order. Only the fields passed are changed; passing `items` replaces the lines.',
    ];

    protected function permissions(): array
    {
        return ['edit_purchase_orders'];
    }

    public function type(): Type
    {
        return GraphQL::type('PurchaseOrder');
    }

    public function args(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the purchase order'],
            'supplier_id' => ['type' => Type::int(), 'description' => 'The supplier'],
            'order_date' => ['type' => Type::string(), 'description' => 'Order date (YYYY-MM-DD)'],
            'expected_date' => ['type' => Type::string(), 'description' => 'Expected delivery date (YYYY-MM-DD)'],
            'currency' => ['type' => Type::string(), 'description' => 'Currency (ISO 4217)'],
            'shipping' => ['type' => Type::float(), 'description' => 'Shipping cost'],
            'tax' => ['type' => Type::float(), 'description' => 'Tax'],
            'notes' => ['type' => Type::string(), 'description' => 'Notes'],
            'items' => ['type' => Type::listOf(Type::nonNull(GraphQL::type('PurchaseOrderItemInput'))), 'description' => 'Replacement lines'],
        ];
    }

    protected function rules(array $args = []): array
    {
        return PurchaseOrderArgs::rules($this->organizationId(), creating: false);
    }

    public function resolve($root, array $args)
    {
        $purchaseOrder = PurchaseOrder::forOrganization($this->organizationId())->find($args['id'])
            ?? throw new Error('Purchase order not found');

        $data = collect($args)->except('id')->all();

        $updated = $this->attempt(fn () => app(PurchaseOrderService::class)->update($purchaseOrder, $this->organizationId(), $data));

        return $updated->load(['supplier', 'items']);
    }
}
