<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PermissionSet;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Validates a new permission set via the REST API. permissions.* is allowlisted
 * against the Permission enum so a caller can't persist arbitrary/garbage or
 * privileged strings that later merge into effective role permissions; the
 * controller additionally checks a non-admin only includes permissions they
 * themselves hold.
 */
final class StorePermissionSetRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string|In>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:50'],
            'icon' => ['nullable', 'string', 'max:50'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(array_column(Permission::cases(), 'value'))],
        ];
    }
}
