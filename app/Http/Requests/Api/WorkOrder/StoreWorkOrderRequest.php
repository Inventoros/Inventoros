<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new work order via the REST API. Rules unchanged from
 * Api\WorkOrderController::store; product/warehouse existence is org-scoped.
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
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('organization_id', $organizationId),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
