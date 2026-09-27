<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Validation\Rule;

/**
 * The `suppliers[]` rules shared by every product write surface (web store and
 * update, REST store and update) so they validate a supplier link identically.
 *
 * Every supplier_id must be a live supplier of the caller's organization; a
 * foreign id fails validation rather than linking another tenant's supplier.
 * At most one entry may be flagged primary (ProductService promotes the first
 * entry when none is).
 */
trait ValidatesProductSuppliers
{
    /**
     * @return array<string, mixed>
     */
    protected function productSupplierRules(int $organizationId): array
    {
        return [
            'suppliers' => [
                'sometimes',
                'array',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $primaries = collect($value)
                        ->filter(fn ($row) => is_array($row) && filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN))
                        ->count();

                    if ($primaries > 1) {
                        $fail('Only one supplier can be the primary supplier.');
                    }
                },
            ],
            'suppliers.*.supplier_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('suppliers', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'suppliers.*.supplier_sku' => ['nullable', 'string', 'max:255'],
            'suppliers.*.cost_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'suppliers.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'suppliers.*.minimum_order_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'suppliers.*.is_primary' => ['nullable', 'boolean'],
        ];
    }
}
