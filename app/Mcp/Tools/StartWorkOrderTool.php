<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\BusinessRuleException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\WorkOrder;
use App\Services\WorkOrderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class StartWorkOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'start_work_order';

    protected string $description = 'Move a draft or pending work order into "in_progress". Validates that every component has sufficient stock (and enough available serials or batch quantity when tracked) before starting; fails fast otherwise.';

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

        // The same checks the web and REST surfaces run: status under a row
        // lock, variant and kit lines, stock and tracked serials / batches.
        try {
            app(WorkOrderService::class)->start($wo);
        } catch (BusinessRuleException $e) {
            return Response::error($e->getMessage());
        }

        $wo->refresh();

        return Response::json([
            'message' => 'Work order started.',
            'id' => $wo->id,
            'work_order_number' => $wo->work_order_number,
            'status' => $wo->status,
            'started_at' => $wo->started_at?->toIso8601String(),
        ]);
    }
}
