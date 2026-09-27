<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\User;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates updating a user via the REST API. Same fields as the web form
 * (password optional); custom roles must be the caller's organization's own
 * or system roles. The escalation guards live in UserManagementService.
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
        $organizationId = $this->user()->organization_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', 'in:admin,manager,member'],
            'role_ids' => ['nullable', 'array', 'max:100'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where(
                fn (Builder $q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id')
            )],
        ];
    }
}
