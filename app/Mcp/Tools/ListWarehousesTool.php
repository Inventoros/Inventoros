<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Warehouse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListWarehousesTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'List warehouses for the authenticated organization.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Max results (default 100, max 500).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_warehouses', 'manage_warehouses', 'view_products', 'manage_products']);

        $limit = min((int) ($request->get('limit') ?? 100), 500);

        $warehouses = Warehouse::query()
            ->where('organization_id', $this->organizationId())
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'address_line_1', 'city', 'province', 'country', 'is_default', 'is_active']);

        return Response::json([
            'count' => $warehouses->count(),
            'warehouses' => $warehouses->all(),
        ]);
    }
}
