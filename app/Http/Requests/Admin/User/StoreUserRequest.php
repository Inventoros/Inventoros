<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates creating a user and limits custom roles to the active
 * organization or shared system roles.
 */
final class StoreUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => 'required|in:admin,manager,member',
            'role_ids' => 'nullable|array',
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where(
                fn (Builder $query) => $query->where('organization_id', $this->user()->organization_id)->orWhereNull('organization_id')
            )],
        ];
    }
}
