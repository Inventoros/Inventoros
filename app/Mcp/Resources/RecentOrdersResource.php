<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Order\Order;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class RecentOrdersResource extends Resource
{
    use AuthenticatesMcpRequest;

    protected string $description = 'The 25 most recent sales orders for the authenticated organization, with status and totals. Useful for "what just happened?" questions.';

    protected string $uri = 'inventoros://orders/recent';

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        $this->authorize(['view_orders', 'manage_orders']);

        $orders = Order::query()
            ->forOrganization($this->organizationId())
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['id', 'order_number', 'customer_name', 'status', 'total', 'currency', 'created_at']);

        return Response::json([
            'count' => $orders->count(),
            'generated_at' => now()->toIso8601String(),
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'customer_name' => $o->customer_name,
                'status' => $o->status,
                'total' => $o->total,
                'currency' => $o->currency,
                'item_count' => $o->items_count ?? 0,
                'created_at' => $o->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
