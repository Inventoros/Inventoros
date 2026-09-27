<?php

declare(strict_types=1);

namespace App\Http\Requests\ReturnOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates changing the restock flag and/or condition of return lines
 * before the return is received (web and REST). Whether each line belongs
 * to the return is checked by ReturnOrderService::updateLines().
 */
final class UpdateReturnLinesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:500',
            'items.*.id' => 'required|integer',
            'items.*.restock' => 'nullable|boolean',
            'items.*.condition' => 'nullable|in:new,used,damaged',
        ];
    }
}
