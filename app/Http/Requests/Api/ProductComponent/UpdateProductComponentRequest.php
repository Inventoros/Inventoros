<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ProductComponent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates updating a kit/assembly component via the REST API. Rules
 * unchanged from Api\ProductComponentController::update.
 */
final class UpdateProductComponentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
