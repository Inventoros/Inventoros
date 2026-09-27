<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Http\Requests\Concerns\ValidatesProductSuppliers;
use App\Services\ProductService;
use Closure;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class UpdateProductMutation extends Mutation
{
    use ValidatesProductSuppliers;

    protected $attributes = [
        'name' => 'updateProduct',
        'description' => 'Update an existing product',
    ];

    public function type(): Type
    {
        return GraphQL::type('Product');
    }

    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the product to update',
                'rules' => ['required', 'integer'],
            ],
            'sku' => [
                'type' => Type::string(),
                'description' => 'Stock keeping unit',
                'rules' => ['sometimes', 'string', 'max:255'],
            ],
            'name' => [
                'type' => Type::string(),
                'description' => 'Product name',
                'rules' => ['sometimes', 'string', 'max:255'],
            ],
            'description' => [
                'type' => Type::string(),
                'description' => 'Product description',
                'rules' => ['nullable', 'string', 'max:5000'],
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
                'description' => 'Stock quantity',
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
                'rules' => ['nullable', 'string', 'max:5000'],
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
            'suppliers' => [
                'type' => Type::listOf(Type::nonNull(GraphQL::type('ProductSupplierInput'))),
                'description' => 'Supplier links. When sent, replaces the product\'s links (an empty list removes them); omit to leave them unchanged.',
            ],
        ];
    }

    /**
     * The same suppliers[] rules as the web and REST product surfaces:
     * org-scoped supplier ids, at most one primary, no duplicates.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function rules(array $args = []): array
    {
        return $this->productSupplierRules((int) auth()->user()?->organization_id);
    }

    public function resolve($root, array $args, $context, ResolveInfo $resolveInfo, Closure $getSelectFields)
    {
        $user = auth()->user();
        if (! $user->hasPermission('edit_products')) {
            throw new AuthorizationException('Unauthorized');
        }

        $organizationId = $user->organization_id;

        $product = Product::forOrganization($organizationId)->find($args['id']);

        if (! $product) {
            throw new Error('Product not found');
        }

        if (isset($args['category_id']) && ! ProductCategory::query()
            ->whereKey($args['category_id'])
            ->where('organization_id', $user->organization_id)
            ->exists()) {
            throw new Error('Category not found');
        }

        if (isset($args['location_id']) && ! ProductLocation::query()
            ->whereKey($args['location_id'])
            ->where('organization_id', $user->organization_id)
            ->exists()) {
            throw new Error('Location not found');
        }

        $updateData = collect($args)->except(['id'])->toArray();
        // Route through the shared ProductService for consistent handling
        // (currency normalisation, and variants left untouched when omitted).
        $product = app(ProductService::class)->update($product, $updateData);
        $product->load(['category', 'location']);

        return $product;
    }
}
