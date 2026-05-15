<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchProductsTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Find a small set of products by free-text query. Optimized for the AI to look up "what is this thing" without paginating. Returns up to 10 matches with id, sku, name, stock and price.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->min(1)->description('Substring to match against name, SKU, or barcode.'),
            'limit' => $schema->integer()->description('Max results (default 10, max 25).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_products', 'manage_products']);

        $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $query = (string) $request->get('query');
        $limit = min((int) ($request->get('limit') ?? 10), 25);

        $products = Product::query()
            ->forOrganization($this->organizationId())
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'sku', 'name', 'barcode', 'stock', 'price', 'selling_price', 'min_stock']);

        return Response::json([
            'count' => $products->count(),
            'matches' => $products->all(),
        ]);
    }
}
