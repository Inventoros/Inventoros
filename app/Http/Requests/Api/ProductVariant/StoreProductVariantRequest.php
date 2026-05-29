<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ProductVariant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new product variant via the REST API. Rules unchanged from
 * Api\ProductVariantController::store.
 */
final class StoreProductVariantRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'option_values' => ['required', 'array'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'in:kg,lb,oz,g'],
            'is_active' => ['nullable', 'boolean'],
            'requires_shipping' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
