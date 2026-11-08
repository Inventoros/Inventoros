<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Role;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates known permissions and tenant-owned permission sets for a new role.
 */
final class StoreRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in(Permission::values())],
            'permission_set_ids' => 'nullable|array',
            'permission_set_ids.*' => ['integer', Rule::exists('permission_sets', 'id')->where(fn ($query) => $query
                ->where('organization_id', $this->user()->organization_id)
                ->orWhere(fn ($templates) => $templates->whereNull('organization_id')->where('is_template', true)))],
        ];
    }
}
