<?php

declare(strict_types=1);

namespace App\Http\Requests\ProductLocation;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new product location (web surface). Rules unchanged from the
 * previous inline validation in Inventory\ProductLocationController::store,
 * including the org-ownership check on the optional warehouse_id.
 */
final class StoreProductLocationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'warehouse_id' => [
                'nullable',
                'exists:warehouses,id',
                function ($attribute, $value, $fail) use ($organizationId) {
                    if ($value && ! Warehouse::where('id', $value)->where('organization_id', $organizationId)->exists()) {
                        $fail('The selected warehouse does not belong to your organization.');
                    }
                },
            ],
        ];
    }
}
