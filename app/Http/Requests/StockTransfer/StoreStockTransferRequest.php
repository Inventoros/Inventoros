<?php

declare(strict_types=1);

namespace App\Http\Requests\StockTransfer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new stock transfer (web surface). Rules unchanged from the
 * previous inline validation in Inventory\StockTransferController::store.
 */
final class StoreStockTransferRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_location_id' => 'required|exists:product_locations,id',
            'to_location_id' => [
                'required',
                'exists:product_locations,id',
                'different:from_location_id',
            ],
            'notes' => 'nullable|string|max:1000',
            'shipping_method' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:255',
            'estimated_arrival' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:500',
        ];
    }
}
