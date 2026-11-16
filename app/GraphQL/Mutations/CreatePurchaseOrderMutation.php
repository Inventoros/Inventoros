<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Services\PurchaseOrderService;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class CreatePurchaseOrderMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'createPurchaseOrder',
        'description' => 'Create a draft purchase order',
    ];

    protected function permissions(): array
    {
        return ['create_purchase_orders'];
    }

    public function type(): Type
    {
        return GraphQL::type('PurchaseOrder');
    }

    public function args(): array
    {
        return [
            'supplier_id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The supplier'],
            'order_date' => ['type' => Type::nonNull(Type::string()), 'description' => 'Order date (YYYY-MM-DD)'],
            'expected_date' => ['type' => Type::string(), 'description' => 'Expected delivery date (YYYY-MM-DD)'],
            'currency' => ['type' => Type::nonNull(Type::string()), 'description' => 'Currency (ISO 4217)'],
            'shipping' => ['type' => Type::float(), 'description' => 'Shipping cost'],
            'tax' => ['type' => Type::float(), 'description' => 'Tax'],
            'notes' => ['type' => Type::string(), 'description' => 'Notes'],
            'exchange_rate_mode' => ['type' => Type::string(), 'description' => 'For a currency other than the organization\'s: auto (provider rate), manual (use exchange_rate) or confirm (accept a stale provider rate). manual and confirm need override_purchase_order_exchange_rate'],
            'exchange_rate' => ['type' => Type::string(), 'description' => 'Exchange rate as a decimal string (1 PO currency = this many organization currency), for exchange_rate_mode manual'],
            'items' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('PurchaseOrderItemInput')))), 'description' => 'Lines to order'],
        ];
    }

    protected function rules(array $args = []): array
    {
        return PurchaseOrderArgs::rules($this->organizationId(), creating: true);
    }

    public function resolve($root, array $args)
    {
        $purchaseOrder = $this->attempt(fn () => app(PurchaseOrderService::class)->create($this->organizationId(), $this->actor(), $args));

        return $purchaseOrder->load(['supplier', 'items']);
    }
}
