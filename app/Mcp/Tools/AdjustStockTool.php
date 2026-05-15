<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAdjustment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AdjustStockTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Adjust the on-hand stock of a product by a positive or negative integer, recording the reason. WARNING: this writes to inventory. Always confirm the product id and quantity with the user before invoking. Use type "manual" for plain corrections, "count" for cycle-count adjustments, "damage" for write-offs, "return" for customer returns, "transfer" for inter-warehouse moves.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Product id within the caller\'s organization.'),
            'quantity' => $schema->integer()->required()->description('Signed delta. Positive adds stock, negative removes it.'),
            'type' => $schema->string()->required()->enum(['manual', 'count', 'damage', 'return', 'transfer'])->description('Reason category.'),
            'reason' => $schema->string()->description('Short human label (e.g. "Cycle count Q2", max 255 chars).'),
            'notes' => $schema->string()->description('Free-text notes for the audit log.'),
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
        ]);

        $product = Product::query()
            ->forOrganization($this->organizationId())
            ->find($validated['product_id']);

        if (! $product) {
            return Response::error('Product not found in this organization.');
        }

        if ($validated['quantity'] < 0 && abs($validated['quantity']) > $product->stock) {
            return Response::error("Cannot remove {$validated['quantity']} units; only {$product->stock} on hand.");
        }

        $adjustment = DB::transaction(fn () => StockAdjustment::adjust(
            $product,
            $validated['quantity'],
            $validated['type'],
            $validated['reason'] ?? null,
            $validated['notes'] ?? null,
        ));

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
