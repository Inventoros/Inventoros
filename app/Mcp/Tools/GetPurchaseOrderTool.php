<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Purchasing\PurchaseOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetPurchaseOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Fetch a single purchase order with supplier and line items.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Purchase order id.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_purchase_orders', 'manage_purchase_orders']);

        $request->validate(['id' => ['required', 'integer']]);

        $po = PurchaseOrder::query()
            ->forOrganization($this->organizationId())
            ->with(['supplier', 'items'])
            ->find((int) $request->get('id'));

        if (! $po) {
            return Response::error('Purchase order not found in this organization.');
        }

        return Response::json([
            'id' => $po->id,
            'po_number' => $po->po_number,
            'status' => $po->status,
            'supplier' => $po->supplier?->only(['id', 'name', 'email']),
            'order_date' => $po->order_date?->toIso8601String(),
            'expected_date' => $po->expected_date?->toIso8601String(),
            'received_date' => $po->received_date?->toIso8601String(),
            'subtotal' => $po->subtotal,
            'tax' => $po->tax,
            'shipping' => $po->shipping,
            'total' => $po->total,
            'currency' => $po->currency,
            'notes' => $po->notes,
            'items' => $po->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product_name,
                'sku' => $i->sku,
                'supplier_sku' => $i->supplier_sku,
                'quantity_ordered' => $i->quantity_ordered,
                'quantity_received' => $i->quantity_received,
                'remaining_quantity' => $i->remaining_quantity,
                'unit_cost' => $i->unit_cost,
                'subtotal' => $i->subtotal,
                'total' => $i->total,
            ])->all(),
        ]);
    }
}
