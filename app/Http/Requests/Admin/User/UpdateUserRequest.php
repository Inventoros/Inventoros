<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates updating a user, including tenant-scoped custom roles.
 * The password is optional and email uniqueness ignores the bound user.
 */
final class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => 'required|in:admin,manager,member',
            'role_ids' => 'nullable|array',
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where(
                fn (Builder $query) => $query->where('organization_id', $this->user()->organization_id)->orWhereNull('organization_id')
            )],
        ];
    }
}
