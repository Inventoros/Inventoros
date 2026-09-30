<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\DiscountType;
use App\Http\Requests\Concerns\ValidatesVariantLines;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new sales order created through the web (Inertia) surface.
 *
 * Rules are unchanged from the previous inline validation in
 * Order\OrderController::store; org-scoped existence checks read the
 * authenticated user's organization.
 */
final class StoreOrderRequest extends FormRequest
{
    use ValidatesVariantLines;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_address' => 'nullable|string',
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled',
            'order_date' => 'required|date',
            'shipping' => 'nullable|numeric|decimal:0,2|min:0',
            'tax' => 'nullable|numeric|decimal:0,2|min:0',
            'discount_type' => ['nullable', Rule::in(DiscountType::values())],
            'discount_value' => 'nullable|numeric|decimal:0,2|min:0|max:99999999',
            'notes' => 'nullable|string',
            'warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('organization_id', $organizationId)],
            'items.*.product_variant_id' => ['nullable', Rule::exists('product_variants', 'id')->where('organization_id', $organizationId)],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|decimal:0,2|min:0',
            'items.*.discount_type' => ['nullable', Rule::in(DiscountType::values())],
            'items.*.discount_value' => 'nullable|numeric|decimal:0,2|min:0|max:99999999',
        ];
    }
}
