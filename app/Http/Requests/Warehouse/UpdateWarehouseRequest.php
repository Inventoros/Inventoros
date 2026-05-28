<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehouse;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a warehouse edit (web surface). Rules unchanged from the previous
 * WarehouseController::validationRules($organizationId, $warehouse->id) used by
 * update — the unique-code rule ignores the bound warehouse.
 */
final class UpdateWarehouseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        /** @var Warehouse $warehouse */
        $warehouse = $this->route('warehouse');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($warehouse->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'max:3'],
            'is_active' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
