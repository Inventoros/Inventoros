<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Role;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates updating a role (admin). Rules unchanged from
 * Admin\RoleController::update.
 */
final class UpdateRoleRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'permission_set_ids' => 'nullable|array',
            'permission_set_ids.*' => 'integer|exists:permission_sets,id',
        ];
    }
}
