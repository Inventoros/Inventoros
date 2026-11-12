<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Shipping\Shipment;
use App\Services\Documents\FulfillmentDocumentService;
use App\Services\WarehouseAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class FulfillmentDocumentController extends Controller
{
    public function __construct(
        private readonly FulfillmentDocumentService $documents,
        private readonly WarehouseAccessService $warehouses,
    ) {}

    public function pickList(Request $request, Order $order): Response
    {
        $this->authorizeOrder($request, $order);

        return $this->documents->pickList($order)->stream("order-{$order->id}-pick-list.pdf");
    }

    public function packingSlip(Request $request, Shipment $shipment): Response
    {
        abort_unless($shipment->organization_id === $request->user()->organization_id, 403);
        $this->warehouses->authorizeWarehouse($request->user(), $shipment->warehouse_id);
        $order = $shipment->order;
        abort_unless($order !== null, 404);
        $this->authorizeOrder($request, $order);

        return $this->documents->packingSlip($shipment)->stream("shipment-{$shipment->id}-packing-slip.pdf");
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless($order->organization_id === $request->user()->organization_id, 403);
        $this->warehouses->authorizeWarehouse($request->user(), $order->warehouse_id);
    }
}
