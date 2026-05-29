<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ProductOption;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a product option update via the REST API. Rules unchanged from
 * Api\ProductOptionController::update (name/values are `sometimes`).
 */
final class UpdateProductOptionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'values' => ['sometimes', 'array', 'min:1'],
            'values.*' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
