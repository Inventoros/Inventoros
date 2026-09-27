<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\ShipmentStatus;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Shipping\Shipment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListShipmentsTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'list_shipments';

    protected string $description = 'List shipments (carrier, tracking number and link, status, packed lines), optionally for one order or status. Returns paginated results, newest first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->integer()->description('Restrict to one sales order.'),
            'status' => $schema->string()->enum(ShipmentStatus::values())->description('Restrict to a status.'),
            'page' => $schema->integer()->description('1-indexed page.'),
            'per_page' => $schema->integer()->description('Default 15, max 100.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_shipments']);

        $perPage = min(max((int) ($request->get('per_page') ?? 15), 1), 100);
        $page = max((int) ($request->get('page') ?? 1), 1);

        $paginator = Shipment::where('organization_id', $this->organizationId())
            ->with('items.orderItem', 'warehouse')
            ->when($request->get('order_id'), fn ($q, $id) => $q->where('order_id', $id))
            ->when($request->get('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(perPage: $perPage, page: $page);

        return Response::json([
            'data' => collect($paginator->items())->map(fn (Shipment $s) => $s->toPublicArray())->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
