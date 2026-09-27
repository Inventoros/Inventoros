<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\StockAudit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a physical count for one stock-audit item via the REST API.
 * Rules match the web updateCount endpoint.
 */
final class RecordStockAuditCountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'counted_quantity' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
