<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\InvalidStateException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\WorkOrder;
use App\Services\WorkOrderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteWorkOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Delete a draft or cancelled work order. Work orders that have started or completed (and so moved stock) cannot be deleted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Work order id.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['manage_stock']);

        $request->validate(['id' => ['required', 'integer']]);

        $wo = WorkOrder::query()
            ->forOrganization($this->organizationId())
            ->find((int) $request->get('id'));

        if (! $wo) {
            return Response::error('Work order not found in this organization.');
        }

        try {
            app(WorkOrderService::class)->delete($wo);
        } catch (InvalidStateException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'message' => "Work order {$wo->work_order_number} deleted.",
            'id' => $wo->id,
        ]);
    }
}
