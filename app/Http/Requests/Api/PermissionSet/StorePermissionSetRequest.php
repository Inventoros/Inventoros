<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PermissionSet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new permission set via the REST API. Rules unchanged from
 * Api\PermissionSetController::store.
 */
final class StorePermissionSetRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:50'],
            'icon' => ['nullable', 'string', 'max:50'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
        ];
    }
}
