<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use GraphQL\Type\Definition\Type;
use Illuminate\Validation\Rules\Unique;

/**
 * Customer fields shared by the create and update mutations, with validation
 * mirroring the web and REST customer FormRequests.
 */
final class CustomerArgs
{
    private const STRINGS_255 = [
        'code', 'company_name', 'contact_name', 'phone', 'billing_city', 'billing_state', 'billing_zip_code',
        'billing_country', 'shipping_city', 'shipping_state', 'shipping_zip_code', 'shipping_country',
        'tax_id', 'payment_terms',
    ];

    private const TEXTS = ['billing_address', 'shipping_address', 'notes'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function args(bool $requireName): array
    {
        $args = [
            'name' => ['type' => $requireName ? Type::nonNull(Type::string()) : Type::string(), 'description' => 'Customer name'],
            'email' => ['type' => Type::string(), 'description' => 'Email address'],
            'credit_limit' => ['type' => Type::float(), 'description' => 'Credit limit'],
            'currency' => ['type' => Type::string(), 'description' => 'Default currency (ISO 4217)'],
            'is_active' => ['type' => Type::boolean(), 'description' => 'Whether the customer is active'],
        ];

        foreach ([...self::STRINGS_255, ...self::TEXTS] as $field) {
            $args[$field] = ['type' => Type::string(), 'description' => ucfirst(str_replace('_', ' ', $field))];
        }

        return $args;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(bool $requireName, Unique $uniqueCode): array
    {
        $rules = [
            'name' => [$requireName ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'is_active' => ['nullable', 'boolean'],
        ];

        foreach (self::STRINGS_255 as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }

        foreach (self::TEXTS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:5000'];
        }

        $rules['code'][] = $uniqueCode;

        return $rules;
    }
}
