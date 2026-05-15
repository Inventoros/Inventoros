<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\WorkOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class StartWorkOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Move a draft or pending work order into "in_progress". Validates that all components have sufficient stock before starting; fails fast otherwise.';

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
            ->with('items.product')
            ->find((int) $request->get('id'));

        if (! $wo) {
            return Response::error('Work order not found in this organization.');
        }

        if (! in_array($wo->status, ['draft', 'pending'], true)) {
            return Response::error("Only draft or pending work orders can be started (current status: {$wo->status}).");
        }

        foreach ($wo->items as $item) {
            $remaining = $item->quantity_required - $item->quantity_consumed;
            if ($item->product && $item->product->stock < $remaining) {
                return Response::error(
                    "Insufficient stock for component '{$item->product->name}': "
                    ."available {$item->product->stock}, required {$remaining}."
                );
            }
        }

        $wo->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return Response::json([
            'message' => 'Work order started.',
            'id' => $wo->id,
            'work_order_number' => $wo->work_order_number,
            'status' => $wo->status,
            'started_at' => $wo->started_at?->toIso8601String(),
        ]);
    }
}
