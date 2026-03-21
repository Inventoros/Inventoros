<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Inventory\ProductCategory;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class ProductCategoryType extends GraphQLType
{
    protected $attributes = [
        'name' => 'ProductCategory',
        'description' => 'A product category',
        'model' => ProductCategory::class,
    ];

    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the category',
            ],
            'name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Category name',
            ],
            'slug' => [
                'type' => Type::string(),
                'description' => 'URL-friendly slug',
            ],
            'description' => [
                'type' => Type::string(),
                'description' => 'Category description',
            ],
            'parent_id' => [
                'type' => Type::int(),
                'description' => 'Parent category ID',
            ],
            'is_active' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the category is active',
            ],
            'parent' => [
                'type' => GraphQL::type('ProductCategory'),
                'description' => 'Parent category',
                'resolve' => fn (ProductCategory $category) => $category->parent,
            ],
            'children' => [
                'type' => Type::listOf(GraphQL::type('ProductCategory')),
                'description' => 'Child categories',
                'resolve' => fn (ProductCategory $category) => $category->children,
            ],
            'products_count' => [
                'type' => Type::int(),
                'description' => 'Number of products in this category',
                'resolve' => fn (ProductCategory $category) => $category->products()->count(),
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (ProductCategory $category) => $category->created_at?->toIso8601String(),
            ],
            'updated_at' => [
                'type' => Type::string(),
                'description' => 'Last update timestamp',
                'resolve' => fn (ProductCategory $category) => $category->updated_at?->toIso8601String(),
            ],
        ];
    }
}
