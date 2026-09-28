<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Services\ReturnOrderService;
use GraphQL\Type\Definition\Type;
use Illuminate\Validation\Rule;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class CreateReturnOrderMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'createReturnOrder',
        'description' => 'Create a return (RMA) against an order. Each line is capped at the quantity not yet returned.',
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
            'order_id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The order being returned against'],
            'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'return or exchange'],
            'reason' => ['type' => Type::nonNull(Type::string()), 'description' => 'Reason for the return'],
            'notes' => ['type' => Type::string(), 'description' => 'Notes'],
            'items' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('ReturnOrderItemInput')))), 'description' => 'Lines to return'],
        ];
    }

    protected function rules(array $args = []): array
    {
        return [
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')->where('organization_id', $this->organizationId())->whereNull('deleted_at')],
            'type' => ['required', 'in:return,exchange'],
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct', Rule::exists('order_items', 'id')->where('order_id', (int) ($args['order_id'] ?? 0))],
            'items.*.restock' => ['required', 'boolean'],
        ];
    }

    public function resolve($root, array $args)
    {
        $returnOrder = $this->attempt(fn () => app(ReturnOrderService::class)->create($this->organizationId(), $this->actor(), $args));

        return $returnOrder->load(['order', 'items.product']);
    }
}
