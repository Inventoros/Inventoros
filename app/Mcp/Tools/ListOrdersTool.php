<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Order\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListOrdersTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'List sales orders with optional filters (status, source, warehouse, date range, free-text customer search). Returns paginated results.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Match against order number, customer name or email.'),
            'status' => $schema->string()->enum(['pending', 'processing', 'shipped', 'delivered', 'cancelled'])->description('Restrict to a status.'),
            'warehouse_id' => $schema->integer()->description('Restrict to one warehouse.'),
            'source' => $schema->string()->description('Restrict to one source (e.g. "api", "web").'),
            'date_from' => $schema->string()->description('ISO date inclusive lower bound on order_date.'),
            'date_to' => $schema->string()->description('ISO date inclusive upper bound on order_date.'),
            'sort_by' => $schema->string()->enum(['created_at', 'updated_at', 'order_number', 'customer_name', 'total', 'status', 'order_date'])->description('Sort column.'),
            'sort_dir' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction (default: desc).'),
            'page' => $schema->integer()->description('1-indexed page.'),
            'per_page' => $schema->integer()->description('Default 15, max 100.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_orders', 'manage_orders']);

        $orgId = $this->organizationId();

        $query = Order::withCount('items')
            ->forOrganization($orgId)
            ->when($request->get('warehouse_id'), fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->get('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%");
                });
            })
            ->when($request->get('status'), fn ($q, $status) => $q->byStatus($status))
            ->when($request->get('source'), fn ($q, $source) => $q->bySource($source))
            ->when($request->get('date_from'), fn ($q, $d) => $q->where('order_date', '>=', $d))
            ->when($request->get('date_to'), fn ($q, $d) => $q->where('order_date', '<=', $d));

        $allowed = ['created_at', 'updated_at', 'order_number', 'customer_name', 'total', 'status', 'order_date'];
        $sortBy = in_array($request->get('sort_by'), $allowed, true) ? $request->get('sort_by') : 'created_at';
        $sortDir = $request->get('sort_dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) ($request->get('per_page') ?? 15), 100);
        $page = max((int) ($request->get('page') ?? 1), 1);

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return Response::json([
            'data' => collect($paginator->items())->map(fn (Order $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'customer_name' => $o->customer_name,
                'customer_email' => $o->customer_email,
                'status' => $o->status,
                'total' => $o->total,
                'currency' => $o->currency,
                'item_count' => $o->items_count ?? 0,
                'order_date' => $o->order_date?->toIso8601String(),
                'created_at' => $o->created_at?->toIso8601String(),
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
