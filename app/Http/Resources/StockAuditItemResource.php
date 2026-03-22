<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\StockAuditItem
 */
class StockAuditItemResource extends JsonResource
{
    /**
     * Transform the stock audit item resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_audit_id' => $this->stock_audit_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'location_id' => $this->location_id,
            'system_quantity' => $this->system_quantity,
            'counted_quantity' => $this->counted_quantity,
            'discrepancy' => $this->discrepancy,
            'status' => $this->status,
            'counted_by' => $this->counted_by,
            'counted_at' => $this->counted_at?->toIso8601String(),
            'notes' => $this->notes,
            'product' => new ProductResource($this->whenLoaded('product')),
            'variant' => $this->whenLoaded('variant'),
            'location' => $this->whenLoaded('location', function () {
                return [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                    'code' => $this->location->code,
                ];
            }),
            'counted_by_user' => $this->whenLoaded('countedByUser', function () {
                return [
                    'id' => $this->countedByUser->id,
                    'name' => $this->countedByUser->name,
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
