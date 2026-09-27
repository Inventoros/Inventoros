<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Enums\ShipmentStatus;
use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Shipping\Shipment;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class ShipmentsQuery extends Query
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'shipments',
        'description' => 'List shipments, newest first, with optional filters',
    ];

    protected function permissions(): array
    {
        return ['view_shipments'];
    }

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('Shipment'));
    }

    public function args(): array
    {
        return [
            'order_id' => ['type' => Type::int(), 'description' => 'Only shipments for this order'],
            'status' => ['type' => Type::string(), 'description' => implode(', ', ShipmentStatus::values())],
            'carrier' => ['type' => Type::string(), 'description' => 'manual or easypost'],
            'limit' => ['type' => Type::int(), 'description' => 'Maximum number of results (default: 50, max: 100)'],
        ];
    }

    public function resolve($root, array $args)
    {
        return Shipment::with('items.orderItem')
            ->where('organization_id', $this->organizationId())
            ->when($args['order_id'] ?? null, fn ($q, $orderId) => $q->where('order_id', $orderId))
            ->when($args['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($args['carrier'] ?? null, fn ($q, $carrier) => $q->where('carrier', $carrier))
            ->latest('id')
            ->limit(max(1, min($args['limit'] ?? 50, 100)))
            ->get();
    }
}
