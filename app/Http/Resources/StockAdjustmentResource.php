<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'quantity_before' => $this->quantity_before,
            'quantity_after' => $this->quantity_after,
            'adjustment_quantity' => $this->adjustment_quantity,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
