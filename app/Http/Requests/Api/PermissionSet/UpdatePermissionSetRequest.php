<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PermissionSet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a permission set update via the REST API. Rules unchanged from
 * Api\PermissionSetController::update (name/permissions are `sometimes`).
 */
final class UpdatePermissionSetRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:50'],
            'icon' => ['nullable', 'string', 'max:50'],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
