<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\ProductLocation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListLocationsTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'List storage locations (bins, shelves, zones) for the authenticated organization. Filterable by warehouse.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'warehouse_id' => $schema->integer()->description('Restrict to one warehouse.'),
            'limit' => $schema->integer()->description('Max results (default 100, max 500).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_locations', 'manage_locations', 'view_products', 'manage_products']);

        $limit = min((int) ($request->get('limit') ?? 100), 500);

        $locations = ProductLocation::query()
            ->where('organization_id', $this->organizationId())
            ->when($request->get('warehouse_id'), fn ($q, $id) => $q->where('warehouse_id', $id))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'description', 'warehouse_id']);

        return Response::json([
            'count' => $locations->count(),
            'locations' => $locations->all(),
        ]);
    }
}
