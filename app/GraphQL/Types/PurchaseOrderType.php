<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\GraphQL\Support\ListResourceLimits;
use App\Models\Purchasing\PurchaseOrder;
use App\Support\ExchangeMath;
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
            'approval_status' => [
                'type' => Type::string(),
                'description' => 'Approval state: null (none asked for), pending, approved, rejected',
            ],
            'approval_notes' => [
                'type' => Type::string(),
                'description' => 'Approver comment or rejection reason',
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
            'sent_at' => [
                'type' => Type::string(),
                'description' => 'When the purchase order was last emailed to the supplier',
                'resolve' => fn (PurchaseOrder $po) => $po->sent_at?->toIso8601String(),
            ],
            'sent_to' => [
                'type' => Type::string(),
                'description' => 'Address the purchase order was last emailed to',
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
            'organization_currency' => [
                'type' => Type::string(),
                'description' => 'The organization currency the PO is valued in',
                'resolve' => fn (PurchaseOrder $po) => $po->organizationCurrency(),
            ],
            'exchange_rate' => [
                'type' => Type::string(),
                'description' => 'Exchange rate snapshot as a decimal string: 1 exchange_rate_from = exchange_rate exchange_rate_to. Null for a PO in the organization currency, or a foreign-currency PO with no rate recorded',
                'resolve' => fn (PurchaseOrder $po) => $po->exchange_rate !== null ? ExchangeMath::rate((string) $po->exchange_rate) : null,
            ],
            'exchange_rate_from' => [
                'type' => Type::string(),
                'description' => 'Currency the rate converts from (the PO currency)',
            ],
            'exchange_rate_to' => [
                'type' => Type::string(),
                'description' => 'Currency the rate converts to (the organization currency)',
            ],
            'exchange_rate_provider' => [
                'type' => Type::string(),
                'description' => 'Provider id the rate came from (null for a rate entered by hand)',
            ],
            'exchange_rate_effective_date' => [
                'type' => Type::string(),
                'description' => 'Date the rate is for (YYYY-MM-DD)',
                'resolve' => fn (PurchaseOrder $po) => $po->exchange_rate_effective_date?->format('Y-m-d'),
            ],
            'exchange_rate_retrieved_at' => [
                'type' => Type::string(),
                'description' => 'When the rate was retrieved or entered',
                'resolve' => fn (PurchaseOrder $po) => $po->exchange_rate_retrieved_at?->toIso8601String(),
            ],
            'exchange_rate_source' => [
                'type' => Type::string(),
                'description' => 'provider, fallback, confirmed (a stale rate a user accepted) or manual',
            ],
            'exchange_rate_set_by' => [
                'type' => Type::int(),
                'description' => 'User who entered or confirmed the rate',
            ],
            'exchange_rate_locked_at' => [
                'type' => Type::string(),
                'description' => 'When the rate was locked (the PO left draft); it never changes after',
                'resolve' => fn (PurchaseOrder $po) => $po->exchange_rate_locked_at?->toIso8601String(),
            ],
            'converted_subtotal' => [
                'type' => Type::float(),
                'description' => 'Subtotal in the organization currency',
            ],
            'converted_tax' => [
                'type' => Type::float(),
                'description' => 'Tax in the organization currency',
            ],
            'converted_shipping' => [
                'type' => Type::float(),
                'description' => 'Shipping in the organization currency',
            ],
            'converted_total' => [
                'type' => Type::float(),
                'description' => 'Total in the organization currency (null without a rate)',
            ],
            'organization_currency_total' => [
                'type' => Type::float(),
                'description' => 'Total in the organization currency: the total itself for a PO in that currency, the converted total otherwise, null when no rate was recorded',
                'resolve' => fn (PurchaseOrder $po) => ($total = $po->organizationCurrencyTotal()) === null ? null : (float) $total,
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
                'description' => 'Purchase order line items (at most 100 entries).',
                'resolve' => fn (PurchaseOrder $po) => ListResourceLimits::relation($po, 'items'),
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
