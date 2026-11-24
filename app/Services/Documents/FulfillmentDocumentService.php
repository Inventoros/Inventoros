<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Inventory\OrderItemKitComponent;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Read-only warehouse documents. These deliberately do not use the invoice
 * renderer, assign invoice numbers, or pass financial/private order fields
 * to a customer-facing document.
 */
final class FulfillmentDocumentService
{
    public function canPrintPickList(Order $order): bool
    {
        return $order->stock_committed
            && in_array($order->status, [OrderStatus::PENDING, OrderStatus::PROCESSING], true)
            && in_array($order->approval_status, [OrderApprovalStatus::APPROVED, OrderApprovalStatus::NOT_REQUIRED], true);
    }

    public function pickList(Order $order): DomPdf
    {
        return Pdf::loadView('pdf.fulfillment', $this->pickListData($order))->setPaper('a4');
    }

    /** @return array<string, mixed> */
    public function pickListData(Order $order): array
    {
        abort_unless($this->canPrintPickList($order), 409, __('This order is not ready for picking.'));

        $order->loadMissing(['organization', 'warehouse']);

        // Pending/labelled shipments still need picking. Only units that have
        // physically left reduce this list; cancelled parcels never do.
        $shipped = ShipmentItem::query()
            ->whereHas('shipment', fn ($query) => $query
                ->where('order_id', $order->id)
                ->where('organization_id', $order->organization_id)
                ->leftWarehouse())
            ->selectRaw('order_item_id, SUM(quantity) as shipped_quantity')
            ->groupBy('order_item_id')
            ->pluck('shipped_quantity', 'order_item_id');

        // A kit line is picked as its components: what the sale took, from
        // the line's snapshot (deleted components included), never the
        // current BOM.
        $lines = $order->items()->orderBy('id')
            ->with([
                'kitComponents' => fn ($query) => $query->orderBy('id'),
                'kitComponents.componentProduct' => fn ($query) => $query->withTrashed(),
            ])
            ->get()
            ->map(function (OrderItem $item) use ($shipped) {
                $line = [
                    'name' => $item->product_name,
                    'sku' => $item->sku,
                    // Units still on backorder never left stock, so they are not
                    // on the shelf to pick yet.
                    'quantity' => max(0, $item->quantity_fulfilled - (int) ($shipped[$item->id] ?? 0)),
                ];
                // Only kit lines carry `components`: each one's quantity for
                // the kits still to pick on this document.
                if ($item->kitComponents->isNotEmpty()) {
                    $line['components'] = $item->kitComponents
                        ->map(fn (OrderItemKitComponent $row) => [
                            'name' => $row->componentProduct?->name,
                            'sku' => $row->componentProduct?->sku,
                            'quantity' => (int) $row->quantity_per_kit * $line['quantity'],
                        ])
                        ->values()->all();
                }

                return $line;
            })
            ->filter(fn (array $line) => $line['quantity'] > 0)
            ->values()->all();

        abort_if($lines === [], 409, __('There are no items left to pick.'));

        return [
            'isPickList' => true,
            'organizationName' => $order->organization?->name,
            'orderNumber' => $order->order_number,
            'orderDate' => $order->order_date?->format('Y-m-d'),
            'warehouseName' => $order->warehouse?->name,
            'customerName' => $order->customer_name,
            'shipmentId' => null,
            'addressLines' => [],
            'carrier' => null,
            'trackingNumber' => null,
            'lines' => $lines,
        ];
    }

    public function packingSlip(Shipment $shipment): DomPdf
    {
        return Pdf::loadView('pdf.fulfillment', $this->packingSlipData($shipment))->setPaper('a4');
    }

    /** @return array<string, mixed> */
    public function packingSlipData(Shipment $shipment): array
    {
        abort_if($shipment->status === ShipmentStatus::CANCELLED, 409, __('Cancelled shipments do not have packing slips.'));

        $shipment->loadMissing(['order.organization', 'items.orderItem']);
        $order = $shipment->order;
        abort_unless($order !== null && $order->organization_id === $shipment->organization_id, 404);

        $lines = $shipment->items->map(function (ShipmentItem $item) use ($order) {
            $line = $item->orderItem;
            abort_unless($line !== null && $line->order_id === $order->id, 409, __('A shipment item is unavailable.'));

            return ['name' => $line->product_name, 'sku' => $line->sku, 'quantity' => $item->quantity];
        })->all();

        return [
            'isPickList' => false,
            'organizationName' => $order->organization?->name,
            'orderNumber' => $order->order_number,
            'orderDate' => $order->order_date?->format('Y-m-d'),
            'warehouseName' => null,
            'customerName' => null,
            'shipmentId' => $shipment->id,
            // The parcel's address is a snapshot. Do not replace it with the
            // customer's current address or include email/phone/extra keys.
            'addressLines' => $this->addressLines($shipment->to_address ?? []),
            'carrier' => implode(' ', array_filter([$shipment->carrier_name, $shipment->service])),
            'trackingNumber' => $shipment->tracking_number,
            'lines' => $lines,
        ];
    }

    /** @param array<string, mixed> $address
     * @return array<int, string>
     */
    private function addressLines(array $address): array
    {
        $value = fn (string $key) => is_string($address[$key] ?? null) ? trim($address[$key]) : '';

        return array_values(array_filter([
            $value('name'), $value('company'), $value('street1'), $value('street2'),
            implode(' ', array_filter([$value('city'), $value('state'), $value('zip')])),
            $value('country'),
        ], fn (string $line) => $line !== ''));
    }
}
