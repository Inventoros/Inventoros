<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class LowStockResource extends Resource
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Snapshot of every product currently at or below its min_stock for the authenticated organization. Browse this resource for an at-a-glance reorder list.';

    protected string $uri = 'inventoros://low-stock';

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        $this->authorize(['view_products']);

        $rows = Product::query()
            ->forOrganization($this->organizationId())
            ->lowStock()
            // Products sold by variant keep their stock on the variants, so
            // sort by the same effective stock lowStock() checks.
            ->orderByRaw('(min_stock - '.Product::effectiveStockSql('products').') DESC')
            ->limit(200)
            ->select(['id', 'sku', 'name', 'stock', 'min_stock', 'reorder_point', 'reorder_quantity'])
            ->withEffectiveStock()
            ->get()
            ->each(fn (Product $product) => $product->append('total_stock')->makeHidden('effective_stock'));

        return Response::json([
            'count' => $rows->count(),
            'generated_at' => now()->toIso8601String(),
            'products' => $rows->all(),
        ]);
    }
}
