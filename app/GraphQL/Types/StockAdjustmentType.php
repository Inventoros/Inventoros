<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Inventory\StockAdjustment;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class StockAdjustmentType extends GraphQLType
{
    protected $attributes = [
        'name' => 'StockAdjustment',
        'description' => 'A stock adjustment record',
        'model' => StockAdjustment::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the adjustment',
            ],
            'product_id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The product ID',
            ],
            'product_variant_id' => [
                'type' => Type::int(),
                'description' => 'The variant ID if applicable',
            ],
            'user_id' => [
                'type' => Type::int(),
                'description' => 'The user who made the adjustment',
            ],
            'type' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Adjustment type: manual, count, damage, return, transfer',
            ],
            'quantity_before' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Stock quantity before adjustment',
            ],
            'quantity_after' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Stock quantity after adjustment',
            ],
            'adjustment_quantity' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The adjustment amount (positive or negative)',
            ],
            'reason' => [
                'type' => Type::string(),
                'description' => 'Reason for the adjustment',
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Additional notes',
            ],
            'reference_type' => [
                'type' => Type::string(),
                'description' => 'Reference model type',
            ],
            'reference_id' => [
                'type' => Type::int(),
                'description' => 'Reference model ID',
            ],
            'product' => [
                'type' => GraphQL::type('Product'),
                'description' => 'The associated product',
                'resolve' => fn (StockAdjustment $adj) => $adj->product,
            ],
            'user_name' => [
                'type' => Type::string(),
                'description' => 'Name of the user who made the adjustment',
                'resolve' => fn (StockAdjustment $adj) => $adj->user?->name,
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (StockAdjustment $adj) => $adj->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (StockAdjustment $adj) => $adj->updated_at?->toIso8601String(),
            ],
        ];
    }
}
