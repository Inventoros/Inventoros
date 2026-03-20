<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\StockAudit
 */
class StockAuditResource extends JsonResource
{
    /**
     * Transform the stock audit resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'audit_number' => $this->audit_number,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'audit_type' => $this->audit_type,
            'warehouse_location_id' => $this->warehouse_location_id,
            'warehouse_location' => $this->whenLoaded('warehouseLocation', function () {
                return [
                    'id' => $this->warehouseLocation->id,
                    'name' => $this->warehouseLocation->name,
                    'code' => $this->warehouseLocation->code,
                ];
            }),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ];
            }),
            'items_count' => $this->when(isset($this->items_count), $this->items_count),
            'items' => StockAuditItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
