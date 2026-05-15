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
class ListLowStockTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'List products at or below their min_stock threshold for the authenticated organization. The agent uses this to suggest reorders.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'warehouse_id' => $schema->integer()->description('Restrict to one warehouse.'),
            'limit' => $schema->integer()->description('Max results (default 50, max 200).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_products', 'manage_products']);

        $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = min((int) ($request->get('limit') ?? 50), 200);

        $products = Product::query()
            ->forOrganization($this->organizationId())
            ->lowStock()
            ->when($request->get('warehouse_id'), function ($q, $warehouseId) {
                $q->whereHas('location', fn ($l) => $l->where('warehouse_id', $warehouseId));
            })
            ->orderByRaw('(min_stock - stock) DESC')
            ->limit($limit)
            ->get(['id', 'sku', 'name', 'stock', 'min_stock', 'reorder_point', 'reorder_quantity', 'location_id']);

        return Response::json([
            'count' => $products->count(),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'stock' => $p->stock,
                'min_stock' => $p->min_stock,
                'reorder_point' => $p->reorder_point,
                'reorder_quantity' => $p->reorder_quantity,
                'shortage' => max(0, ($p->min_stock ?? 0) - $p->stock),
                'location_id' => $p->location_id,
            ])->all(),
        ]);
    }
}
