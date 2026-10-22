<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Concerns\RequiresPermissions;
use App\GraphQL\Queries\StockTransfersQuery;
use App\Models\Inventory\StockTransfer;
use App\Services\StockTransferService;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class TransitionStockTransferMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'transitionStockTransfer',
        'description' => 'Prepare, ship, receive (complete) or cancel a stock transfer through its warehouse workflow',
    ];

    protected function permissions(): array
    {
        return ['transfer_stock'];
    }

    public function type(): Type
    {
        return GraphQL::type('StockTransfer');
    }

    public function args(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int())],
            'action' => ['type' => Type::nonNull(Type::string()), 'rules' => ['required', 'in:prepare,ship,complete,cancel']],
            'tracking_number' => ['type' => Type::string(), 'rules' => ['nullable', 'string', 'max:255']],
            'shipping_method' => ['type' => Type::string(), 'rules' => ['nullable', 'string', 'max:255']],
        ];
    }

    public function resolve($root, array $args)
    {
        $transfer = StockTransfer::forOrganization($this->organizationId())->find($args['id'])
            ?? throw new Error('Stock transfer not found');

        $service = app(StockTransferService::class);
        $this->attempt(fn () => $args['action'] === 'ship'
            ? $service->ship($transfer, $this->actor(), array_intersect_key($args, array_flip(['tracking_number', 'shipping_method'])))
            : $service->{$args['action']}($transfer, $this->actor()));

        return $transfer->fresh(StockTransfersQuery::WITH);
    }
}
