<?php

declare(strict_types=1);

namespace App\Http\Requests\StockAdjustment;

use App\Support\VariantLineValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a new stock adjustment (web surface). location_id is an optional
 * addition so an adjustment can target a specific location bin; the rest is
 * unchanged from the previous inline validation in the controller.
 *
 * product_variant_id targets a variant (picked, or resolved from a scanned
 * variant barcode). A product sold by variant needs one, it must belong to
 * the product, and it cannot be combined with a location: variant stock has
 * no per-location breakdown.
 */
final class StoreStockAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'product_id' => 'required|exists:products,id',
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'type' => 'required|in:manual,recount,damage,loss,return,correction',
            'adjustment_quantity' => 'required|integer|not_in:0',
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('product_locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = VariantLineValidator::errors(
                    [$this->only(['product_id', 'product_variant_id'])],
                    (int) $this->user()->organization_id,
                );

                if (isset($errors[0])) {
                    $validator->errors()->add('product_variant_id', $errors[0]);
                }

                if ($this->filled('product_variant_id') && $this->filled('location_id')) {
                    $validator->errors()->add(
                        'location_id',
                        'Variant stock is not tracked by location. Leave the location empty for a variant.'
                    );
                }
            },
        ];
    }
}
