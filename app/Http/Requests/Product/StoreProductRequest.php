<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new product created through the web (Inertia) surface.
 *
 * Rules are unchanged from the previous inline validation in
 * Inventory\ProductController::store, including the
 * `product_store_validation_rules` plugin filter so plugins can still adjust
 * the ruleset.
 */
final class StoreProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        // Hook: Modify validation rules before validation
        return apply_filters('product_store_validation_rules', [
            'type' => 'sometimes|string|in:standard,kit,assembly',
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->where('organization_id', $organizationId)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'currency' => 'required|string|max:3',
            'price_in_currencies' => 'nullable|array',
            'price_in_currencies.*.currency' => 'required|string|max:3',
            'price_in_currencies.*.price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'reorder_point' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'barcode' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'category_id' => ['nullable', Rule::exists('product_categories', 'id')->where('organization_id', $organizationId)],
            'location_id' => ['nullable', Rule::exists('product_locations', 'id')->where('organization_id', $organizationId)],
            'is_active' => 'boolean',
            'images' => 'nullable|array|max:5',
            'images.*.file' => 'nullable',
            'images.*.preview' => 'nullable|string',
            'images.*.name' => 'nullable|string',
            'has_variants' => 'boolean',
            'tracking_type' => 'nullable|string|in:none,batch,serial',
            'options' => 'nullable|array|max:3',
            'options.*.name' => 'required_with:options|string|max:255',
            'options.*.values' => 'required_with:options|array|min:1',
            'options.*.values.*' => 'string|max:255',
            'variants' => 'nullable|array',
            'variants.*.option_values' => 'required_with:variants|array',
            'variants.*.sku' => 'nullable|string|max:255',
            'variants.*.barcode' => 'nullable|string|max:255',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.purchase_price' => 'nullable|numeric|min:0',
            'variants.*.stock' => 'nullable|integer|min:0',
            'variants.*.min_stock' => 'nullable|integer|min:0',
            'variants.*.is_active' => 'boolean',
        ], $this);
    }
}
