<?php

declare(strict_types=1);

namespace App\Http\Requests\ReturnOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new return order (web surface). Rules unchanged from the previous
 * inline validation in Order\ReturnOrderController::store.
 */
final class StoreReturnOrderRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'order_id' => 'required|exists:orders,id',
            'type' => 'required|in:return,exchange',
            'reason' => 'required|string|max:1000',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|exists:order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.condition' => 'required|in:new,used,damaged',
            'items.*.restock' => 'required|boolean',
        ];
    }
}
