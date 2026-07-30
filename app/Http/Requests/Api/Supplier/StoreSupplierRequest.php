<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new supplier via the REST API. Rules unchanged from
 * Api\SupplierController::store.
 */
final class StoreSupplierRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique('suppliers', 'code')->where('organization_id', $organizationId)],
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
            'metadata' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
