<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the receiving payload for a purchase order via the REST API. Rules
 * unchanged from Api\PurchaseOrderController::receive.
 */
final class ReceivePurchaseOrderRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'exists:purchase_order_items,id'],
            'items.*.quantity_to_receive' => ['required', 'integer', 'min:0'],
        ];
    }
}
