<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\User;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates creating a user via the REST API. Same fields as the web form;
 * custom roles must be the caller's organization's own or system roles. The
 * escalation guards themselves live in UserManagementService.
 */
final class StoreUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'in:admin,manager,member'],
            'role_ids' => ['nullable', 'array', 'max:100'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where(
                fn (Builder $q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id')
            )],
        ];
    }
}
