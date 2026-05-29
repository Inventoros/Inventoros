<?php

declare(strict_types=1);

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new work order (web surface). Rules unchanged from the previous
 * inline validation in Inventory\WorkOrderController::store; the product and
 * warehouse existence checks are scoped to the authenticated user's org.
 */
final class StoreWorkOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('organization_id', $organizationId),
            ],
            'quantity' => 'required|integer|min:1|max:999999',
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('organization_id', $organizationId),
            ],
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
