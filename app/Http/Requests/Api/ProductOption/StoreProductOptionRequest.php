<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ProductOption;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new product option via the REST API. Rules unchanged from
 * Api\ProductOptionController::store.
 */
final class StoreProductOptionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'values' => ['required', 'array', 'min:1'],
            'values.*' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
