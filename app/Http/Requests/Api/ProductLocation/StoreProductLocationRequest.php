<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ProductLocation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new product location via the REST API. Rules unchanged from
 * Api\ProductLocationController::store.
 */
final class StoreProductLocationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'aisle' => ['nullable', 'string', 'max:255'],
            'shelf' => ['nullable', 'string', 'max:255'],
            'bin' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            // Optional: the warehouse this location belongs to (same org).
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $this->user()->organization_id)->whereNull('deleted_at')],
        ];
    }
}
