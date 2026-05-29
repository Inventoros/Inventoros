<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class CreateProductTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Create a new product in the catalog. Always confirm SKU, name, and starting stock with the user before invoking.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'sku' => $schema->string()->required()->description('Stock keeping unit, must be unique within the organization.'),
            'name' => $schema->string()->required()->description('Product display name.'),
            'description' => $schema->string()->description('Long description.'),
            'price' => $schema->number()->description('Display/list price.'),
            'selling_price' => $schema->number()->description('Sell price (defaults to price).'),
            'purchase_price' => $schema->number()->description('Default purchase cost.'),
            'currency' => $schema->string()->description('ISO 4217 currency, default USD.'),
            'stock' => $schema->integer()->description('Starting on-hand units (default 0).'),
            'min_stock' => $schema->integer()->description('Reorder warning threshold.'),
            'max_stock' => $schema->integer()->description('Maximum desired stock.'),
            'barcode' => $schema->string()->description('UPC/EAN/SKU barcode.'),
            'category_id' => $schema->integer()->description('Optional category id.'),
            'location_id' => $schema->integer()->description('Optional default location id.'),
            'is_active' => $schema->boolean()->description('Default true.'),
            'tracking_type' => $schema->string()->enum(['none', 'batch', 'serial'])->description('How units are tracked.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['manage_products']);

        $orgId = $this->organizationId();

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('organization_id', $orgId)],
            'location_id' => ['nullable', 'integer', Rule::exists('product_locations', 'id')->where('organization_id', $orgId)],
            'is_active' => ['nullable', 'boolean'],
            'tracking_type' => ['nullable', 'string', 'in:none,batch,serial'],
        ]);

        $validated['organization_id'] = $orgId;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['stock'] = $validated['stock'] ?? 0;
        $validated['tracking_type'] = $validated['tracking_type'] ?? 'none';

        $product = Product::create($validated);

        return Response::json([
            'message' => 'Product created.',
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'stock' => $product->stock,
                'price' => $product->price,
                'is_active' => (bool) $product->is_active,
                'tracking_type' => $product->tracking_type?->value,
            ],
        ]);
    }
}
