<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\StockTransfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new stock transfer via the REST API. Same fields as the web
 * form, but every location and product id must belong to the caller's
 * organization so a foreign id is a 422 rather than a lookup failure.
 */
final class StoreStockTransferRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $ownLocation = Rule::exists('product_locations', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at');

        return [
            'from_location_id' => ['required', 'integer', $ownLocation],
            'to_location_id' => ['required', 'integer', $ownLocation, 'different:from_location_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'shipping_method' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'estimated_arrival' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
