<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Inventory\Product;
use App\Models\User;
use App\Services\WarehouseAccessService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A product's location must be in one of the user's warehouses; moving an
 * existing product also needs access to the location it is leaving. The
 * same rule ProductService enforces, reported as a field error so a form
 * keeps its input. An unchanged location always passes.
 */
final class AccessibleProductLocation implements ValidationRule
{
    public function __construct(private readonly ?User $user, private readonly ?Product $product = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->user === null || $value === null || $value === '') {
            return;
        }

        $access = app(WarehouseAccessService::class);
        $current = $this->product?->location_id === null ? null : (int) $this->product->location_id;

        if ($this->product !== null && $current === (int) $value) {
            return;
        }

        if ($this->product !== null && $current !== null && ! $access->canAccessLocation($this->user, $current)) {
            $fail('This product is kept in a warehouse you do not have access to, so you cannot move it.');

            return;
        }

        if (! $access->canAccessLocation($this->user, (int) $value)) {
            $fail('You do not have access to this location\'s warehouse.');
        }
    }
}
