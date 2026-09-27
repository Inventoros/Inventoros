<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\StockAudit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new stock audit via the REST API. Same fields as the web form,
 * but the location and product ids must belong to the caller's organization.
 */
final class StoreStockAuditRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'audit_type' => ['required', 'in:full,cycle,spot'],
            'warehouse_location_id' => ['nullable', 'integer', Rule::exists('product_locations', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'product_ids' => ['nullable', 'array', 'max:5000'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
        ];
    }
}
