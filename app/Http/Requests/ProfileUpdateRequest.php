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
    /**
     * A new address is stored lowercase. An untouched address (ignoring case)
     * is left exactly as stored, because sign-in matches the stored address.
     */
    protected function prepareForValidation(): void
    {
        if ($this->changesEmail() && is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => $this->changesEmail() ? [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ] : ['required', 'string', 'email', 'max:255'],
            // Changing the sign-in email needs the current password.
            'current_password' => $this->changesEmail() ? ['required', 'string', 'current_password'] : ['exclude'],
        ];
    }

    public function changesEmail(): bool
    {
        $email = $this->input('email');

        return ! is_string($email) || mb_strtolower(trim($email)) !== mb_strtolower((string) $this->user()->email);
    }
}
