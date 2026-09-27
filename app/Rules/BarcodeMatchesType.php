<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\BarcodeType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a barcode value against the symbology it will be printed as.
 *
 * With no type (null or unknown) any value passes: it is printed with the
 * detected symbology, falling back to Code 128. An unknown type string is
 * reported by the `barcode_type` enum rule, not here.
 */
final class BarcodeMatchesType implements ValidationRule
{
    public function __construct(private readonly ?string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $this->type === null || $this->type === '') {
            return;
        }

        $type = BarcodeType::tryFrom($this->type);
        if ($type === null) {
            return;
        }

        if (! is_string($value) || ! $type->isValid($value)) {
            $fail("The :attribute is not a valid {$type->label()} code. It must be {$type->requirement()}.");
        }
    }
}
