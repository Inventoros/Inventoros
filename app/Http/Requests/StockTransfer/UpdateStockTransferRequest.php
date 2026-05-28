<?php

declare(strict_types=1);

namespace App\Http\Requests\StockTransfer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a stock transfer edit (web surface). Rules unchanged from the
 * previous inline validation in Inventory\StockTransferController::update.
 */
final class UpdateStockTransferRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'status' => 'sometimes|string|in:in_transit',
            'shipping_method' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:255',
            'estimated_arrival' => 'nullable|date',
        ];
    }
}
