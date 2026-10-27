<?php

declare(strict_types=1);

namespace App\Http\Requests\StockAudit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new stock audit (web surface). Rules unchanged from the previous
 * inline validation in Inventory\StockAuditController::store.
 */
final class StoreStockAuditRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'audit_type' => 'required|in:full,cycle,spot',
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $this->user()->organization_id)->whereNull('deleted_at')],
            'warehouse_location_id' => 'nullable|exists:product_locations,id',
            'notes' => 'nullable|string|max:2000',
            'require_variance_approval' => ['sometimes', 'boolean'],
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ];
    }
}
