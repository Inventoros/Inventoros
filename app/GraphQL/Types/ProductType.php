<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Inventory\Product;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class ProductType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Product',
        'description' => 'A product in the inventory',
        'model' => Product::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the product',
            ],
            'sku' => [
                'type' => Type::string(),
                'description' => 'Stock keeping unit',
            ],
            'name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The name of the product',
            ],
            'description' => [
                'type' => Type::string(),
                'description' => 'Product description',
            ],
            'price' => [
                'type' => Type::float(),
                'description' => 'Product base price',
            ],
            'selling_price' => [
                'type' => Type::float(),
                'description' => 'Selling price',
            ],
            'purchase_price' => [
                'type' => Type::float(),
                'description' => 'Purchase/cost price',
            ],
            'currency' => [
                'type' => Type::string(),
                'description' => 'Currency code',
            ],
            'stock' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Current stock quantity',
            ],
            'total_stock' => [
                'type' => Type::int(),
                'description' => 'Total stock including variants',
                'resolve' => fn (Product $product) => $product->total_stock,
            ],
            'min_stock' => [
                'type' => Type::int(),
                'description' => 'Minimum stock threshold',
            ],
            'max_stock' => [
                'type' => Type::int(),
                'description' => 'Maximum stock level',
            ],
            'reorder_point' => [
                'type' => Type::int(),
                'description' => 'Stock level to trigger reorder',
            ],
            'reorder_quantity' => [
                'type' => Type::int(),
                'description' => 'Quantity to reorder',
            ],
            'barcode' => [
                'type' => Type::string(),
                'description' => 'Barcode value',
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Additional notes',
            ],
            'image' => [
                'type' => Type::string(),
                'description' => 'Primary image URL',
            ],
            'thumbnail' => [
                'type' => Type::string(),
                'description' => 'Thumbnail image URL',
            ],
            'is_active' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the product is active',
            ],
            'has_variants' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the product has variants',
            ],
            'tracking_type' => [
                'type' => Type::string(),
                'description' => 'Tracking type: none, batch, or serial',
            ],
            'is_low_stock' => [
                'type' => Type::boolean(),
                'description' => 'Whether the product is below minimum stock',
                'resolve' => fn (Product $product) => $product->isLowStock(),
            ],
            'is_out_of_stock' => [
                'type' => Type::boolean(),
                'description' => 'Whether the product is out of stock',
                'resolve' => fn (Product $product) => $product->isOutOfStock(),
            ],
            'profit' => [
                'type' => Type::float(),
                'description' => 'Profit per unit',
                'resolve' => fn (Product $product) => $product->profit,
            ],
            'profit_margin' => [
                'type' => Type::float(),
                'description' => 'Profit margin percentage',
                'resolve' => fn (Product $product) => $product->profit_margin,
            ],
            'category' => [
                'type' => GraphQL::type('ProductCategory'),
                'description' => 'The product category',
                'resolve' => fn (Product $product) => $product->category,
            ],
            'location' => [
                'type' => GraphQL::type('Location'),
                'description' => 'The product storage location',
                'resolve' => fn (Product $product) => $product->location,
            ],
            'variants' => [
                'type' => Type::listOf(GraphQL::type('ProductVariant')),
                'description' => 'Product variants',
                'resolve' => fn (Product $product) => $product->variants,
            ],
            'suppliers' => [
                'type' => Type::listOf(GraphQL::type('Supplier')),
                'description' => 'Product suppliers',
                'resolve' => fn (Product $product) => $product->suppliers,
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (Product $product) => $product->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (Product $product) => $product->updated_at?->toIso8601String(),
            ],
        ];
    }
}
