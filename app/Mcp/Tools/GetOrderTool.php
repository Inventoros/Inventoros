<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Order\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $description = 'Fetch a single order with all line items.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Order id.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['view_orders', 'manage_orders']);

        $request->validate(['id' => ['required', 'integer']]);

        $order = Order::query()
            ->forOrganization($this->organizationId())
            ->with('items')
            ->find((int) $request->get('id'));

        if (! $order) {
            return Response::error('Order not found in this organization.');
        }

        return Response::json([
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_address' => $order->customer_address,
            'status' => $order->status,
            'subtotal' => $order->subtotal,
            'tax' => $order->tax,
            'shipping' => $order->shipping,
            'total' => $order->total,
            'currency' => $order->currency,
            'order_date' => $order->order_date?->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'notes' => $order->notes,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
                'tax' => $item->tax,
                'total' => $item->total,
            ])->all(),
        ]);
    }
}
