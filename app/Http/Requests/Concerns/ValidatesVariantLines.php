<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\VariantLineValidator;
use Illuminate\Validation\Validator;

/**
 * Adds the variant pairing checks from VariantLineValidator to a form
 * request's `items` array, reported as items.N.product_variant_id errors
 * (instead of letting the service throw a flash error after the fact).
 *
 * Requests serving existing API clients override requiresVariantForVariantProducts()
 * to keep the variant optional there.
 */
trait ValidatesVariantLines
{
    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $items = $this->input('items');
                if (! is_array($items)) {
                    return;
                }

                $errors = VariantLineValidator::errors(
                    $items,
                    (int) $this->user()->organization_id,
                    $this->requiresVariantForVariantProducts(),
                );

                foreach ($errors as $index => $message) {
                    $validator->errors()->add("items.{$index}.product_variant_id", $message);
                }
            },
        ];
    }

    protected function requiresVariantForVariantProducts(): bool
    {
        return true;
    }
}
