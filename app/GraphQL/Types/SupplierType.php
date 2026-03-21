<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Inventory\Supplier;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class SupplierType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Supplier',
        'description' => 'A supplier in the system',
        'model' => Supplier::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the supplier',
            ],
            'name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Supplier name',
            ],
            'code' => [
                'type' => Type::string(),
                'description' => 'Supplier code',
            ],
            'contact_name' => [
                'type' => Type::string(),
                'description' => 'Contact person name',
            ],
            'email' => [
                'type' => Type::string(),
                'description' => 'Email address',
            ],
            'phone' => [
                'type' => Type::string(),
                'description' => 'Phone number',
            ],
            'address' => [
                'type' => Type::string(),
                'description' => 'Street address',
            ],
            'city' => [
                'type' => Type::string(),
                'description' => 'City',
            ],
            'state' => [
                'type' => Type::string(),
                'description' => 'State/Province',
            ],
            'zip_code' => [
                'type' => Type::string(),
                'description' => 'Zip/Postal code',
            ],
            'country' => [
                'type' => Type::string(),
                'description' => 'Country',
            ],
            'full_address' => [
                'type' => Type::string(),
                'description' => 'Full formatted address',
                'resolve' => fn (Supplier $supplier) => $supplier->full_address,
            ],
            'website' => [
                'type' => Type::string(),
                'description' => 'Website URL',
            ],
            'payment_terms' => [
                'type' => Type::string(),
                'description' => 'Payment terms',
            ],
            'currency' => [
                'type' => Type::string(),
                'description' => 'Default currency',
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Additional notes',
            ],
            'is_active' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the supplier is active',
            ],
            'products' => [
                'type' => Type::listOf(GraphQL::type('Product')),
                'description' => 'Products supplied',
                'resolve' => fn (Supplier $supplier) => $supplier->products,
            ],
            'products_count' => [
                'type' => Type::int(),
                'description' => 'Number of products supplied',
                'resolve' => fn (Supplier $supplier) => $supplier->products()->count(),
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (Supplier $supplier) => $supplier->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (Supplier $supplier) => $supplier->updated_at?->toIso8601String(),
            ],
        ];
    }
}
