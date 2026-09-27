<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Inventory\StockTransfer;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class StockTransfersQuery extends Query
{
    use RequiresPermissions;

    /** Relations every transfer field may touch, loaded once per query. */
    public const WITH = ['fromLocation', 'toLocation', 'transferredBy', 'items.product'];

    protected $attributes = [
        'name' => 'stockTransfers',
        'description' => 'List stock transfers with optional filters',
    ];

    protected function permissions(): array
    {
        return ['transfer_stock'];
    }

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('StockTransfer'));
    }

    public function args(): array
    {
        return [
            'status' => ['type' => Type::string(), 'description' => 'pending, in_transit, completed or cancelled'],
            'search' => ['type' => Type::string(), 'description' => 'Search by transfer number'],
            'limit' => ['type' => Type::int(), 'description' => 'Maximum number of results (default: 50, max: 100)'],
        ];
    }

    public function resolve($root, array $args)
    {
        return StockTransfer::with(self::WITH)
            ->forOrganization($this->organizationId())
            ->when($args['status'] ?? null, fn ($query, $status) => $query->byStatus($status))
            ->when($args['search'] ?? null, fn ($query, $search) => $query->where('transfer_number', 'like', "%{$search}%"))
            ->latest()
            ->latest('id')
            ->limit(max(1, min($args['limit'] ?? 50, 100)))
            ->get();
    }
}
