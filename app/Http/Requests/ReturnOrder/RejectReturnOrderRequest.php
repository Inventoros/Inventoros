<?php

declare(strict_types=1);

namespace App\Http\Requests\ReturnOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates rejecting a return order (web surface). Rules unchanged from
 * Order\ReturnOrderController::reject.
 */
final class RejectReturnOrderRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
