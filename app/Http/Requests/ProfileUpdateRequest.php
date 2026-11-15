<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for validating user profile updates.
 *
 * Ensures name and email are valid and email remains unique.
 */
final class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // Changing the sign-in email needs the current password.
            'current_password' => $this->changesEmail() ? ['required', 'string', 'current_password'] : ['exclude'],
        ];
    }

    private function changesEmail(): bool
    {
        return mb_strtolower((string) $this->input('email')) !== mb_strtolower((string) $this->user()->email);
    }
}
