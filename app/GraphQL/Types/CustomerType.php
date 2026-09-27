<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Customer;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

class CustomerType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Customer',
        'description' => 'A customer',
        'model' => Customer::class,
    ];

    public function fields(): array
    {
        $string = fn (string $description) => ['type' => Type::string(), 'description' => $description];

        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the customer'],
            'name' => ['type' => Type::nonNull(Type::string()), 'description' => 'Customer name'],
            'code' => $string('Customer code'),
            'company_name' => $string('Company name'),
            'contact_name' => $string('Contact person'),
            'email' => $string('Email address'),
            'phone' => $string('Phone number'),
            'billing_address' => $string('Billing street address'),
            'billing_city' => $string('Billing city'),
            'billing_state' => $string('Billing state or province'),
            'billing_zip_code' => $string('Billing postal code'),
            'billing_country' => $string('Billing country'),
            'shipping_address' => $string('Shipping street address'),
            'shipping_city' => $string('Shipping city'),
            'shipping_state' => $string('Shipping state or province'),
            'shipping_zip_code' => $string('Shipping postal code'),
            'shipping_country' => $string('Shipping country'),
            'tax_id' => $string('Tax ID'),
            'payment_terms' => $string('Payment terms'),
            'credit_limit' => ['type' => Type::float(), 'description' => 'Credit limit'],
            'currency' => $string('Default currency'),
            'notes' => $string('Notes'),
            'is_active' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Whether the customer is active'],
            'orders_count' => [
                'type' => Type::int(),
                'description' => 'Number of orders (loaded by the customers/customer queries)',
                'resolve' => fn (Customer $customer) => $customer->orders_count,
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (Customer $customer) => $customer->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (Customer $customer) => $customer->updated_at?->toIso8601String(),
            ],
        ];
    }
}
