<?php

declare(strict_types=1);

namespace App\Http\Requests\StockAudit;

use Illuminate\Foundation\Http\FormRequest;

final class ListStockAuditItemsRequest extends FormRequest
{
    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'in:25,50,100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:200'], 'item_status' => ['sometimes', 'in:all,pending,counted,variance,review']];
    }
}
