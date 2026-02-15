<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the product resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'selling_price' => $this->selling_price,
            'purchase_price' => $this->purchase_price,
            'currency' => $this->currency,
            'price_in_currencies' => $this->price_in_currencies,
            'stock' => $this->stock,
            'total_stock' => $this->total_stock,
            'min_stock' => $this->min_stock,
            'max_stock' => $this->max_stock,
            'barcode' => $this->barcode,
            'notes' => $this->notes,
            'image' => $this->image,
            'images' => $this->images,
            'thumbnail' => $this->thumbnail,
            'is_active' => $this->is_active,
            'has_variants' => $this->has_variants,
            'metadata' => $this->metadata,
            'is_low_stock' => $this->isLowStock(),
            'is_out_of_stock' => $this->isOutOfStock(),
            'profit' => $this->profit,
            'profit_margin' => $this->profit_margin,
            'price_range' => $this->when($this->has_variants, $this->price_range),
            'category' => new ProductCategoryResource($this->whenLoaded('category')),
            'location' => new ProductLocationResource($this->whenLoaded('location')),
            'options' => ProductOptionResource::collection($this->whenLoaded('options')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'variants_count' => $this->when($this->has_variants, $this->variants()->count()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
