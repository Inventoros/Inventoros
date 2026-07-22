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

            // Variants, options, and base64 images are processed by
            // ProductService (shared with the web surface). Omitting variants
            // leaves any existing ones untouched.
            'images' => ['nullable', 'array', 'max:5'],
            'images.*.preview' => ['nullable', 'string'],
            'images.*.name' => ['nullable', 'string'],
            'has_variants' => ['boolean'],
            'options' => ['nullable', 'array', 'max:3'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required_with:options', 'string', 'max:255'],
            'options.*.values' => ['required_with:options', 'array', 'min:1'],
            'options.*.values.*' => ['string', 'max:255'],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.option_values' => ['required_with:variants', 'array'],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.barcode' => ['nullable', 'string', 'max:255'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.min_stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['boolean'],
        ];
    }
}
