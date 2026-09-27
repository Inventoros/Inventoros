<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Order\Order;
use App\Support\PaymentVisibility;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class OrderType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Order',
        'description' => 'An order in the system',
        'model' => Order::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the order',
            ],
            'order_number' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Unique order number',
            ],
            'source' => [
                'type' => Type::string(),
                'description' => 'Order source (api, web, etc.)',
            ],
            'external_id' => [
                'type' => Type::string(),
                'description' => 'External system ID',
            ],
            'customer_name' => [
                'type' => Type::string(),
                'description' => 'Customer name',
            ],
            'customer_email' => [
                'type' => Type::string(),
                'description' => 'Customer email',
            ],
            'customer_address' => [
                'type' => Type::string(),
                'description' => 'Customer address',
            ],
            'status' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Order status',
            ],
            'approval_status' => [
                'type' => Type::string(),
                'description' => 'Approval status',
            ],
            'invoice_number' => [
                'type' => Type::string(),
                'description' => 'Invoice number, assigned the first time the invoice is generated',
            ],
            'invoice_issued_at' => [
                'type' => Type::string(),
                'description' => 'When the invoice number was assigned',
                'resolve' => fn (Order $order) => $order->invoice_issued_at?->toIso8601String(),
            ],
            'invoice_sent_at' => [
                'type' => Type::string(),
                'description' => 'When the invoice was last emailed to the customer',
                'resolve' => fn (Order $order) => $order->invoice_sent_at?->toIso8601String(),
            ],
            'invoice_sent_to' => [
                'type' => Type::string(),
                'description' => 'Address the invoice was last emailed to',
            ],
            'subtotal' => [
                'type' => Type::float(),
                'description' => 'Subtotal amount',
            ],
            'discount_type' => [
                'type' => Type::string(),
                'description' => 'Order-level discount type: percent or fixed (null when none)',
                'resolve' => fn (Order $order) => $order->discount_type?->value,
            ],
            'discount_value' => [
                'type' => Type::float(),
                'description' => 'Order-level discount as entered (a percentage or an amount)',
            ],
            'discount_amount' => [
                'type' => Type::float(),
                'description' => 'Total discount (line discounts plus the order discount); subtotal - discount_amount + tax + shipping = total',
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
            'amount_paid' => [
                'type' => Type::float(),
                'description' => 'Net amount paid (payments minus refunds). Null without view_payments.',
                'resolve' => fn (Order $order) => self::canViewPayments() ? (float) $order->amount_paid : null,
            ],
            'balance_due' => [
                'type' => Type::float(),
                'description' => 'Amount still owed. Null without view_payments.',
                'resolve' => fn (Order $order) => self::canViewPayments() ? (float) $order->balanceDue() : null,
            ],
            'payment_status' => [
                'type' => Type::string(),
                'description' => 'unpaid, partial, paid, overpaid or refunded. Null without view_payments.',
                'resolve' => fn (Order $order) => self::canViewPayments() ? $order->payment_status?->value : null,
            ],
            'payments' => [
                'type' => Type::listOf(GraphQL::type('OrderPayment')),
                'description' => 'Payments and refunds, voided ones included. Null without view_payments.',
                'resolve' => fn (Order $order) => self::canViewPayments() ? $order->payments()->orderBy('paid_at')->orderBy('id')->get() : null,
            ],
            'currency' => [
                'type' => Type::string(),
                'description' => 'Currency code',
            ],
            'order_date' => [
                'type' => Type::string(),
                'description' => 'Order date',
                'resolve' => fn (Order $order) => $order->order_date?->toIso8601String(),
            ],
            'shipped_at' => [
                'type' => Type::string(),
                'description' => 'Shipped timestamp',
                'resolve' => fn (Order $order) => $order->shipped_at?->toIso8601String(),
            ],
            'delivered_at' => [
                'type' => Type::string(),
                'description' => 'Delivered timestamp',
                'resolve' => fn (Order $order) => $order->delivered_at?->toIso8601String(),
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Order notes',
            ],
            'items' => [
                'type' => Type::listOf(GraphQL::type('OrderItem')),
                'description' => 'Order line items',
                'resolve' => fn (Order $order) => $order->items,
            ],
            'items_count' => [
                'type' => Type::int(),
                'description' => 'Number of line items',
                'resolve' => fn (Order $order) => $order->items()->count(),
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (Order $order) => $order->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (Order $order) => $order->updated_at?->toIso8601String(),
            ],
        ];
    }

    private static function canViewPayments(): bool
    {
        // Role AND token abilities, like the REST view_payments routes.
        return PaymentVisibility::allows(auth()->user());
    }
}
