<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ReturnOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new return order via the REST API.
 *
 * The order must belong to the caller's organization (a foreign id is a 422,
 * never a lookup). Each line names an order item; the product is derived from
 * that line by ReturnOrderService, so no product_id is accepted.
 */
final class StoreReturnOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
            'type' => ['required', 'in:return,exchange'],
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.order_item_id' => ['required', 'integer', Rule::exists('order_items', 'id')->where('order_id', (int) $this->input('order_id'))],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.condition' => ['required', 'in:new,used,damaged'],
            'items.*.restock' => ['required', 'boolean'],
        ];
    }
}
