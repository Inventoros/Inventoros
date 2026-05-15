<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Supplier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListSuppliersTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'List suppliers (vendors) for the authenticated organization. Filter by active flag, search by name or code.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Substring of name or code.'),
            'is_active' => $schema->boolean()->description('Restrict to active or inactive.'),
            'limit' => $schema->integer()->description('Max results (default 50, max 200).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_suppliers', 'manage_suppliers']);

        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $limit = min((int) ($request->get('limit') ?? 50), 200);

        $suppliers = Supplier::query()
            ->where('organization_id', $this->organizationId())
            ->when($request->get('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->get('is_active') !== null, fn ($q) => $q->where('is_active', (bool) $request->get('is_active')))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'contact_name', 'email', 'phone', 'currency', 'is_active']);

        return Response::json([
            'count' => $suppliers->count(),
            'suppliers' => $suppliers->all(),
        ]);
    }
}
