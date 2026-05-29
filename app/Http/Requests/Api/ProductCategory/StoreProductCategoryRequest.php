<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ProductCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new product category via the REST API. Rules unchanged from
 * Api\ProductCategoryController::store.
 */
final class StoreProductCategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('organization_id', $organizationId)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
