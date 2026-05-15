<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetProductTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Fetch a single product by id with category, location, suppliers, options, and active variants. Returns 404-style error if the product is not in the caller\'s organization.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Product id.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_products', 'manage_products']);

        $request->validate(['id' => ['required', 'integer']]);

        try {
            $product = Product::query()
                ->forOrganization($this->organizationId())
                ->with(['category', 'location', 'suppliers', 'options', 'activeVariants'])
                ->findOrFail((int) $request->get('id'));
        } catch (ModelNotFoundException) {
            return Response::error('Product not found in this organization.');
        }

        return Response::json([
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'barcode' => $product->barcode,
            'price' => $product->price,
            'selling_price' => $product->selling_price,
            'purchase_price' => $product->purchase_price,
            'currency' => $product->currency,
            'stock' => $product->stock,
            'total_stock' => $product->total_stock,
            'min_stock' => $product->min_stock,
            'max_stock' => $product->max_stock,
            'reorder_point' => $product->reorder_point,
            'reorder_quantity' => $product->reorder_quantity,
            'is_active' => (bool) $product->is_active,
            'has_variants' => (bool) $product->has_variants,
            'tracking_type' => $product->tracking_type ?? 'none',
            'category' => $product->category?->only(['id', 'name']),
            'location' => $product->location?->only(['id', 'name', 'warehouse_id']),
            'suppliers' => $product->suppliers->map(fn ($s) => $s->only(['id', 'name']))->all(),
            'options' => $product->options->map(fn ($o) => $o->only(['id', 'name', 'values']))->all(),
            'active_variants' => $product->activeVariants->map(fn ($v) => $v->only(['id', 'sku', 'stock', 'price']))->all(),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ]);
    }
}
