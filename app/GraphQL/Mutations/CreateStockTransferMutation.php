<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Concerns\RequiresPermissions;
use App\GraphQL\Queries\StockTransfersQuery;
use App\Services\StockTransferService;
use GraphQL\Type\Definition\Type;
use Illuminate\Validation\Rule;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class CreateStockTransferMutation extends Mutation
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'createStockTransfer',
        'description' => 'Create a pending stock transfer between two locations',
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
            'from_location_id' => ['type' => Type::nonNull(Type::int()), 'description' => 'Source location'],
            'to_location_id' => ['type' => Type::nonNull(Type::int()), 'description' => 'Destination location'],
            'notes' => ['type' => Type::string(), 'description' => 'Notes'],
            'shipping_method' => ['type' => Type::string(), 'description' => 'Shipping method (inter-warehouse)'],
            'tracking_number' => ['type' => Type::string(), 'description' => 'Tracking number (inter-warehouse)'],
            'estimated_arrival' => ['type' => Type::string(), 'description' => 'Estimated arrival date (inter-warehouse)'],
            'items' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('StockTransferItemInput')))), 'description' => 'Lines to move'],
        ];
    }

    protected function rules(array $args = []): array
    {
        $organizationId = $this->organizationId();
        $ownLocation = Rule::exists('product_locations', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at');

        return [
            'from_location_id' => ['required', 'integer', $ownLocation],
            'to_location_id' => ['required', 'integer', $ownLocation, 'different:from_location_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'shipping_method' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'estimated_arrival' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
        ];
    }

    public function resolve($root, array $args)
    {
        $transfer = $this->attempt(fn () => app(StockTransferService::class)->create($this->organizationId(), $this->actor(), $args));

        return $transfer->load(StockTransfersQuery::WITH);
    }
}
