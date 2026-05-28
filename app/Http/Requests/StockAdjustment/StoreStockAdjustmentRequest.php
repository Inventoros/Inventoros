<?php

declare(strict_types=1);

namespace App\Http\Requests\StockAdjustment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new stock adjustment (web surface). Rules unchanged from the
 * previous inline validation in Inventory\StockAdjustmentController::store.
 */
final class StoreStockAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:manual,recount,damage,loss,return,correction',
            'adjustment_quantity' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ];
    }
}
