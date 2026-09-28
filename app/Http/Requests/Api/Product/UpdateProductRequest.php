<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Product;

use App\Enums\BarcodeType;
use App\Http\Requests\Concerns\ValidatesProductSuppliers;
use App\Rules\BarcodeMatchesType;
use App\Services\ProductService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a product update via the REST API. Rules unchanged from the
 * previous inline validation in Api\ProductController::update (sku/name are
 * `sometimes`).
 */
final class UpdateProductRequest extends FormRequest
{
    use ValidatesProductSuppliers;

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
            // On-hand stock only moves through the audited ledger; a product
            // edit that wrote it straight to the row could put sold units back.
            'stock' => ['prohibited'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:255', new BarcodeMatchesType($this->exists('barcode_type') ? $this->input('barcode_type') : $this->route('product')?->barcode_type)],
            'barcode_type' => ['nullable', Rule::enum(BarcodeType::class)],
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
            // Opening stock for a NEW variant (no id) is booked as a ledger
            // row; an existing variant's stock cannot be set here.
            'variants.*.stock' => ['nullable', 'integer', 'min:0', function (string $attribute, mixed $value, \Closure $fail): void {
                $index = explode('.', $attribute)[1] ?? null;
                if ($index !== null && ! empty($this->input("variants.{$index}.id"))) {
                    $fail(ProductService::STOCK_EDIT_MESSAGE);
                }
            }],
            'variants.*.min_stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['boolean'],
        ] + $this->productSupplierRules($organizationId);
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
