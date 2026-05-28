<?php

declare(strict_types=1);

namespace App\Http\Requests\ProductCategory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a product category edit (web surface). Rules unchanged from the
 * previous inline validation in Inventory\ProductCategoryController::update.
 */
final class UpdateProductCategoryRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }
}
