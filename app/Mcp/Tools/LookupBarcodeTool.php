<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Services\ScanLookupService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class LookupBarcodeTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'lookup_barcode';

    protected string $description = 'Look up a scanned code: a product or variant by exact barcode/SKU/UPC, a product QR deep link, or a storage location by its QR code (LOC:<code>) or location code. Same resolution as the in-app scanner.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->required()->min(1)->description('Exact barcode, SKU, UPC, product link, or location code (LOC:<code>).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_products']);

        $request->validate(['code' => ['required', 'string', 'min:1', 'max:2048']]);

        $code = (string) $request->get('code');
        $scans = app(ScanLookupService::class);
        $match = $scans->resolve((int) $this->organizationId(), $code);

        if ($match !== null && $match['type'] === 'location') {
            return Response::json(['match' => 'location'] + $scans->locationSummary($match['location']));
        }

        if ($match !== null && $match['variant'] !== null) {
            $variant = $match['variant'];

            return Response::json([
                'match' => 'variant',
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'stock' => $variant->stock,
                'price' => $variant->price,
                'product' => $match['product']->only(['id', 'name']),
            ]);
        }

        if ($match !== null) {
            $product = $match['product'];

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

        return Response::error("No product or variant found for code [{$code}] in this organization, and no storage location matches it.");
    }
}
