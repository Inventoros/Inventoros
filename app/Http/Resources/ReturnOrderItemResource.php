<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order\ReturnOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReturnOrderItem
 */
class ReturnOrderItemResource extends JsonResource
{
    /**
     * Transform the return order item resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_item_id' => $this->order_item_id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'condition' => $this->condition,
            'restock' => $this->restock,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
            ] : null),
        ];
    }
}
