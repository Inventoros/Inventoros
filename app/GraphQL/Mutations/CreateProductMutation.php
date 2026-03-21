<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Inventory\Product;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class CreateProductMutation extends Mutation
{
    protected $attributes = [
        'name' => 'createProduct',
        'description' => 'Create a new product',
    ];

    public function type(): Type
    {
        return GraphQL::type('Product');
    }

    public function args(): array
    {
        return [
            'sku' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Stock keeping unit',
                'rules' => ['required', 'string', 'max:255'],
            ],
            'name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Product name',
                'rules' => ['required', 'string', 'max:255'],
            ],
            'description' => [
                'type' => Type::string(),
                'description' => 'Product description',
                'rules' => ['nullable', 'string'],
            ],
            'price' => [
                'type' => Type::float(),
                'description' => 'Product base price',
                'rules' => ['nullable', 'numeric', 'min:0'],
            ],
            'selling_price' => [
                'type' => Type::float(),
                'description' => 'Selling price',
                'rules' => ['nullable', 'numeric', 'min:0'],
            ],
            'purchase_price' => [
                'type' => Type::float(),
                'description' => 'Purchase/cost price',
                'rules' => ['nullable', 'numeric', 'min:0'],
            ],
            'currency' => [
                'type' => Type::string(),
                'description' => 'Currency code',
                'rules' => ['nullable', 'string', 'max:3'],
            ],
            'stock' => [
                'type' => Type::int(),
                'description' => 'Initial stock quantity',
                'rules' => ['nullable', 'integer', 'min:0'],
            ],
            'min_stock' => [
                'type' => Type::int(),
                'description' => 'Minimum stock threshold',
                'rules' => ['nullable', 'integer', 'min:0'],
            ],
            'max_stock' => [
                'type' => Type::int(),
                'description' => 'Maximum stock level',
                'rules' => ['nullable', 'integer', 'min:0'],
            ],
            'barcode' => [
                'type' => Type::string(),
                'description' => 'Barcode value',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'notes' => [
                'type' => Type::string(),
                'description' => 'Additional notes',
                'rules' => ['nullable', 'string'],
            ],
            'category_id' => [
                'type' => Type::int(),
                'description' => 'Category ID',
                'rules' => ['nullable', 'integer', 'exists:product_categories,id'],
            ],
            'location_id' => [
                'type' => Type::int(),
                'description' => 'Location ID',
                'rules' => ['nullable', 'integer', 'exists:product_locations,id'],
            ],
            'is_active' => [
                'type' => Type::boolean(),
                'description' => 'Whether the product is active',
                'rules' => ['nullable', 'boolean'],
            ],
            'tracking_type' => [
                'type' => Type::string(),
                'description' => 'Tracking type: none, batch, or serial',
                'rules' => ['nullable', 'string', 'in:none,batch,serial'],
            ],
        ];
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        if (!$user->hasPermission('create_products')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Unauthorized');
        }

        $args['organization_id'] = $user->organization_id;
        $args['is_active'] = $args['is_active'] ?? true;
        $args['stock'] = $args['stock'] ?? 0;
        $args['tracking_type'] = $args['tracking_type'] ?? 'none';

        $product = Product::create($args);
        $product->load(['category', 'location']);

        return $product;
    }
}
