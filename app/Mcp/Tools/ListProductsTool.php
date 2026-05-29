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
class ListProductsTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'List products in the authenticated organization. Supports search by name/SKU/barcode, category and warehouse filters, low-stock filter, sorting and pagination.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Match against name, SKU or barcode (substring).'),
            'category_id' => $schema->integer()->description('Restrict to a single category.'),
            'warehouse_id' => $schema->integer()->description('Products in any location belonging to this warehouse.'),
            'is_active' => $schema->boolean()->description('Only active or only inactive products.'),
            'low_stock' => $schema->boolean()->description('Only products at or below their min_stock.'),
            'sort_by' => $schema->string()->enum(['created_at', 'updated_at', 'name', 'sku', 'price', 'stock'])->description('Sort column.'),
            'sort_dir' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction (default: desc).'),
            'page' => $schema->integer()->description('1-indexed page number.'),
            'per_page' => $schema->integer()->description('Items per page (default 15, max 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_products', 'manage_products']);

        $orgId = $this->organizationId();

        $query = Product::query()
            ->with(['category', 'location'])
            ->forOrganization($orgId)
            ->when($request->get('warehouse_id'), function ($q, $warehouseId) {
                $q->whereHas('location', fn ($l) => $l->where('warehouse_id', $warehouseId));
            })
            ->when($request->get('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($request->get('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->get('is_active') !== null, fn ($q) => $q->where('is_active', (bool) $request->get('is_active')))
            ->when($request->get('low_stock'), fn ($q) => $q->lowStock());

        $allowedSort = ['created_at', 'updated_at', 'name', 'sku', 'price', 'stock'];
        $sortBy = in_array($request->get('sort_by'), $allowedSort, true) ? $request->get('sort_by') : 'created_at';
        $sortDir = $request->get('sort_dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) ($request->get('per_page') ?? 15), 100);
        $page = max((int) ($request->get('page') ?? 1), 1);

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return Response::json([
            'data' => collect($paginator->items())->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'barcode' => $p->barcode,
                'price' => $p->price,
                'selling_price' => $p->selling_price,
                'stock' => $p->stock,
                'min_stock' => $p->min_stock,
                'is_active' => (bool) $p->is_active,
                'category' => $p->category?->only(['id', 'name']),
                'location' => $p->location?->only(['id', 'name']),
                'tracking_type' => $p->tracking_type?->value ?? 'none',
            ])->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
