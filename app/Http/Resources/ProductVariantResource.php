<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    /**
     * Transform the product variant resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'title' => $this->title,
            'option_values' => $this->option_values,
            'price' => $this->price,
            'purchase_price' => $this->purchase_price,
            'compare_at_price' => $this->compare_at_price,
            'effective_price' => $this->effective_price,
            'effective_purchase_price' => $this->effective_purchase_price,
            'stock' => $this->stock,
            'min_stock' => $this->min_stock,
            'image' => $this->image,
            'weight' => $this->weight,
            'weight_unit' => $this->weight_unit,
            'is_active' => $this->is_active,
            'requires_shipping' => $this->requires_shipping,
            'position' => $this->position,
            'metadata' => $this->metadata,
            'is_low_stock' => $this->isLowStock(),
            'is_out_of_stock' => $this->isOutOfStock(),
            'is_on_sale' => $this->isOnSale(),
            'discount_percentage' => $this->discount_percentage,
            'profit' => $this->profit,
            'profit_margin' => $this->profit_margin,
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
