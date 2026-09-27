<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per-warehouse thresholds for one product, one entry per warehouse.
 * A blank field inherits the product-level value.
 */
class UpdateWarehouseStockLevelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'levels' => ['present', 'array', 'max:500'],
            'levels.*.warehouse_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
            ],
            'levels.*.min_stock' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'levels.*.reorder_point' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'levels.*.reorder_quantity' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'levels.*.max_stock' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    /**
     * A warehouse's max cannot sit below its own min or reorder point.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                foreach ((array) $this->input('levels', []) as $index => $level) {
                    $max = $level['max_stock'] ?? null;

                    if ($max === null || $max === '') {
                        continue;
                    }

                    foreach (['min_stock', 'reorder_point'] as $floor) {
                        $value = $level[$floor] ?? null;

                        if ($value !== null && $value !== '' && (int) $max < (int) $value) {
                            $validator->errors()->add("levels.{$index}.max_stock", 'Max stock must be at least the warehouse\'s '.str_replace('_', ' ', $floor).'.');

                            break;
                        }
                    }
                }
            },
        ];
    }
}
