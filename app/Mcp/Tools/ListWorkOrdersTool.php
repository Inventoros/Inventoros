<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\WorkOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListWorkOrdersTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'List manufacturing work orders. Filter by status or search by WO number / product.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Substring of WO number, product name, or SKU.'),
            'status' => $schema->string()->enum(['draft', 'pending', 'in_progress', 'completed', 'cancelled'])->description('Restrict to a status.'),
            'sort_by' => $schema->string()->enum(['created_at', 'updated_at', 'work_order_number', 'status', 'quantity'])->description('Sort column.'),
            'sort_dir' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction (default desc).'),
            'page' => $schema->integer()->description('1-indexed page.'),
            'per_page' => $schema->integer()->description('Default 15, max 100.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['manage_stock']);

        $query = WorkOrder::with(['product:id,name,sku', 'creator:id,name'])
            ->forOrganization($this->organizationId())
            ->when($request->get('search'), function ($q, $s) {
                $q->where(function ($q) use ($s) {
                    $q->where('work_order_number', 'like', "%{$s}%")
                        ->orWhereHas('product', function ($q2) use ($s) {
                            $q2->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%");
                        });
                });
            })
            ->when($request->get('status'), fn ($q, $s) => $q->byStatus($s));

        $allowed = ['created_at', 'updated_at', 'work_order_number', 'status', 'quantity'];
        $sortBy = in_array($request->get('sort_by'), $allowed, true) ? $request->get('sort_by') : 'created_at';
        $sortDir = $request->get('sort_dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) ($request->get('per_page') ?? 15), 100);
        $page = max((int) ($request->get('page') ?? 1), 1);

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return Response::json([
            'data' => collect($paginator->items())->map(fn (WorkOrder $w) => [
                'id' => $w->id,
                'work_order_number' => $w->work_order_number,
                'status' => $w->status,
                'quantity' => $w->quantity,
                'quantity_produced' => $w->quantity_produced,
                'product' => $w->product?->only(['id', 'name', 'sku']),
                'creator' => $w->creator?->only(['id', 'name']),
                'started_at' => $w->started_at?->toIso8601String(),
                'completed_at' => $w->completed_at?->toIso8601String(),
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
