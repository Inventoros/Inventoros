<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Inventory\ProductLocation;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

class LocationType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Location',
        'description' => 'A physical storage location',
        'model' => ProductLocation::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the location',
            ],
            'name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Location name',
            ],
            'code' => [
                'type' => Type::string(),
                'description' => 'Location code',
            ],
            'description' => [
                'type' => Type::string(),
                'description' => 'Location description',
            ],
            'aisle' => [
                'type' => Type::string(),
                'description' => 'Aisle identifier',
            ],
            'shelf' => [
                'type' => Type::string(),
                'description' => 'Shelf identifier',
            ],
            'bin' => [
                'type' => Type::string(),
                'description' => 'Bin identifier',
            ],
            'full_location' => [
                'type' => Type::string(),
                'description' => 'Full formatted location string',
                'resolve' => fn (ProductLocation $location) => $location->full_location,
            ],
            'is_active' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the location is active',
            ],
            'products_count' => [
                'type' => Type::int(),
                'description' => 'Number of products at this location',
                'resolve' => fn (ProductLocation $location) => $location->products()->count(),
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (ProductLocation $location) => $location->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (ProductLocation $location) => $location->updated_at?->toIso8601String(),
            ],
        ];
    }
}
