<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Inventory\ProductOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductOption
 */
class ProductOptionResource extends JsonResource
{
    /**
     * Transform the product option resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'values' => $this->values,
            'value_count' => $this->value_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
