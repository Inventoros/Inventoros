<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\ProductCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListCategoriesTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'List product categories for the authenticated organization. Used by the agent before creating products or filtering lists.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Max results (default 100, max 500).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_categories', 'manage_categories', 'view_products', 'manage_products']);

        $limit = min((int) ($request->get('limit') ?? 100), 500);

        $categories = ProductCategory::query()
            ->where('organization_id', $this->organizationId())
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'description', 'parent_id']);

        return Response::json([
            'count' => $categories->count(),
            'categories' => $categories->all(),
        ]);
    }
}
