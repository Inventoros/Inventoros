<?php

declare(strict_types=1);

namespace App\Http\Requests\StockAdjustment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new stock adjustment (web surface). location_id is an optional
 * addition so an adjustment can target a specific location bin; the rest is
 * unchanged from the previous inline validation in the controller.
 */
final class StoreStockAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:manual,recount,damage,loss,return,correction',
            'adjustment_quantity' => 'required|integer|not_in:0',
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('product_locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ];
    }
}
