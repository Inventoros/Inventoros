<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class LookupBarcodeTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Look up a product or variant by exact barcode/SKU/UPC. Used by handheld scanners and AI agents that need to identify a single physical item.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->required()->min(1)->description('Exact barcode, SKU, or UPC string.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_products', 'manage_products']);

        $request->validate(['code' => ['required', 'string', 'min:1', 'max:255']]);

        $orgId = $this->organizationId();
        $code = (string) $request->get('code');

        $product = Product::query()
            ->forOrganization($orgId)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->first();

        if ($product) {
            return Response::json([
                'match' => 'product',
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'stock' => $product->stock,
                'price' => $product->price,
                'selling_price' => $product->selling_price,
            ]);
        }

        $variant = ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->forOrganization($orgId))
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->with('product:id,name,organization_id')
            ->first();

        if ($variant) {
            return Response::json([
                'match' => 'variant',
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'stock' => $variant->stock,
                'price' => $variant->price,
                'product' => $variant->product?->only(['id', 'name']),
            ]);
        }

        return Response::error("No product or variant found for code [{$code}] in this organization.");
    }
}
