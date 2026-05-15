<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class CreatePurchaseOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Create a draft purchase order for a supplier. The PO is created in "draft" status and does NOT affect stock until it is later sent and received. Always confirm supplier and item list before invoking.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'supplier_id' => $schema->integer()->required()->description('Supplier id within the caller\'s organization.'),
            'order_date' => $schema->string()->required()->description('ISO date the PO is dated.'),
            'expected_date' => $schema->string()->description('ISO date items are expected.'),
            'currency' => $schema->string()->required()->description('ISO 4217 currency code.'),
            'shipping' => $schema->number()->description('Shipping cost (default 0).'),
            'tax' => $schema->number()->description('Tax total (default 0).'),
            'notes' => $schema->string()->description('Internal notes.'),
            'items' => $schema->array()->required()->description('Line items: [{product_id, quantity, unit_cost, supplier_sku?}].'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['manage_purchase_orders']);

        $orgId = $this->organizationId();

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('organization_id', $orgId)],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency' => ['required', 'string', 'max:3'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $orgId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:255'],
        ]);

        $po = DB::transaction(function () use ($validated, $orgId) {
            $subtotal = 0.0;
            $rows = [];
            foreach ($validated['items'] as $item) {
                $product = Product::forOrganization($orgId)->find($item['product_id']);
                $itemSubtotal = $item['quantity'] * (float) $item['unit_cost'];
                $subtotal += $itemSubtotal;
                $rows[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'supplier_sku' => $item['supplier_sku'] ?? null,
                    'quantity_ordered' => $item['quantity'],
                    'quantity_received' => 0,
                    'unit_cost' => $item['unit_cost'],
                    'subtotal' => $itemSubtotal,
                    'tax' => 0,
                    'total' => $itemSubtotal,
                ];
            }

            $po = PurchaseOrder::create([
                'organization_id' => $orgId,
                'supplier_id' => $validated['supplier_id'],
                'created_by' => $this->user()->id,
                'po_number' => PurchaseOrder::generatePONumber($orgId),
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'subtotal' => $subtotal,
                'tax' => $validated['tax'] ?? 0,
                'shipping' => $validated['shipping'] ?? 0,
                'total' => $subtotal + ($validated['tax'] ?? 0) + ($validated['shipping'] ?? 0),
                'currency' => $validated['currency'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $po->items()->createMany($rows);

            return $po->fresh(['supplier', 'items']);
        });

        return Response::json([
            'message' => 'Draft purchase order created.',
            'purchase_order' => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'status' => $po->status,
                'supplier' => $po->supplier?->only(['id', 'name']),
                'total' => $po->total,
                'currency' => $po->currency,
                'item_count' => $po->items->count(),
            ],
        ]);
    }
}
