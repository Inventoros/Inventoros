<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a product update via the REST API. Rules unchanged from the
 * previous inline validation in Api\ProductController::update (sku/name are
 * `sometimes`).
 */
final class UpdateProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'sku' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('organization_id', $organizationId)],
            'location_id' => ['nullable', 'integer', Rule::exists('product_locations', 'id')->where('organization_id', $organizationId)],
            'is_active' => ['nullable', 'boolean'],
            'tracking_type' => ['nullable', 'string', 'in:none,batch,serial'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
