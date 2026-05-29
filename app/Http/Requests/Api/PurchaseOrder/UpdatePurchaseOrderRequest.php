<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a purchase order update via the REST API. Rules unchanged from
 * Api\PurchaseOrderController::update (header fields + items are `sometimes`).
 */
final class UpdatePurchaseOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'supplier_id' => ['sometimes', Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)],
            'order_date' => ['sometimes', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            // parent purchase_order is already org-scoped via route-model binding
            'items.*.id' => ['nullable', 'exists:purchase_order_items,id'],
            'items.*.product_id' => ['required_with:items', Rule::exists('products', 'id')->where('organization_id', $organizationId)],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:255'],
        ];
    }
}
