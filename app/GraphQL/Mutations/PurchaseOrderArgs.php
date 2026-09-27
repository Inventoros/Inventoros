<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Illuminate\Validation\Rule;

/**
 * Purchase order validation for the GraphQL mutations, mirroring the REST
 * Store/UpdatePurchaseOrderRequest: supplier and products must belong to the
 * caller's organization.
 */
final class PurchaseOrderArgs
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(int $organizationId, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'supplier_id' => [$required, 'integer', Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)],
            'order_date' => [$required, 'date'],
            'expected_date' => ['nullable', 'date'],
            'currency' => [$required, 'string', 'max:3'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => [$required, 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('organization_id', $organizationId)],
        ];
    }
}
