<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListPendingApprovalsTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'list_pending_approvals';

    protected string $description = 'List the purchase orders, stock adjustment requests, stock transfers and sales orders waiting for the caller\'s approval. Each item has a type (purchase_order, stock_adjustment, stock_transfer, sales_order) and id to pass to decide_approval. Requests the caller raised themselves are left out unless they may approve their own.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $items = app(ApprovalService::class)->pendingFor($this->user());

        return Response::json([
            'count' => $items->count(),
            'items' => $items->all(),
        ]);
    }
}
