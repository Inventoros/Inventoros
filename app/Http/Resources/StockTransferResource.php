<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Inventory\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockTransfer
 */
class StockTransferResource extends JsonResource
{
    /**
     * Transform the stock transfer resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = fn ($loc) => $loc ? ['id' => $loc->id, 'name' => $loc->name, 'code' => $loc->code] : null;

        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'status' => $this->status,
            'from_location_id' => $this->from_location_id,
            'to_location_id' => $this->to_location_id,
            'from_warehouse_id' => $this->from_warehouse_id,
            'to_warehouse_id' => $this->to_warehouse_id,
            'is_inter_warehouse' => $this->is_inter_warehouse,
            'shipping_method' => $this->shipping_method,
            'tracking_number' => $this->tracking_number,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'estimated_arrival' => $this->estimated_arrival?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'from_location' => $this->whenLoaded('fromLocation', fn () => $location($this->fromLocation)),
            'to_location' => $this->whenLoaded('toLocation', fn () => $location($this->toLocation)),
            'transferred_by' => $this->whenLoaded('transferredBy', fn () => $this->transferredBy ? [
                'id' => $this->transferredBy->id,
                'name' => $this->transferredBy->name,
            ] : null),
            'items' => StockTransferItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
