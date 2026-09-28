<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CustomerContact;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnOrder;
use App\Models\Scopes\OrganizationScope;
use App\Support\Portal\PortalContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Base for signed-in portal pages.
 *
 * Tenancy here never relies on the staff OrganizationScope, which keys off
 * whoever the default guard's user is. Every query is built from the
 * contact: organization_id AND customer_id, explicitly, with the global
 * scope removed so the result does not depend on guard state. Anything not
 * matched is a 404, never a 403, so the portal does not confirm that another
 * customer's record exists.
 */
abstract class PortalController extends Controller
{
    protected function contact(Request $request): CustomerContact
    {
        return PortalContext::contact($request);
    }

    /**
     * Orders belonging to the contact's customer in the contact's organization.
     *
     * @return Builder<Order>
     */
    protected function orders(CustomerContact $contact): Builder
    {
        return Order::withoutGlobalScope(OrganizationScope::class)
            ->where('orders.organization_id', $contact->organization_id)
            ->where('orders.customer_id', $contact->customer_id);
    }

    protected function findOrder(CustomerContact $contact, int|string $id): Order
    {
        if (! ctype_digit((string) $id)) {
            abort(404);
        }

        return $this->orders($contact)->whereKey((int) $id)->firstOrFail();
    }

    /**
     * Returns raised against the contact's customer's orders.
     *
     * @return Builder<ReturnOrder>
     */
    protected function returns(CustomerContact $contact): Builder
    {
        return ReturnOrder::query()
            ->where('return_orders.organization_id', $contact->organization_id)
            ->whereIn('return_orders.order_id', $this->orders($contact)->select('orders.id'));
    }

    /**
     * Whether the contact may download the order's invoice: never for a
     * cancelled order; otherwise once staff have issued it, or once the
     * order is being fulfilled. Pending orders are not invoiced from the
     * portal (generating an invoice allocates its number).
     */
    protected function invoiceAvailable(Order $order): bool
    {
        if ($order->status === OrderStatus::CANCELLED) {
            return false;
        }

        if (filled($order->invoice_number)) {
            return true;
        }

        return in_array($order->status, [OrderStatus::PROCESSING, OrderStatus::SHIPPED, OrderStatus::DELIVERED], true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderSummary(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status?->value,
            'order_date' => $order->order_date?->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'total' => $order->total,
            'currency' => $order->currency,
            'invoice_number' => $order->invoice_number,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function returnSummary(ReturnOrder $returnOrder): array
    {
        return [
            'id' => $returnOrder->id,
            'return_number' => $returnOrder->return_number,
            'type' => $returnOrder->type,
            'status' => $returnOrder->status,
            'refund_amount' => $returnOrder->refund_amount,
            'created_at' => $returnOrder->created_at?->toIso8601String(),
            'completed_at' => $returnOrder->completed_at?->toIso8601String(),
            'order' => $returnOrder->relationLoaded('order') && $returnOrder->order ? [
                'id' => $returnOrder->order->id,
                'order_number' => $returnOrder->order->order_number,
                'currency' => $returnOrder->order->currency,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lineSummary(OrderItem $item, int $alreadyReturned): array
    {
        return [
            'id' => $item->id,
            'product_name' => $item->product_name,
            'variant_title' => $item->product_variant_id !== null ? $item->variant?->title : null,
            'sku' => $item->sku,
            'quantity' => (int) $item->quantity,
            'unit_price' => $item->unit_price,
            'subtotal' => $item->subtotal,
            'discount_amount' => $item->discount_amount,
            'tax' => $item->tax,
            'total' => $item->total,
            'returned_quantity' => $alreadyReturned,
            'returnable_quantity' => max(0, (int) $item->quantity - $alreadyReturned),
        ];
    }
}
