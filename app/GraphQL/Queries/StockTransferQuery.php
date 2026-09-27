<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Inventory\StockTransfer;
use App\Services\StockTransferService;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class StockTransferQuery extends Query
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'stockTransfer',
        'description' => 'Get a single stock transfer by ID',
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
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the transfer'],
        ];
    }

    public function resolve($root, array $args)
    {
        $transfer = StockTransfer::with(StockTransfersQuery::WITH)
            ->forOrganization($this->organizationId())
            ->find($args['id']) ?? throw new Error('Stock transfer not found');

        app(StockTransferService::class)->authorizeTransfer($this->actor(), $transfer);

        return $transfer;
    }
}
