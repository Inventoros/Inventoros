<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\Inventory\ProductVariant;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class ProductVariantsQuery extends Query
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'productVariants',
        'description' => 'List product variants, optionally for one product',
    ];

    protected function permissions(): array
    {
        return ['view_products'];
    }

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('ProductVariant'));
    }

    public function args(): array
    {
        return [
            'product_id' => ['type' => Type::int(), 'description' => 'Only variants of this product'],
            'search' => ['type' => Type::string(), 'description' => 'Search by SKU, barcode or title'],
            'is_active' => ['type' => Type::boolean(), 'description' => 'Filter by active status'],
            'limit' => ['type' => Type::int(), 'description' => 'Maximum number of results (default: 50, max: 100)'],
        ];
    }

    public function resolve($root, array $args)
    {
        // product_variants carries its own organization_id and has no global
        // scope, so the tenant filter here is the only one.
        return ProductVariant::with('product')
            ->where('organization_id', $this->organizationId())
            ->when($args['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($args['search'] ?? null, function ($query, $search) {
                $query->where(fn ($q) => $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%"));
            })
            ->when(isset($args['is_active']), fn ($query) => $query->where('is_active', $args['is_active']))
            ->orderBy('product_id')
            ->orderBy('position')
            ->limit(max(1, min($args['limit'] ?? 50, 100)))
            ->get();
    }
}
