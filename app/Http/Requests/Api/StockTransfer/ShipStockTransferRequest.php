<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\StockTransfer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates marking a stock transfer as shipped (in transit) via the REST API.
 */
final class ShipStockTransferRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'shipping_method' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'estimated_arrival' => ['nullable', 'date'],
        ];
    }
}
