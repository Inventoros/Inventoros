<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Services\OrderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
            'items' => $schema->array()->required()->description('Line items: [{product_id, product_variant_id?, quantity, unit_price?, tax?}]. product_variant_id is required for variant-tracked products. Must be non-empty.'),
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
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('organization_id', $orgId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['status'] ??= 'pending';
        $validated['order_date'] ??= now();
        $validated['currency'] ??= 'USD';

        // OrderService owns the create invariant (lock → validate → ledger +
        // decrement, wrapped in SequenceNumberRetry) shared with the
        // web/REST/GraphQL surfaces. It throws InsufficientStockException
        // (a RuntimeException) which the MCP framework surfaces as a tool
        // error — same as the previous inline RuntimeException.
        $order = app(OrderService::class)->create(
            $validated,
            $this->user(),
            $validated['source'] ?? 'mcp'
        );

        $result = $order->fresh('items');

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
