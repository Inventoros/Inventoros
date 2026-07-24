<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ProductComponent;

use App\Models\Inventory\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates adding a component to a kit/assembly via the REST API. Rules
 * unchanged from Api\ProductComponentController::store: the component product
 * must belong to the org, must not be the parent itself, and must be unique
 * within the parent. The deeper circular-reference check stays in the
 * controller.
 */
final class StoreProductComponentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'component_product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('organization_id', $organizationId),
                function ($attribute, $value, $fail) use ($product) {
                    if ((int) $value === $product->id) {
                        $fail('A product cannot be a component of itself.');
                    }
                },
                function ($attribute, $value, $fail) use ($organizationId) {
                    // Variant-tracked products keep stock on ProductVariant, but
                    // work-order consumption decrements the parent's (unused)
                    // stock — forbid them as components until variant-level BOM
                    // support lands.
                    $component = Product::where('id', $value)
                        ->where('organization_id', $organizationId)
                        ->first();
                    if ($component && $component->has_variants) {
                        $fail('Variant-tracked products cannot be used as components.');
                    }
                },
                Rule::unique('product_components')
                    ->where('parent_product_id', $product->id),
            ],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
