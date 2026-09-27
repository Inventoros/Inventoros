<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order\ReturnOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReturnOrder
 */
class ReturnOrderResource extends JsonResource
{
    /**
     * Transform the return order resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'order_id' => $this->order_id,
            'type' => $this->type,
            'status' => $this->status,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'refund_amount' => $this->refund_amount,
            'processed_by' => $this->processed_by,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'customer_name' => $this->order->customer_name,
            ] : null),
            'processor' => $this->whenLoaded('processor', fn () => $this->processor ? [
                'id' => $this->processor->id,
                'name' => $this->processor->name,
            ] : null),
            'items' => ReturnOrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
