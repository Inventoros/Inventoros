<?php

declare(strict_types=1);

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a purchase order edit (web surface). supplier_id and product_id are
 * org-scoped so an edit can't point a PO at another tenant's supplier/product
 * (the web store path re-fetched via forOrganization, but update wrote the id
 * directly — a cross-tenant supplier could then be read via a PO report).
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
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)],
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'currency' => 'required|string|max:3',
            'shipping' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:purchase_order_items,id',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('organization_id', $organizationId)],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.supplier_sku' => 'nullable|string|max:255',
        ];
    }
}
