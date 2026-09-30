<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShippingSetting;
use App\Models\User;
use App\Models\Warehouse;

/**
 * The shipping props for the order detail page: the order's shipments and
 * what the "Ship" modal needs (lines with remaining quantities, carriers,
 * warehouses, default addresses and parcel). Both are null for users without
 * view_shipments, so the page hides the section entirely.
 */
final class OrderShippingPanel
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly CarrierManager $carriers,
    ) {}

    /**
     * @return array{shipments: array<int, array<string, mixed>>|null, shipping: array<string, mixed>|null}
     */
    public function forOrder(Order $order, User $user): array
    {
        if (! $user->hasPermission('view_shipments')) {
            return ['shipments' => null, 'shipping' => null];
        }

        $organizationId = (int) $order->organization_id;
        $settings = ShippingSetting::forOrganization($organizationId);
        $remaining = $this->shipments->remainingQuantities($order);

        $shipments = Shipment::where('order_id', $order->id)
            ->with('items.orderItem.variant', 'warehouse')
            ->latest('id')
            ->get()
            ->map(fn (Shipment $s) => $s->toPublicArray())
            ->all();

        $warehouseId = $order->warehouse_id ?? $settings->default_warehouse_id;

        return [
            'shipments' => $shipments,
            'shipping' => [
                // An order waiting for approval cannot ship (ShipmentService
                // refuses it too); the panel says why instead of offering Ship.
                'canCreate' => $user->hasPermission('create_shipments') && ! $order->isPendingApproval(),
                'awaitingApproval' => $order->isPendingApproval(),
                'carriers' => $this->carriers->available($organizationId),
                'lines' => OrderItem::where('order_id', $order->id)->with('variant')->orderBy('id')->get()
                    ->map(fn (OrderItem $item) => [
                        'order_item_id' => $item->id,
                        'product_name' => $item->product_name,
                        'variant_title' => $item->variant?->title,
                        'sku' => $item->sku,
                        'quantity' => (int) $item->quantity,
                        'remaining' => $remaining[$item->id] ?? 0,
                    ])->all(),
                'warehouses' => Warehouse::where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->toArray(),
                'defaultWarehouseId' => $warehouseId,
                'toAddress' => $this->shipments->defaultToAddress($order),
                'fromAddress' => $this->shipments->defaultFromAddress($order, $warehouseId),
                'parcel' => $settings->default_parcel ?? (object) [],
                'notifyCustomers' => (bool) $settings->notify_customers,
                'customerEmail' => $order->customer_email,
            ],
        ];
    }
}
