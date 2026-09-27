<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Order\OrderPayment;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

class OrderPaymentType extends GraphQLType
{
    protected $attributes = [
        'name' => 'OrderPayment',
        'description' => 'A payment received against an order, or a refund given back',
        'model' => OrderPayment::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the payment',
            ],
            'type' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'payment or refund',
                'resolve' => fn (OrderPayment $payment) => $payment->type->value,
            ],
            'amount' => [
                'type' => Type::nonNull(Type::float()),
                'description' => 'Amount (always positive; the type says which way it moved)',
            ],
            'method' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'cash, card, bank_transfer, cheque or other',
                'resolve' => fn (OrderPayment $payment) => $payment->method->value,
            ],
            'reference' => [
                'type' => Type::string(),
                'description' => 'Reference (transaction id, cheque number, and so on)',
            ],
            'paid_at' => [
                'type' => Type::string(),
                'description' => 'When the money moved',
                'resolve' => fn (OrderPayment $payment) => $payment->paid_at?->toIso8601String(),
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Notes',
            ],
            'voided_at' => [
                'type' => Type::string(),
                'description' => 'When the payment was voided (null when it stands)',
                'resolve' => fn (OrderPayment $payment) => $payment->voided_at?->toIso8601String(),
            ],
            'void_reason' => [
                'type' => Type::string(),
                'description' => 'Why it was voided',
            ],
        ];
    }
}
