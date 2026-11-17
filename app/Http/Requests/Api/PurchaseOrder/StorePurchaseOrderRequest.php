<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PurchaseOrder;

use App\Http\Requests\Concerns\ValidatesVariantLines;
use App\Services\ExchangeRates\PurchaseOrderExchangeRates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new purchase order via the REST API. Rules unchanged from
 * Api\PurchaseOrderController::store.
 */
final class StorePurchaseOrderRequest extends FormRequest
{
    use ValidatesVariantLines;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency' => ['required', 'string', 'max:3'],
            'shipping' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'tax' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('organization_id', $organizationId)],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:255'],
            // A line's tax rate in percent: the server computes its tax and
            // ignores a sent amount. Without a rate, tax is an amount kept as sent.
            'items.*.tax_rate' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:100'],
            'items.*.tax' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            // Optional: enter a rate (manual), accept a stale one (confirm) or
            // take the provider's again (auto). Needs
            // override_purchase_order_exchange_rate for manual and confirm.
            ...PurchaseOrderExchangeRates::rules(),
        ];
    }

    /**
     * The variant stays optional on the REST API so existing clients that
     * send product-only lines keep working; when sent it must still match.
     */
    protected function requiresVariantForVariantProducts(): bool
    {
        return false;
    }
}
