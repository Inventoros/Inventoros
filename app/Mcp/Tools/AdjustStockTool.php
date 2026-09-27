<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\InsufficientStockException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Services\ApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AdjustStockTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Adjust the on-hand stock of a product by a positive or negative integer, recording the reason. WARNING: this writes to inventory. Always confirm the product id and quantity with the user before invoking. Use type "manual" for plain corrections, "count" for cycle-count adjustments, "damage" for write-offs, "return" for customer returns, "transfer" for inter-warehouse moves. When the organization requires approval for this adjustment, nothing changes yet: the result has status "pending_approval" and stock moves once an approver approves it.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Product id within the caller\'s organization.'),
            'quantity' => $schema->integer()->required()->description('Signed delta. Positive adds stock, negative removes it.'),
            'type' => $schema->string()->required()->enum(['manual', 'count', 'damage', 'return', 'transfer'])->description('Reason category.'),
            'reason' => $schema->string()->description('Short human label (e.g. "Cycle count Q2", max 255 chars).'),
            'notes' => $schema->string()->description('Free-text notes for the audit log.'),
            'location_id' => $schema->integer()->description('Location bin to apply the delta to. Required when the user is restricted to assigned warehouses.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['manage_stock', 'view_stock_adjustments']);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'type' => ['required', 'string', 'in:manual,count,damage,return,transfer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $product = Product::query()
            ->forOrganization($this->organizationId())
            ->find($validated['product_id']);

        if (! $product) {
            return Response::error('Product not found in this organization.');
        }

        $locationId = isset($validated['location_id']) ? (int) $validated['location_id'] : null;

        if ($locationId !== null && ! ProductLocation::query()->where('organization_id', $this->organizationId())->whereKey($locationId)->exists()) {
            return Response::error('Location not found in this organization.');
        }

        // A restricted user may only adjust a bin in one of their warehouses.
        $this->warehouseAccess()->authorizeLocation($this->user(), $locationId);

        if ($validated['quantity'] < 0 && abs($validated['quantity']) > $product->stock) {
            return Response::error("Cannot remove {$validated['quantity']} units; only {$product->stock} on hand.");
        }

        $approvals = app(ApprovalService::class);

        try {
            $adjustment = $approvals->submitStockAdjustment(
                user: $this->user(),
                product: $product,
                variant: null,
                quantity: (int) $validated['quantity'],
                type: $validated['type'],
                reason: $validated['reason'] ?? null,
                notes: $validated['notes'] ?? null,
                locationId: $locationId,
            );
        } catch (InsufficientStockException $e) {
            return Response::error($e->getMessage());
        }

        if ($adjustment instanceof StockAdjustmentRequest) {
            return Response::json([
                'message' => 'This adjustment needs approval. It was sent to an approver; stock has not changed yet.',
                'status' => 'pending_approval',
                'request' => $approvals->describe(ApprovalService::STOCK_ADJUSTMENT, $adjustment),
            ]);
        }

        $product->refresh();

        return Response::json([
            'message' => 'Stock adjusted.',
            'adjustment' => [
                'id' => $adjustment->id,
                'type' => $adjustment->type,
                'quantity' => $adjustment->adjustment_quantity ?? $validated['quantity'],
                'reason' => $adjustment->reason,
            ],
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'stock' => $product->stock,
            ],
        ]);
    }
}
