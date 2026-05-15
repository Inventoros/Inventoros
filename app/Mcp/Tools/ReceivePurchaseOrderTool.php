<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ReceivePurchaseOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Receive items against a sent purchase order. WARNING: this writes stock for every received line. Pass an explicit list of items with quantity_to_receive — partial receipts are allowed and the PO transitions to "partial" or "received" automatically.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Purchase order id.'),
            'items' => $schema->array()->required()->description('Items to receive: [{id: po_item_id, quantity_to_receive: int}]. Quantities must be >= 0.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['receive_purchase_orders', 'manage_purchase_orders']);

        $orgId = $this->organizationId();

        $po = PurchaseOrder::query()
            ->forOrganization($orgId)
            ->find((int) $request->get('id'));

        if (! $po) {
            return Response::error('Purchase order not found in this organization.');
        }

        if (! $po->canReceiveItems()) {
            return Response::error("Purchase order in status [{$po->status}] cannot receive items.");
        }

        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => [
                'required',
                'integer',
                Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $po->id),
            ],
            'items.*.quantity_to_receive' => ['required', 'integer', 'min:0'],
        ]);

        $received = 0;

        DB::transaction(function () use ($validated, $po, &$received) {
            foreach ($validated['items'] as $itemData) {
                if ($itemData['quantity_to_receive'] <= 0) {
                    continue;
                }

                $item = PurchaseOrderItem::query()
                    ->where('id', $itemData['id'])
                    ->where('purchase_order_id', $po->id)
                    ->first();

                if ($item && $item->remaining_quantity > 0) {
                    $item->receive((int) $itemData['quantity_to_receive']);
                    $received++;
                }
            }
        });

        if ($received === 0) {
            return Response::error('No items were received (all quantities zero or already fully received).');
        }

        $po->refresh();

        return Response::json([
            'message' => "Received {$received} line(s).",
            'id' => $po->id,
            'po_number' => $po->po_number,
            'status' => $po->status,
        ]);
    }
}
