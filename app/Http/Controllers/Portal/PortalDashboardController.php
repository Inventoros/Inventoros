<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\OrderStatus;
use App\Models\Order\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portal landing page: a few counts and the latest orders.
 */
class PortalDashboardController extends PortalController
{
    public function __invoke(Request $request): Response
    {
        $contact = $this->contact($request);

        $openStatuses = [OrderStatus::PENDING->value, OrderStatus::PROCESSING->value, OrderStatus::SHIPPED->value];

        return Inertia::render('Portal/Dashboard', [
            'stats' => [
                'orders' => $this->orders($contact)->count(),
                'open_orders' => $this->orders($contact)->whereIn('status', $openStatuses)->count(),
                'open_returns' => $this->returns($contact)->whereIn('status', ['pending', 'approved', 'received'])->count(),
            ],
            'recentOrders' => $this->orders($contact)
                ->latest('order_date')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Order $order) => $this->orderSummary($order))
                ->values(),
        ]);
    }
}
