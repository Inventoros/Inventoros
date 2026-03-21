<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Inventory\ProductVariant;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class ProductVariantType extends GraphQLType
{
    protected $attributes = [
        'name' => 'ProductVariant',
        'description' => 'A specific variant of a product',
        'model' => ProductVariant::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the variant',
            ],
            'product_id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The parent product ID',
            ],
            'sku' => [
                'type' => Type::string(),
                'description' => 'Variant SKU',
            ],
            'barcode' => [
                'type' => Type::string(),
                'description' => 'Variant barcode',
            ],
            'title' => [
                'type' => Type::string(),
                'description' => 'Variant title',
            ],
            'price' => [
                'type' => Type::float(),
                'description' => 'Variant price',
            ],
            'purchase_price' => [
                'type' => Type::float(),
                'description' => 'Purchase price',
            ],
            'compare_at_price' => [
                'type' => Type::float(),
                'description' => 'Compare-at price for sales',
            ],
            'effective_price' => [
                'type' => Type::float(),
                'description' => 'Effective price (variant or product fallback)',
                'resolve' => fn (ProductVariant $variant) => $variant->effective_price,
            ],
            'stock' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Current stock quantity',
            ],
            'min_stock' => [
                'type' => Type::int(),
                'description' => 'Minimum stock threshold',
            ],
            'image' => [
                'type' => Type::string(),
                'description' => 'Variant image URL',
            ],
            'weight' => [
                'type' => Type::float(),
                'description' => 'Weight value',
            ],
            'weight_unit' => [
                'type' => Type::string(),
                'description' => 'Weight unit (kg, lb, etc.)',
            ],
            'is_active' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the variant is active',
            ],
            'requires_shipping' => [
                'type' => Type::boolean(),
                'description' => 'Whether shipping is required',
            ],
            'position' => [
                'type' => Type::int(),
                'description' => 'Sort position',
            ],
            'is_low_stock' => [
                'type' => Type::boolean(),
                'description' => 'Whether below minimum stock',
                'resolve' => fn (ProductVariant $variant) => $variant->isLowStock(),
            ],
            'is_out_of_stock' => [
                'type' => Type::boolean(),
                'description' => 'Whether out of stock',
                'resolve' => fn (ProductVariant $variant) => $variant->isOutOfStock(),
            ],
            'is_on_sale' => [
                'type' => Type::boolean(),
                'description' => 'Whether the variant is on sale',
                'resolve' => fn (ProductVariant $variant) => $variant->isOnSale(),
            ],
            'discount_percentage' => [
                'type' => Type::float(),
                'description' => 'Discount percentage if on sale',
                'resolve' => fn (ProductVariant $variant) => $variant->discount_percentage,
            ],
            'profit' => [
                'type' => Type::float(),
                'description' => 'Profit per unit',
                'resolve' => fn (ProductVariant $variant) => $variant->profit,
            ],
            'profit_margin' => [
                'type' => Type::float(),
                'description' => 'Profit margin percentage',
                'resolve' => fn (ProductVariant $variant) => $variant->profit_margin,
            ],
            'product' => [
                'type' => GraphQL::type('Product'),
                'description' => 'The parent product',
                'resolve' => fn (ProductVariant $variant) => $variant->product,
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (ProductVariant $variant) => $variant->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (ProductVariant $variant) => $variant->updated_at?->toIso8601String(),
            ],
        ];
    }
}
