<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Shipping\Shipment;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class ShipmentType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Shipment',
        'description' => 'A parcel sent for a sales order, with its carrier, tracking and packed lines',
        'model' => Shipment::class,
    ];

    public function fields(): array
    {
        $iso = fn (string $field) => fn (Shipment $s) => $s->{$field}?->toIso8601String();

        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the shipment'],
            'order_id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The order this shipment belongs to'],
            'carrier' => ['type' => Type::nonNull(Type::string()), 'description' => 'The integration: manual or easypost'],
            'carrier_name' => ['type' => Type::string(), 'description' => 'Carrier, for example USPS, UPS, Canada Post'],
            'service' => ['type' => Type::string(), 'description' => 'Service level'],
            'tracking_number' => ['type' => Type::string(), 'description' => 'Carrier tracking number'],
            'tracking_url' => ['type' => Type::string(), 'description' => 'Public tracking page'],
            'tracking_status_detail' => ['type' => Type::string(), 'description' => 'Latest carrier tracking message'],
            'status' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'pending, label_created, shipped, in_transit, delivered, exception or cancelled',
                'resolve' => fn (Shipment $s) => $s->status->value,
            ],
            'has_label' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether a carrier label was bought',
                'resolve' => fn (Shipment $s) => $s->hasLabel(),
            ],
            'cost' => ['type' => Type::float(), 'description' => 'What the shipment cost'],
            'currency' => ['type' => Type::string(), 'description' => 'Currency of the cost'],
            'weight_oz' => ['type' => Type::float(), 'description' => 'Parcel weight in ounces'],
            'warehouse_id' => ['type' => Type::int(), 'description' => 'Ship-from warehouse'],
            'shipped_at' => ['type' => Type::string(), 'description' => 'When it left', 'resolve' => $iso('shipped_at')],
            'delivered_at' => ['type' => Type::string(), 'description' => 'When the carrier reported delivery', 'resolve' => $iso('delivered_at')],
            'created_at' => ['type' => Type::string(), 'description' => 'Creation timestamp', 'resolve' => $iso('created_at')],
            'items' => [
                'type' => Type::listOf(GraphQL::type('ShipmentItem')),
                'description' => 'Order lines and quantities in this shipment',
                'resolve' => fn (Shipment $s) => $s->items,
            ],
        ];
    }
}
