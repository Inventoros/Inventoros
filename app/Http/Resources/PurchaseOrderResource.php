<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Purchasing\PurchaseOrder;
use App\Support\ExchangeMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * Transform the purchase order resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'order_date' => $this->order_date?->format('Y-m-d'),
            'expected_date' => $this->expected_date?->format('Y-m-d'),
            'received_date' => $this->received_date?->format('Y-m-d'),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'sent_to' => $this->sent_to,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'approval_status' => $this->approval_status,
            'approval_notes' => $this->approval_notes,
            'approval_requested_at' => $this->approval_requested_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'needs_approval' => $this->status === 'draft' && $this->needsApproval(),
            'subtotal' => $this->subtotal,
            // The line taxes plus any PO-level tax.
            'tax' => $this->tax,
            // Taxable amount and tax per line tax rate, then one entry with
            // a null rate for tax entered as an amount. converted_taxable and
            // converted_tax are in the organization currency.
            'tax_breakdown' => $this->whenLoaded('items', fn () => $this->resource->taxBreakdown()),
            'shipping' => $this->shipping,
            'total' => $this->total,
            'currency' => $this->currency,
            // Exchange rate snapshot (1 exchange_rate_from = exchange_rate
            // exchange_rate_to, the organization currency). Null rate on a
            // PO in the organization's currency, or a foreign-currency PO
            // with no rate recorded.
            'organization_currency' => $this->organizationCurrency(),
            'exchange_rate' => $this->exchange_rate !== null ? ExchangeMath::rate((string) $this->exchange_rate) : null,
            'exchange_rate_from' => $this->exchange_rate_from,
            'exchange_rate_to' => $this->exchange_rate_to,
            'exchange_rate_provider' => $this->exchange_rate_provider,
            'exchange_rate_effective_date' => $this->exchange_rate_effective_date?->format('Y-m-d'),
            'exchange_rate_retrieved_at' => $this->exchange_rate_retrieved_at?->toIso8601String(),
            'exchange_rate_source' => $this->exchange_rate_source,
            'exchange_rate_set_by' => $this->exchange_rate_set_by,
            'exchange_rate_locked_at' => $this->exchange_rate_locked_at?->toIso8601String(),
            'converted_subtotal' => $this->converted_subtotal,
            'converted_tax' => $this->converted_tax,
            'converted_shipping' => $this->converted_shipping,
            'converted_total' => $this->converted_total,
            'organization_currency_total' => $this->organizationCurrencyTotal(),
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'can_be_edited' => $this->canBeEdited(),
            'can_be_sent' => $this->canBeSent(),
            'can_be_submitted_for_approval' => $this->canBeSubmittedForApproval(),
            'can_receive_items' => $this->canReceiveItems(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'is_fully_received' => $this->when($this->relationLoaded('items'), fn () => $this->isFullyReceived()),
            'is_partially_received' => $this->when($this->relationLoaded('items'), fn () => $this->isPartiallyReceived()),
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->when(isset($this->items_count), $this->items_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
