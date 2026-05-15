<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class ReorderHelperPrompt extends Prompt
{
    protected string $description = 'Walk the user through identifying low-stock products, picking suppliers, and drafting purchase orders.';

    public function arguments(): array
    {
        return [
            new Argument(
                name: 'warehouse_id',
                description: 'Optional warehouse to focus on. If omitted, all warehouses are considered.',
                required: false,
            ),
        ];
    }

    public function handle(Request $request): Response
    {
        $warehouseHint = $request->get('warehouse_id')
            ? "Restrict the low-stock check to warehouse_id={$request->get('warehouse_id')}."
            : 'Consider every warehouse.';

        return Response::text(<<<PROMPT
            You are helping the user replenish their Inventoros inventory.

            Step 1. Call the `list_low_stock` tool. {$warehouseHint}
            Step 2. Group the returned products by their preferred supplier (use `get_product` if needed to inspect the supplier list).
            Step 3. For each supplier, propose a draft purchase order with reasonable order quantities (use `reorder_quantity` when set, otherwise compute `(min_stock * 2) - stock`).
            Step 4. Show the user the planned POs in a table and **ask for confirmation** before invoking `create_purchase_order`.
            Step 5. After each PO is created, summarise what was done and offer to mark them as sent with `send_purchase_order`.

            Never invoke a tool with `IsDestructive` annotation without explicit user confirmation in the same turn.
            PROMPT);
    }
}
