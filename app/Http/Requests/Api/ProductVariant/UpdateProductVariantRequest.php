<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ProductVariant;

use App\Services\ProductService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a product variant update via the REST API. Rules unchanged from
 * Api\ProductVariantController::update (option_values is `sometimes`).
 */
final class UpdateProductVariantRequest extends FormRequest
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
            'option_values' => ['sometimes', 'array'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            // Variant stock moves only through the ledger (adjust-stock).
            'stock' => ['prohibited'],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stock.prohibited' => ProductService::STOCK_EDIT_MESSAGE,
        ];
    }
}
