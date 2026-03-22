<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Purchasing\PurchaseOrder;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class PurchaseOrderType extends GraphQLType
{
    protected $attributes = [
        'name' => 'PurchaseOrder',
        'description' => 'A purchase order',
        'model' => PurchaseOrder::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the purchase order',
            ],
            'po_number' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Purchase order number',
            ],
            'status' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Status: draft, sent, partial, received, cancelled',
            ],
            'status_label' => [
                'type' => Type::string(),
                'description' => 'Human-readable status label',
                'resolve' => fn (PurchaseOrder $po) => $po->status_label,
            ],
            'status_color' => [
                'type' => Type::string(),
                'description' => 'Status badge color',
                'resolve' => fn (PurchaseOrder $po) => $po->status_color,
            ],
            'order_date' => [
                'type' => Type::string(),
                'description' => 'Order date',
                'resolve' => fn (PurchaseOrder $po) => $po->order_date?->format('Y-m-d'),
            ],
            'expected_date' => [
                'type' => Type::string(),
                'description' => 'Expected delivery date',
                'resolve' => fn (PurchaseOrder $po) => $po->expected_date?->format('Y-m-d'),
            ],
            'received_date' => [
                'type' => Type::string(),
                'description' => 'Date received',
                'resolve' => fn (PurchaseOrder $po) => $po->received_date?->format('Y-m-d'),
            ],
            'subtotal' => [
                'type' => Type::float(),
                'description' => 'Subtotal amount',
            ],
            'tax' => [
                'type' => Type::float(),
                'description' => 'Tax amount',
            ],
            'shipping' => [
                'type' => Type::float(),
                'description' => 'Shipping cost',
            ],
            'total' => [
                'type' => Type::float(),
                'description' => 'Total amount',
            ],
            'currency' => [
                'type' => Type::string(),
                'description' => 'Currency code',
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Additional notes',
            ],
            'can_be_edited' => [
                'type' => Type::boolean(),
                'description' => 'Whether the PO can be edited',
                'resolve' => fn (PurchaseOrder $po) => $po->canBeEdited(),
            ],
            'can_be_sent' => [
                'type' => Type::boolean(),
                'description' => 'Whether the PO can be sent',
                'resolve' => fn (PurchaseOrder $po) => $po->canBeSent(),
            ],
            'can_receive_items' => [
                'type' => Type::boolean(),
                'description' => 'Whether items can be received',
                'resolve' => fn (PurchaseOrder $po) => $po->canReceiveItems(),
            ],
            'can_be_cancelled' => [
                'type' => Type::boolean(),
                'description' => 'Whether the PO can be cancelled',
                'resolve' => fn (PurchaseOrder $po) => $po->canBeCancelled(),
            ],
            'supplier' => [
                'type' => GraphQL::type('Supplier'),
                'description' => 'The supplier',
                'resolve' => fn (PurchaseOrder $po) => $po->supplier,
            ],
            'items' => [
                'type' => Type::listOf(GraphQL::type('PurchaseOrderItem')),
                'description' => 'Purchase order line items',
                'resolve' => fn (PurchaseOrder $po) => $po->items,
            ],
            'items_count' => [
                'type' => Type::int(),
                'description' => 'Number of line items',
                'resolve' => fn (PurchaseOrder $po) => $po->items()->count(),
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (PurchaseOrder $po) => $po->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (PurchaseOrder $po) => $po->updated_at?->toIso8601String(),
            ],
        ];
    }
}
