<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\SavedReport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new saved report via the REST API. Rules unchanged from
 * Api\SavedReportController::store.
 */
final class StoreSavedReportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'data_source' => ['required', 'string', 'max:50'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string', 'max:100'],
            'filters' => ['nullable', 'array'],
            'filters.*.field' => ['required_with:filters', 'string', 'max:100'],
            'filters.*.operator' => ['required_with:filters', 'string', 'max:20'],
            'filters.*.value' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'array'],
            'sort.field' => ['required_with:sort', 'string', 'max:100'],
            'sort.direction' => ['required_with:sort', 'string', 'in:asc,desc'],
            'chart_type' => ['nullable', 'string', 'in:bar,line,pie'],
            'chart_field' => ['nullable', 'string', 'max:100'],
            'is_shared' => ['nullable', 'boolean'],
        ];
    }
}
