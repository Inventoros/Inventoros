<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\StockAdjustment;

use App\Support\VariantLineValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a new stock adjustment via the REST API. Rules unchanged from
 * Api\StockAdjustmentController::store; product existence is org-scoped.
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
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $organizationId)],
            // Optional: adjust one variant's stock (the variant ledger) rather
            // than the product total. Must belong to product_id.
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'quantity' => ['required', 'integer'],
            'type' => ['required', 'string', 'in:manual,count,damage,return,transfer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            // Optional: apply the adjustment to a specific location bin so the
            // per-location breakdown stays in step with the total. Omitted =
            // adjust the total only (leaves the breakdown untouched).
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('product_locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Same variant checks as the web form: the variant must belong to the
     * product, and variant stock is not tracked per location.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('product_variant_id')) {
                    return;
                }

                $errors = VariantLineValidator::errors(
                    [$this->only(['product_id', 'product_variant_id'])],
                    (int) $this->user()->organization_id,
                );

                if (isset($errors[0])) {
                    $validator->errors()->add('product_variant_id', $errors[0]);
                }

                if ($this->filled('location_id')) {
                    $validator->errors()->add(
                        'location_id',
                        'Variant stock is not tracked by location. Leave the location empty for a variant.'
                    );
                }
            },
        ];
    }
}
