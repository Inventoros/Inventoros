<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\OrderPayment;
use App\Models\Order\ReturnOrder;
use App\Models\Scopes\OrganizationScope;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentItem;
use App\Services\Documents\DocumentPdfService;
use App\Services\ReturnOrderService;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A customer's orders: list, detail and invoice PDF.
 *
 * Only customer-facing fields are sent to the page. Staff notes, metadata,
 * the creator and approval notes stay internal.
 */
class PortalOrderController extends PortalController
{
    public function index(Request $request): Response
    {
        $contact = $this->contact($request);

        $orders = $this->orders($contact)
            ->withCount('items')
            ->latest('order_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Order $order) => $this->orderSummary($order) + ['items_count' => $order->items_count]);

        return Inertia::render('Portal/Orders/Index', [
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, string $order, ReturnOrderService $returnsService): Response
    {
        $contact = $this->contact($request);
        $order = $this->findOrder($contact, $order);
        $order->load('items');

        $returned = $returnsService->returnedQuantities($order);
        $items = $order->items->map(fn (OrderItem $item) => $this->lineSummary($item, (int) $returned->get($item->id, 0)))->values();

        $returns = $this->returns($contact)
            ->where('order_id', $order->id)
            ->latest('id')
            ->get()
            ->map(fn (ReturnOrder $r) => $this->returnSummary($r))
            ->values();

        // Payments the customer made (and refunds they received). Voided rows
        // were mistakes; references and notes are internal bookkeeping.
        $payments = $order->payments()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $order->organization_id)
            ->whereNull('voided_at')
            ->orderBy('paid_at')
            ->get()
            ->map(fn (OrderPayment $payment) => [
                'id' => $payment->id,
                'type' => $payment->type?->value,
                'amount' => $payment->amount,
                'method' => $payment->method?->value,
                'method_label' => $payment->method?->label(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Portal/Orders/Show', [
            'order' => $this->orderSummary($order) + [
                'shipments' => $this->shipments($order),
                'discount_type' => $order->discount_type?->value,
                'discount_value' => $order->discount_value,
                'discount_amount' => $order->discount_amount,
                'amount_paid' => $order->amount_paid,
                'balance_due' => Money::max('0', Money::subtract($order->total, $order->amount_paid ?? '0')),
                'payment_status' => $order->payment_status?->value,
                'payments' => $payments,
                'subtotal' => $order->subtotal,
                'tax' => $order->tax,
                'shipping' => $order->shipping,
                'customer_address' => $order->customer_address,
                'items' => $items,
                'invoice_available' => $this->invoiceAvailable($order),
                'can_request_return' => $order->status === OrderStatus::DELIVERED
                    && $items->contains(fn (array $line) => $line['returnable_quantity'] > 0),
            ],
            'returns' => $returns,
        ]);
    }

    /**
     * The order's shipments as the customer should see them: carrier,
     * tracking, status, dates and packed lines. Label files and URLs, costs,
     * carrier ids, rates, raw carrier responses and warehouse addresses stay
     * internal, and cancelled shipments are left out. $order has already
     * been matched to the contact's organization and customer; the shipment
     * query repeats the organization constraint rather than trusting the
     * foreign key alone.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shipments(Order $order): array
    {
        return Shipment::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $order->organization_id)
            ->where('order_id', $order->id)
            ->where('status', '!=', ShipmentStatus::CANCELLED->value)
            ->with('items.orderItem')
            ->orderBy('id')
            ->get()
            ->map(fn (Shipment $shipment) => [
                'id' => $shipment->id,
                'carrier_name' => $shipment->carrier_name,
                'service' => $shipment->service,
                'tracking_number' => $shipment->tracking_number,
                'tracking_url' => $this->safeTrackingUrl($shipment->tracking_url),
                'status' => $shipment->status->value,
                'status_label' => $shipment->status->label(),
                'shipped_at' => $shipment->shipped_at?->toIso8601String(),
                'delivered_at' => $shipment->delivered_at?->toIso8601String(),
                'items' => $shipment->items
                    ->filter(fn (ShipmentItem $item) => $item->orderItem !== null && (int) $item->orderItem->order_id === (int) $order->id)
                    ->map(fn (ShipmentItem $item) => [
                        'product_name' => $item->orderItem->product_name,
                        'sku' => $item->orderItem->sku,
                        'quantity' => (int) $item->quantity,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Only plain web links are rendered as a tracking link.
     */
    private function safeTrackingUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * Download the order's invoice PDF, generated by the same service the
     * staff download and the invoice email use.
     */
    public function invoice(Request $request, string $order, DocumentPdfService $pdfs): HttpResponse
    {
        $contact = $this->contact($request);
        $order = $this->findOrder($contact, $order);

        abort_unless($this->invoiceAvailable($order), 404);

        $pdf = $pdfs->orderInvoice($order, forCustomerPortal: true);

        return $pdf->download($pdfs->orderInvoiceFilename($order));
    }
}
