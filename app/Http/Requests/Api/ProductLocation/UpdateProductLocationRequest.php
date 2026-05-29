<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ProductLocation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a product location update via the REST API. Rules unchanged from
 * Api\ProductLocationController::update (name is `sometimes`).
 */
final class UpdateProductLocationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'aisle' => ['nullable', 'string', 'max:255'],
            'shelf' => ['nullable', 'string', 'max:255'],
            'bin' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
