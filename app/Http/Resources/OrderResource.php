<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order\Order;
use App\Support\PaymentVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the order resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'invoice_number' => $this->invoice_number,
            'invoice_issued_at' => $this->invoice_issued_at?->toIso8601String(),
            'invoice_sent_at' => $this->invoice_sent_at?->toIso8601String(),
            'invoice_sent_to' => $this->invoice_sent_to,
            'invoice_queued_at' => $this->invoice_queued_at?->toIso8601String(),
            'source' => $this->source,
            'external_id' => $this->external_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_address' => $this->customer_address,
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'approval_notes' => $this->approval_notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'subtotal' => $this->subtotal,
            // discount_amount is the whole discount (lines + order level), so
            // subtotal - discount_amount + tax + shipping = total.
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'line_discount_total' => $this->whenLoaded('items', fn () => $this->resource->lineDiscountTotal()),
            'order_discount_amount' => $this->whenLoaded('items', fn () => $this->resource->orderDiscountAmount()),
            'tax' => $this->tax,
            'shipping' => $this->shipping,
            'total' => $this->total,
            // Payment position is financial detail behind view_payments: absent
            // (not zero) for anyone without it.
            $this->mergeWhen($this->canViewPayments($request), fn () => [
                'amount_paid' => $this->amount_paid,
                'balance_due' => $this->resource->balanceDue(),
                'payment_status' => $this->payment_status,
                'payments' => $this->whenLoaded('payments', fn () => OrderPaymentResource::collection($this->payments)->resolve($request)),
            ]),
            'currency' => $this->currency,
            'order_date' => $this->order_date?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            // Resolved to plain arrays here: Inertia turns a nested resource
            // collection prop into a {data: [...]} envelope, which the order
            // pages (reading order.items as a list) could not iterate.
            'items' => $this->whenLoaded('items', fn () => OrderItemResource::collection($this->items)->resolve($request)),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function canViewPayments(Request $request): bool
    {
        return PaymentVisibility::allows($request->user());
    }
}
