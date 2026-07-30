<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a supplier edit (web surface). `code` is org-scoped unique
 * (ignoring this supplier) so it can't collide with another supplier's code.
 */
final class UpdateSupplierRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique('suppliers', 'code')->where('organization_id', $organizationId)->ignore($this->route('supplier'))],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:3'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
