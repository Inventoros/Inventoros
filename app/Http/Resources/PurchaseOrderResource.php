<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * Transform the purchase order resource into an array.
     *
     * @param  Request  $request
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
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'shipping' => $this->shipping,
            'total' => $this->total,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'can_be_edited' => $this->canBeEdited(),
            'can_be_sent' => $this->canBeSent(),
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
