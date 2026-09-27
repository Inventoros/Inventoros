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

class CompleteStockTransferMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'completeStockTransfer',
        'description' => 'Complete a pending or in-transit transfer, moving stock from the source bin to the destination bin',
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
        return ['id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the transfer']];
    }

    public function resolve($root, array $args)
    {
        $transfer = StockTransfer::forOrganization($this->organizationId())->find($args['id'])
            ?? throw new Error('Stock transfer not found');

        $this->attempt(fn () => app(StockTransferService::class)->complete($transfer, $this->actor()));

        return $transfer->fresh(StockTransfersQuery::WITH);
    }
}
