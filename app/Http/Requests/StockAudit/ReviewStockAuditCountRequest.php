<?php

declare(strict_types=1);

namespace App\Http\Requests\StockAudit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewStockAuditCountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approved,recount_requested'],
            'expected_revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'notes' => ['required_if:decision,recount_requested', 'nullable', 'string', 'max:500', Rule::when($this->input('decision') === 'recount_requested', ['min:3'])],
        ];
    }
}
