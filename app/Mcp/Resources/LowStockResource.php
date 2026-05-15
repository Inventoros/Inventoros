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
        $this->authorize(['view_products', 'manage_products']);

        $rows = Product::query()
            ->forOrganization($this->organizationId())
            ->lowStock()
            ->orderByRaw('(min_stock - stock) DESC')
            ->limit(200)
            ->get(['id', 'sku', 'name', 'stock', 'min_stock', 'reorder_point', 'reorder_quantity']);

        return Response::json([
            'count' => $rows->count(),
            'generated_at' => now()->toIso8601String(),
            'products' => $rows->all(),
        ]);
    }
}
