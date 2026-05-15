<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class CreateOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Create a sales order. WARNING: this decrements stock for every line item; the call fails entirely if any item is short. Always confirm the customer, currency, and item list with the user first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_name' => $schema->string()->required()->description('Customer or company name.'),
            'customer_email' => $schema->string()->description('Customer email (optional).'),
            'customer_address' => $schema->string()->description('Free-text shipping address.'),
            'currency' => $schema->string()->description('ISO 4217 currency code, default USD.'),
            'source' => $schema->string()->description('Source label, default "mcp".'),
            'status' => $schema->string()->enum(['pending', 'processing', 'shipped', 'delivered', 'cancelled'])->description('Initial status, default pending.'),
            'order_date' => $schema->string()->description('ISO date, default today.'),
            'notes' => $schema->string()->description('Internal notes.'),
            'items' => $schema->array()->required()->description('Line items: [{product_id, quantity, unit_price?, tax?}]. Must be non-empty.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['manage_orders']);

        $orgId = $this->organizationId();

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_address' => ['nullable', 'string', 'max:5000'],
            'currency' => ['nullable', 'string', 'max:3'],
            'source' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:pending,processing,shipped,delivered,cancelled'],
            'order_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $orgId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = DB::transaction(function () use ($validated, $orgId) {
            $order = Order::create([
                'organization_id' => $orgId,
                'order_number' => Order::generateOrderNumber($orgId),
                'source' => $validated['source'] ?? 'mcp',
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'] ?? null,
                'customer_address' => $validated['customer_address'] ?? null,
                'status' => $validated['status'] ?? 'pending',
                'currency' => $validated['currency'] ?? 'USD',
                'order_date' => $validated['order_date'] ?? now(),
                'notes' => $validated['notes'] ?? null,
                'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0,
            ]);

            $subtotal = 0.0;
            $totalTax = 0.0;

            foreach ($validated['items'] as $itemData) {
                $product = Product::query()
                    ->forOrganization($orgId)
                    ->lockForUpdate()
                    ->find($itemData['product_id']);

                if (! $product || $product->stock < $itemData['quantity']) {
                    $available = $product?->stock ?? 0;
                    $name = $product?->name ?? "ID {$itemData['product_id']}";
                    throw new \RuntimeException(
                        "Insufficient stock for {$name}. Available: {$available}, requested: {$itemData['quantity']}"
                    );
                }

                $unitPrice = (float) ($itemData['unit_price'] ?? $product->selling_price ?? $product->price ?? 0);
                $itemSubtotal = $unitPrice * $itemData['quantity'];
                $itemTax = (float) ($itemData['tax'] ?? 0);

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                    'tax' => $itemTax,
                    'total' => $itemSubtotal + $itemTax,
                ]);

                $product->decrement('stock', $itemData['quantity']);
                $subtotal += $itemSubtotal;
                $totalTax += $itemTax;
            }

            $order->update([
                'subtotal' => $subtotal,
                'tax' => $totalTax,
                'total' => $subtotal + $totalTax,
            ]);

            return $order->fresh('items');
        });

        return Response::json([
            'message' => 'Order created.',
            'order' => [
                'id' => $result->id,
                'order_number' => $result->order_number,
                'status' => $result->status,
                'currency' => $result->currency,
                'total' => $result->total,
                'item_count' => $result->items->count(),
            ],
        ]);
    }
}
