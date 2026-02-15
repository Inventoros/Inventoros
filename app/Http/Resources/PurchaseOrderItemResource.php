<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PurchaseOrderItem
 */
class PurchaseOrderItemResource extends JsonResource
{
    /**
     * Transform the purchase order item resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'sku' => $this->sku,
            'supplier_sku' => $this->supplier_sku,
            'quantity_ordered' => $this->quantity_ordered,
            'quantity_received' => $this->quantity_received,
            'remaining_quantity' => $this->remaining_quantity,
            'is_fully_received' => $this->isFullyReceived(),
            'unit_cost' => $this->unit_cost,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'total' => $this->total,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
