<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Purchasing\PurchaseOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListPurchaseOrdersTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'List purchase orders. Filter by status, supplier, or free-text search on PO number / supplier name.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('PO number or supplier name substring.'),
            'status' => $schema->string()->enum(['draft', 'sent', 'partial', 'received', 'cancelled'])->description('Restrict to a status.'),
            'supplier_id' => $schema->integer()->description('Restrict to one supplier.'),
            'sort_by' => $schema->string()->enum(['created_at', 'updated_at', 'order_date', 'po_number', 'status', 'total'])->description('Sort column.'),
            'sort_dir' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction (default desc).'),
            'page' => $schema->integer()->description('1-indexed page.'),
            'per_page' => $schema->integer()->description('Default 15, max 100.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_purchase_orders', 'manage_purchase_orders']);

        $query = PurchaseOrder::with(['supplier'])
            ->withCount('items')
            ->forOrganization($this->organizationId())
            ->when($request->get('search'), fn ($q, $s) => $q->search($s))
            ->when($request->get('status'), fn ($q, $s) => $q->byStatus($s))
            ->when($request->get('supplier_id'), fn ($q, $id) => $q->bySupplier($id));

        $allowed = ['created_at', 'updated_at', 'order_date', 'po_number', 'status', 'total'];
        $sortBy = in_array($request->get('sort_by'), $allowed, true) ? $request->get('sort_by') : 'order_date';
        $sortDir = $request->get('sort_dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) ($request->get('per_page') ?? 15), 100);
        $page = max((int) ($request->get('page') ?? 1), 1);

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return Response::json([
            'data' => collect($paginator->items())->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'status' => $po->status,
                'supplier' => $po->supplier?->only(['id', 'name']),
                'order_date' => $po->order_date?->toIso8601String(),
                'expected_date' => $po->expected_date?->toIso8601String(),
                'total' => $po->total,
                'currency' => $po->currency,
                'item_count' => $po->items_count ?? 0,
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
